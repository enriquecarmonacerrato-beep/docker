<?php
// Mostrar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==================== Configuración MySQL ====================
$host = 'basededatos';
$user = 'usuario';
$password = '1234';
$database = 'docker-php';

// Conexión MySQL
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $password);
$conn->query("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($database);

// Crear tablas si no existen
$conn->query("
    CREATE TABLE IF NOT EXISTS language (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS translation (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phrase VARCHAR(255) NOT NULL,
        translation VARCHAR(255) NOT NULL,
        language_id INT,
        FOREIGN KEY (language_id) REFERENCES language(id) ON DELETE CASCADE,
        UNIQUE KEY unique_translation (phrase, language_id)
    )
");

// Insertar idiomas si no existen
$idiomas = ['Spanish', 'French', 'German'];
foreach ($idiomas as $nombre) {
    $stmt = $conn->prepare("INSERT IGNORE INTO language (name) VALUES (?)");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $stmt->close();
}

// Obtener los IDs reales de los idiomas
$idIdioma = [];
$res = $conn->query("SELECT id, name FROM language");
while ($row = $res->fetch_assoc()) {
    $idIdioma[$row['name']] = $row['id'];
}

// Insertar traducciones de ejemplo usando ON DUPLICATE KEY UPDATE
$traducciones = [
    ['phrase' => 'hello', 'translation' => 'hola', 'language' => 'Spanish'],
    ['phrase' => 'hello', 'translation' => 'bonjour', 'language' => 'French'],
    ['phrase' => 'hello', 'translation' => 'hallo', 'language' => 'German']
];

$stmt = $conn->prepare("
    INSERT INTO translation (phrase, translation, language_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE translation = VALUES(translation)
");

foreach ($traducciones as $t) {
    $stmt->bind_param("ssi", $t['phrase'], $t['translation'], $idIdioma[$t['language']]);
    $stmt->execute();
}
$stmt->close();

// ==================== Conexión Redis ====================
$redis = new Redis();
$redisConnected = false;
try {
    $redisConnected = $redis->connect('cache', 6379);
} catch (Exception $e) {
    $redisConnected = false;
}

$resultado = "";
$usandoCache = false;

// ==================== Traductor ====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["palabra"])) {
    $palabra = $_POST["palabra"];
    $idioma  = $_POST["idioma"];

    $cacheKey = "traduccion:$palabra:$idioma";

    // Comprobar Redis
    if ($redisConnected && $redis->exists($cacheKey)) {
        $resultado = $redis->get($cacheKey);
        $usandoCache = true;
    } else {
        // Consultar MySQL
        $stmt = $conn->prepare("
            SELECT t.translation 
            FROM translation t
            JOIN language l ON t.language_id = l.id
            WHERE t.phrase = ? AND l.name = ?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $palabra, $idioma);
        $stmt->execute();
        $stmt->bind_result($traduccion);

        if ($stmt->fetch()) {
            $resultado = $traduccion;
            // Guardar en Redis 1 hora
            if ($redisConnected) {
                $redis->setex($cacheKey, 3600, $resultado);
            }
        } else {
            $resultado = "No se encontró traducción.";
        }
        $stmt->close();
    }
}

// ==================== Contador de Clicks ====================
// URL de la API Node.js (usa el nombre del servicio Docker)
$apiBase = 'http://node_app:3000/api';
$clickCount = 0;

// Reiniciar contador SOLO al cargar la página (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ch = curl_init("$apiBase/reset");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Obtener contador actual
try {
    $data = file_get_contents("$apiBase/");
    if ($data !== false) {
        $json = json_decode($data, true);
        $clickCount = $json['clickCount'] ?? 0;
    }
} catch (Exception $e) {
    $clickCount = 0;
}

// Manejar click desde PHP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['click'])) {
    $ch = curl_init("$apiBase/click");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $json = json_decode($response, true);
        $clickCount = $json['clickCount'] ?? $clickCount;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Traductor + Contador de Clicks</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; text-align: center; }
        button { padding: 10px 20px; font-size: 1rem; cursor: pointer; }
        input, select { padding: 8px; margin: 5px; width: 200px; }
        h1, h2 { color: #333; }
        .cache { color: green; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>

<h1>Traductor Inglés</h1>

<?php if ($usandoCache): ?>
    <div class="cache">✅ Resultado obtenido de la cache Redis</div>
<?php endif; ?>

<form method="POST">
    <label>Palabra en inglés:</label><br>
    <input type="text" name="palabra" required><br><br>

    <label>Idioma:</label><br>
    <select name="idioma">
        <option value="Spanish">Español</option>
        <option value="French">Francés</option>
        <option value="German">Alemán</option>
    </select><br><br>

    <button type="submit">Traducir</button>
</form>

<?php if ($resultado): ?>
    <h2>Resultado:</h2>
    <p><?= htmlspecialchars($resultado) ?></p>
<?php endif; ?>

<hr>

<h1>Contador de Clicks de la página</h1>
<p>Total de clicks: <strong id="clickCount"><?= $clickCount ?></strong></p>


<p><a href="http://localhost:3000/api/log" target="_blank">Ver logs</a></p>

<script>
// Actualizar contador en tiempo real cada 2 segundos
async function updateCount() {
    try {
        const res = await fetch("http://localhost:3000/api/");
        const data = await res.json();
        document.getElementById("clickCount").textContent = data.clickCount;
    } catch(err) {
        console.error("Error actualizando contador:", err);
    }
}
setInterval(updateCount, 2000);
</script>

<button id="clickBtn">Haz Click</button>

<script>
document.getElementById("clickBtn").addEventListener("click", async () => {
    try {
        const res = await fetch("http://localhost:3000/api/click", {
            method: "POST"
        });

        if (!res.ok) {
            throw new Error("Error en la API");
        }

        const data = await res.json();
        document.getElementById("clickCount").textContent = data.clickCount;
    } catch (err) {
        console.error("Error al incrementar contador:", err);
    }
});
</script>


</body>
</html>
