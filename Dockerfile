FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    sshpass \
    openssh-client \
    cron \
    mariadb-client \
    postgresql-client \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Install PHP dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN git config --global --add safe.directory /var/www/html \
    && if [ -d vendor ]; then \
         echo "vendor/ already present, skipping composer install"; \
       else \
         composer install --no-dev --optimize-autoloader --no-interaction; \
       fi

# Configure Apache
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/public

# Setup cron jobs
RUN touch /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log \
    && chown www-data:www-data /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log \
    && chmod 666 /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log \
    && echo "0 * * * * www-data cd /var/www/html && /usr/local/bin/php bin/check_expired_clients.php >> /var/log/cron.log 2>&1" > /etc/cron.d/amnezia-cron \
    && echo "0 * * * * www-data cd /var/www/html && /usr/local/bin/php bin/check_traffic_limits.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/amnezia-cron \
    && echo "0 * * * * www-data cd /var/www/html && /usr/local/bin/php bin/sync_external_clients.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/amnezia-cron \
    && echo "30 * * * * www-data cd /var/www/html && /usr/local/bin/php bin/backup.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/amnezia-cron \
    && echo "*/3 * * * * root /bin/bash /var/www/html/bin/monitor_metrics.sh >> /var/log/metrics_monitor.log 2>&1" >> /etc/cron.d/amnezia-cron \
    && chmod 0644 /etc/cron.d/amnezia-cron

# Make monitor script executable
RUN chmod +x /var/www/html/bin/monitor_metrics.sh

RUN echo '#!/bin/bash\n\
# Ensure backups directories exist and have correct permissions\n\
mkdir -p /var/www/html/backups/panel /var/www/html/backups/servers\n\
chown -R www-data:www-data /var/www/html/backups\n\
chmod -R 777 /var/www/html/backups\n\
# Ensure log files exist and are writable by www-data\n\
touch /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log\n\
chown www-data:www-data /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log\n\
chmod 666 /var/log/cron.log /var/log/metrics_monitor.log /var/log/metrics_collector.log\n\
# Ensure .env is writable by www-data\n\
touch /var/www/html/.env\n\
chown www-data:www-data /var/www/html/.env\n\
chmod 666 /var/www/html/.env\n\
service cron start\n\
# Start metrics collector on container startup\n\
/bin/bash /var/www/html/bin/monitor_metrics.sh\n\
apache2-foreground' > /start.sh \
    && chmod +x /start.sh

# Expose port 80
EXPOSE 80

CMD ["/start.sh"]
