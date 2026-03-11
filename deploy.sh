#!/bin/bash

set -euo pipefail

# Use the script location as the project root so the same script works across domains.
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_REMOTE="${DEPLOY_REMOTE:-origin}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"

cd "$PROJECT_ROOT"

# Obtener los ultimos cambios del remoto configurado.
git pull --ff-only "$DEPLOY_REMOTE" "$DEPLOY_BRANCH"

# Instalar dependencias de Composer.
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Instalar dependencias de NPM.
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi

npm run build

# Limpiar cache de Laravel.
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Optimizar Laravel.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Establecer permisos.
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Si es necesario, establecer el propietario correcto.
# chown -R usuario:grupo "$PROJECT_ROOT"

echo "Despliegue completado correctamente en $PROJECT_ROOT"
