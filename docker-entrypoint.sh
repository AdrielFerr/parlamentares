#!/bin/sh
set -e

echo "[entrypoint] Iniciando KeekConecta..."

# Seed de uploads: copia fotos da imagem para o volume sem sobrescrever existentes
if [ -d /var/www/html/public/uploads_seed ]; then
    cp -rn /var/www/html/public/uploads_seed/. /var/www/html/public/uploads/ 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/public/uploads/ 2>/dev/null || true
    echo "[entrypoint] Uploads seed aplicado."
fi

# Executa migrations (migrate.php já aguarda o MySQL ficar pronto)
php /var/www/html/database/migrate.php

echo "[entrypoint] Iniciando Apache..."
exec "$@"
