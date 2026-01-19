#!/bin/bash
set -e

echo "🚀 Iniciando..."

# Esperar a que Docker esté disponible
until docker info > /dev/null 2>&1; do
  echo "⏳ Esperando..."
  sleep 3
done

# Moverse al directorio del proyecto (ruta absoluta)
cd /home/ovimatica/DOCKER-PHP
# Levantar servicios
docker-compose up 

echo "✅ Docker Compose levantado"
