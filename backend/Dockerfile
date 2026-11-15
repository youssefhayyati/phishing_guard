# ---- Laravel API (PHP 8.3) ----
FROM php:8.3-cli

# System libs + PHP extensions Laravel needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring zip bcmath \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching)
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# App source
COPY backend/ ./
RUN composer dump-autoload --optimize

# Entrypoint prepares .env, key, db + seed, then serves
COPY backend/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p database \
    && chmod -R 777 storage bootstrap/cache database

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
