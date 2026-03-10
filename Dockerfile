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
RUN docker-php-ext-install zip bcmath

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

# Cloud Run sets PORT; Apache already configured for 8080
ENV APACHE_HTTP_PORT=8080
EXPOSE 8080

# Apache runs in foreground
CMD ["apache2-foreground"]
