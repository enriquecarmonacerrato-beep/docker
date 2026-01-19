module.exports = {
  apps: [
    {
      name: "node-app",
      script: "index.js",       // o src/index.js según tu estructura
      instances: "max",         // cluster mode, tantas instancias como cores
      exec_mode: "cluster",     // modo cluster
      env: {
        NODE_ENV: "production",
        PORT: 3000
      }
    }
  ]
};