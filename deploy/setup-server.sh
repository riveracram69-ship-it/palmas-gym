#!/usr/bin/env bash
# ==============================================================================
# PALMA'S ELITE GYM MANAGEMENT SYSTEM - 1-CLICK UBUNTU SERVER PROVISIONER
# ==============================================================================
# OS: Ubuntu 22.04 LTS / Ubuntu 24.04 LTS
# Target: DigitalOcean, AWS Lightsail, Linode, Vultr, Hetzner
# ==============================================================================

set -euo pipefail

echo "====================================================================="
echo "  PALMA'S ELITE GYM - AUTOMATED PRODUCTION SERVER SETUP"
echo "====================================================================="
echo ""

# 1. Update OS packages
echo "📦 [1/8] Updating system packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y && apt-get upgrade -y

# 2. Install Nginx, PHP 8.2/8.3, MariaDB, Certbot, and utilities
echo "⚙️ [2/8] Installing Nginx, PHP, MariaDB, Certbot, and required extensions..."
apt-get install -y software-properties-common curl git unzip ufw
add-apt-repository -y ppa:ondrej/php
apt-get update -y

apt-get install -y \
    nginx \
    mariadb-server mariadb-client \
    php8.2-fpm php8.2-cli php8.2-mysql php8.2-gd php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-intl php8.2-bcmath \
    certbot python3-certbot-nginx

# 3. Secure MariaDB Server
echo "🔒 [3/8] Starting & configuring MariaDB..."
systemctl enable mariadb
systemctl start mariadb

# 4. Create Application Web Directory
echo "📁 [4/8] Creating web directory /var/www/palmas-gym..."
mkdir -p /var/www/palmas-gym/gym
mkdir -p /var/backups/palmas-gym
chmod 700 /var/backups/palmas-gym

# 5. Configure Firewall (UFW)
echo "🛡️ [5/8] Configuring UFW Firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# 6. Apply PHP Production Settings
echo "⚡ [6/8] Applying PHP production configuration..."
PHP_INI="/etc/php/8.2/fpm/php.ini"
if [ -f "$PHP_INI" ]; then
    sed -i 's/^display_errors = .*/display_errors = Off/' "$PHP_INI"
    sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 10M/' "$PHP_INI"
    sed -i 's/^post_max_size = .*/post_max_size = 10M/' "$PHP_INI"
    sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
    sed -i 's/^;session.cookie_secure =.*/session.cookie_secure = 1/' "$PHP_INI"
    sed -i 's/^;session.cookie_httponly =.*/session.cookie_httponly = 1/' "$PHP_INI"
    sed -i 's/^;session.cookie_samesite =.*/session.cookie_samesite = "Lax"/' "$PHP_INI"
fi
systemctl restart php8.2-fpm

echo ""
echo "====================================================================="
echo "  🎉 BASE SERVER ENVIRONMENT INSTALLED SUCCESSFULLY!"
echo "====================================================================="
echo ""
echo "NEXT STEPS TO COMPLETE DEPLOYMENT:"
echo "1. Upload your 'gym/' folder to: /var/www/palmas-gym/gym"
echo "2. Copy /var/www/palmas-gym/gym/.env.example to /var/www/palmas-gym/.env and configure secrets"
echo "3. Copy /var/www/palmas-gym/gym/deploy/nginx/palmas-gym.conf to /etc/nginx/sites-available/palmas-gym.conf"
echo "4. Link it: ln -s /etc/nginx/sites-available/palmas-gym.conf /etc/nginx/sites-enabled/"
echo "5. Issue SSL: certbot --nginx -d YOUR-DOMAIN.com"
echo "6. Import Database:"
echo "   mysql -u root -e 'CREATE DATABASE gym_management;'"
echo "   mysql -u root gym_management < /var/www/palmas-gym/gym/gym_management.sql"
echo "====================================================================="
