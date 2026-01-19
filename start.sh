set -e

echo "Iniciando..."

until docker info > /dev/null 2>&1; do
  echo "Esperando..."
  sleep 3
done

cd /home/ovimatica/DOCKER-PHP

docker-compose up 

echo "Docker Compose levantado"
