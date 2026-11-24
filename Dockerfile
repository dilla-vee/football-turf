# Production Dockerfile for Render / Cloud Deployment
FROM php:8.2-apache

# Install PostgreSQL client libraries and PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite & mod_env
RUN a2enmod rewrite env

# Configure Apache to pass all environment variables from Render container to PHP scripts
RUN echo "PassEnv DATABASE_URL APP_BASE_URL PORT DB_HOST DB_PORT DB_USER DB_PASS DB_NAME DB_DRIVER DB_SSLMODE RENDER RENDER_SERVICE_ID" > /etc/apache2/conf-available/docker-env.conf \
    && a2enconf docker-env

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Render sets PORT environment variable (default 80 or 10000)
ENV PORT=80
EXPOSE 80

# Configure Apache port based on $PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

CMD ["apache2-foreground"]
