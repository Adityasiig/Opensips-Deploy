# opensips-deploy-local — PHP/Apache container that serves the deploy UI
# and ships a local bundle snapshot (no SSH to any source server).
#
# Targets (the boxes this tool DEPLOYS TO) are still reached over SSH,
# which is why sshpass and the mysql client are in the image.

FROM php:8.2-apache

# System deps needed by the deploy scripts when they run inside the container.
# sshpass  -- non-interactive ssh for target deploys
# default-mysql-client  -- mysqldump / mysql commands (used for target backups)
# openssh-client  -- ssh/scp for target deploys
# zip + unzip  -- used by some admin flows
# ca-certificates  -- TLS for apt/curl
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        sshpass \
        openssh-client \
        default-mysql-client \
        zip \
        unzip \
        ca-certificates \
        procps \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions the app uses (json is built-in for 8.x).
# Uncomment any you actually need; the current app works with the php:8.2 baseline.
# RUN docker-php-ext-install mysqli pdo_mysql

# Apache: enable mod_rewrite (harmless; useful if anything needs pretty URLs)
RUN a2enmod rewrite \
 && sed -ri 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf

# App and bundle are mounted from the host via docker-compose at runtime.
# We still copy in defaults so the image is self-sufficient if someone runs it
# without volume mounts.
WORKDIR /var/www/html/opensips-deploy

COPY app/           /var/www/html/opensips-deploy/
COPY bundle/        /var/www/html/opensips-deploy/bundle/

# Logs directory (overwritten by a volume in compose so logs survive restarts).
RUN mkdir -p /var/www/html/opensips-deploy/logs \
 && chown -R www-data:www-data /var/www/html/opensips-deploy \
 && chmod +x /var/www/html/opensips-deploy/*.sh

# Apache serves /var/www/html by default. Redirect the root to the app.
RUN printf '<?php header("Location: /opensips-deploy/"); ?>\n' > /var/www/html/index.php

# Keep default Apache port
EXPOSE 80

# Default command is apache-foreground from the base image.
