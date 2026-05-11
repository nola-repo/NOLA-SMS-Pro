# PHP 8.2 with Apache + gRPC pre-installed (avoids 15+ min pecl compile in Cloud Build)
FROM clegginabox/php-grpc:8.2-apache

# Install system deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install extensions for Composer and Firestore (bcmath is required by brick/math)
RUN docker-php-ext-install zip bcmath fileinfo

# Enable mod_rewrite and mod_headers for .htaccess and CORS
RUN a2enmod rewrite headers

# Apache: listen on 8080 (Cloud Run default PORT)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf /etc/apache2/sites-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App lives here
WORKDIR /var/www/html

# Copy app (vendor excluded; we install in container)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

# Install Laravel dependencies for /api/v2 routes in same container.
RUN cd /var/www/html/laravel \
    && composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

# Generate Laravel .env (gitignored — must be created at build time)
RUN echo "APP_NAME=NolaSMSPro" > /var/www/html/laravel/.env \
    && echo "APP_ENV=production" >> /var/www/html/laravel/.env \
    && echo "APP_KEY=base64:TVYWBp+wDYGFX3vr+2pa5R2dNbk5+F8895AAL2OHvSI=" >> /var/www/html/laravel/.env \
    && echo "APP_DEBUG=false" >> /var/www/html/laravel/.env \
    && echo "APP_URL=https://sms-api-116662437564.asia-southeast1.run.app" >> /var/www/html/laravel/.env \
    && echo "LOG_CHANNEL=stderr" >> /var/www/html/laravel/.env \
    && echo "LOG_LEVEL=error" >> /var/www/html/laravel/.env \
    && echo "SESSION_DRIVER=file" >> /var/www/html/laravel/.env \
    && echo "CACHE_STORE=file" >> /var/www/html/laravel/.env \
    && echo "QUEUE_CONNECTION=sync" >> /var/www/html/laravel/.env

# Cloud Run sets PORT; Apache already configured for 8080
ENV APACHE_HTTP_PORT=8080
EXPOSE 8080

# Apache runs in foreground
CMD ["apache2-foreground"]
