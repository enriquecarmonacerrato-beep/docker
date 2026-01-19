const countSpan = document.getElementById("clickCount");
const btn = document.getElementById("clickBtn");

async function updateCount() {
  const res = await fetch("/api/");
  const data = await res.json();
  countSpan.textContent = data.clickCount;
}

btn.addEventListener("click", async () => {
  const res = await fetch("/api/click", { method: "POST" });
  const data = await res.json();
  countSpan.textContent = data.clickCount;
});

updateCount(); // cargar contador al abrir página

