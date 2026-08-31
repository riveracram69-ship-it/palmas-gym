# ==============================================================================
# DOCKERFILE - PALMA'S ELITE GYM MANAGEMENT SYSTEM (RENDER WEB SERVICE)
# ==============================================================================
# PHP 8.2 + Apache base
FROM php:8.2-apache

# Set noninteractive frontend for apt
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies & libraries needed for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libzip-dev \
    mariadb-client \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure & install required PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        gd \
        xml \
        curl \
        zip \
        opcache

# Enable required Apache modules
RUN a2enmod rewrite headers deflate expires


# Copy production PHP runtime settings
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/99-palmas-production.ini

# Copy Apache VirtualHost configuration
COPY apache-docker.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html/gym

# Copy application source code
COPY . /var/www/html/gym

# Set proper ownership & permissions for writable directories
RUN chown -R www-data:www-data /var/www/html/gym \
    && chmod -R 755 /var/www/html/gym \
    && mkdir -p /var/www/html/gym/uploads/members \
    && mkdir -p /var/www/html/gym/backups \
    && chmod 775 /var/www/html/gym/uploads/members \
    && chmod 775 /var/www/html/gym/backups

# Copy and setup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose HTTP port
EXPOSE 80 10000

# Run entrypoint script
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
