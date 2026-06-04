FROM dunglas/frankenphp:latest-php8.3-alpine

# System libraries required by PHP extensions
RUN apk add --no-cache \
    libpq-dev \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev

# PHP extensions
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pgsql pdo_pgsql zip gd bcmath

WORKDIR /var/www/ptt-web

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install custom Caddyfile before copying app code
COPY Caddyfile /etc/caddy/Caddyfile

# Copy composer files first (better layer caching — only re-runs if these change)
COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=512M composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy the rest of the application code
COPY . .

# Run post-install scripts now that full app is present
RUN composer run-script post-autoload-dump 2>/dev/null || true

# Create storage symlink (public/storage -> storage/app/public)
# public/storage is gitignored so it must be created here
RUN ln -sf /var/www/ptt-web/storage/app/public /var/www/ptt-web/public/storage

# Laravel needs write access to these directories
RUN chmod -R 775 storage bootstrap/cache

USER root