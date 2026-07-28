# PHP 8.2 with Apache + gRPC pre-installed (avoids 15+ min pecl compile in Cloud Build)
FROM clegginabox/php-grpc:8.2-apache

# Install system deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install extensions for Redis, Composer, and Firestore (bcmath is required by brick/math)
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install zip bcmath fileinfo

# Enable mod_rewrite and mod_headers for .htaccess and CORS
RUN a2enmod rewrite headers

# Terminal-only HTTP request logging. This observes Apache requests without
# touching PHP/Laravel request handling, headers, bodies, or responses.
COPY docker/apache-request-logging.conf /etc/apache2/conf-available/nola-request-logging.conf
RUN a2enconf nola-request-logging

# Apache: listen on 8080 (Cloud Run default PORT)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's#CustomLog ${APACHE_LOG_DIR}/access.log combined#CustomLog /proc/self/fd/1 nola_request#' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf /etc/apache2/sites-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Keep GitHub/codeload dependency downloads conservative. Cloud Build can
# occasionally receive transient HTTP/2 400 responses when Composer opens many
# parallel dist downloads; retries below retain Composer's cache and finally
# fall back to source installs through git.
ENV COMPOSER_MAX_PARALLEL_HTTP=4 \
    COMPOSER_PROCESS_TIMEOUT=1200
RUN git config --global http.version HTTP/1.1

# App lives here
WORKDIR /var/www/html

# Copy app (vendor excluded; we install in container)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
    || (sleep 5 && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress) \
    || (sleep 15 && composer install --no-dev --prefer-source --optimize-autoloader --no-interaction --no-progress)

COPY . .

# Install Laravel dependencies for /api/v2 routes in same container.
RUN cd /var/www/html/laravel \
    && (composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
        || (sleep 5 && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress) \
        || (sleep 15 && composer install --no-dev --prefer-source --optimize-autoloader --no-interaction --no-progress)) \
    && chown -R www-data:www-data storage bootstrap/cache

# Generate Laravel .env (gitignored — must be created at build time)
RUN echo "APP_NAME=NolaSMSPro" > /var/www/html/laravel/.env \
    && echo "APP_ENV=production" >> /var/www/html/laravel/.env \
    && echo "APP_KEY=placeholder" >> /var/www/html/laravel/.env \
    && echo "APP_DEBUG=false" >> /var/www/html/laravel/.env \
    && echo "LOG_CHANNEL=stderr" >> /var/www/html/laravel/.env \
    && echo "LOG_LEVEL=error" >> /var/www/html/laravel/.env \
    && echo "SESSION_DRIVER=file" >> /var/www/html/laravel/.env \
    && echo "CACHE_STORE=file" >> /var/www/html/laravel/.env \
    && echo "QUEUE_CONNECTION=sync" >> /var/www/html/laravel/.env

# Entrypoint: rewrites laravel/.env at startup with real secrets from Cloud Run env vars
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Cloud Run sets PORT; Apache already configured for 8080
ENV APACHE_HTTP_PORT=8080
EXPOSE 8080

# Use entrypoint to inject secrets then start Apache
CMD ["/usr/local/bin/docker-entrypoint.sh"]
