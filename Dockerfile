FROM php:8.2-apache

# Install MongoDB PHP extension (with TLS/SSL for MongoDB Atlas) and Composer deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    libssl-dev \
    pkg-config \
    unzip \
    git \
    ca-certificates \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Set Apache DocumentRoot to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .

# Install Composer autoloader after copying src/ and config/ for classmap generation
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p /var/www/html/storage/rate_limit \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 10000

# Bind Apache to Render's injected PORT (defaults to 10000) idempotently
CMD ["sh", "-c", "PORT=${PORT:-10000}; sed -ri \"s/Listen [0-9]+/Listen ${PORT}/g\" /etc/apache2/ports.conf && sed -ri \"s/:[0-9]+>/:${PORT}>/g\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
