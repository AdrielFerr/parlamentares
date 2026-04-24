#!/bin/sh
set -e

echo "[entrypoint] Iniciando KeekConecta..."

# Executa migrations (migrate.php já aguarda o MySQL ficar pronto)
php /var/www/html/database/migrate.php

echo "[entrypoint] Iniciando Apache..."
exec "$@"
