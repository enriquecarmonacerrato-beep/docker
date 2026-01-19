const express = require("express");
const mysql = require("mysql2/promise");
const redis = require("redis");
const cors = require("cors");
const path = require("path");

const app = express();
const PORT = 3000;

// Configuración MySQL
const dbConfig = {
  host: process.env.MYSQL_HOST || "basededatos",  // Debe coincidir con el nombre del servicio en docker-compose
  user: process.env.MYSQL_USER || "usuario",
  password: process.env.MYSQL_PASSWORD || "1234",
  database: process.env.MYSQL_DATABASE || "docker-php",
  port: 3306,
};

// Configuración Redis (opcional)
const redisClient = redis.createClient({
  url: process.env.REDIS_URL || "redis://cache:6379",
});

// Middleware
app.use(express.json());
app.use(cors({
  origin: true,
  methods: ["GET", "POST"]
}));
app.use(express.static(path.join(__dirname, "public")));

// Función para esperar a MySQL
async function waitForMySQL() {
  while (true) {
    try {
      const conn = await mysql.createConnection(dbConfig);
      await conn.end();
      console.log("✅ MySQL listo");
      break;
    } catch {
      console.log("⏳ Esperando MySQL...");
      await new Promise(r => setTimeout(r, 2000));
    }
  }
}

async function main() {
  await waitForMySQL();

  await redisClient.connect();
  console.log("✅ Redis listo");

  const connection = await mysql.createConnection(dbConfig);
  console.log("✅ Conectado a MySQL");

  // Crear tabla logs si no existe
  await connection.query(`
    CREATE TABLE IF NOT EXISTS logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      clicks INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  `);

  // Inicializar contador desde Redis
  let clickCount = 0;
  const redisCount = await redisClient.get("clickCount");
  clickCount = redisCount ? parseInt(redisCount) : 0;

  // ================= RUTAS =================

  // Obtener contador
  app.get("/api/", (req, res) => {
    res.json({ clickCount });
  });

  // Incrementar contador
  app.post("/api/click", async (req, res) => {
    try {
      clickCount++;
      await redisClient.set("clickCount", clickCount);
      await connection.query("INSERT INTO logs (clicks) VALUES (?)", [clickCount]);
      res.json({ clickCount });
    } catch (err) {
      console.error(err);
      res.status(500).json({ error: "Error interno" });
    }
  });

  app.post("/api/reset", async (req, res) => {
  try {
    clickCount = 0;
    await redisClient.set("clickCount", 0);
    res.json({ clickCount });
  } catch (err) {
    res.status(500).json({ error: "Error al reiniciar contador" });
  }
});

  // Obtener logs con paginación
  app.get("/api/log", async (req, res) => {
    const page = parseInt(req.query.page) || 1;
    const limit = 10;
    const offset = (page - 1) * limit;

    const [rows] = await connection.query(
      "SELECT * FROM logs ORDER BY created_at DESC LIMIT ? OFFSET ?",
      [limit, offset]
    );
    const [rowsCount] = await connection.query("SELECT COUNT(*) as total FROM logs");
    const total = rowsCount[0].total;
    const totalPages = Math.ceil(total / limit);
    res.json({ logs: rows, page, totalPages });
  });

  // ================= FIN RUTAS =================

  app.listen(PORT, () => {
    console.log(`🚀 Node escuchando en http://localhost:${PORT}`);
  });
}

main();
