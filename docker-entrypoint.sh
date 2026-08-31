#!/bin/bash
set -e

# Support Render dynamic PORT environment variable (defaults to 80 if unset)
RENDER_PORT="${PORT:-80}"

echo "Starting Apache on port ${RENDER_PORT}..."
sed -i "s/Listen 80/Listen ${RENDER_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${RENDER_PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Run automatic self-healing database migrations on container startup
echo "Running database schema migrations..."
php /var/www/html/gym/migrate_system_v2.php || true
php /var/www/html/gym/migrate_google_auth.php || true
php /var/www/html/gym/migrate_database_indexes.php || true

# Execute Apache in foreground
exec apache2-foreground

