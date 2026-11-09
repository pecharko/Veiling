FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libcurl4-openssl-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_mysql mbstring gd curl \
 && rm -rf /var/lib/apt/lists/*
# Local-only photo upload limits. Keep this configuration out of production.
RUN printf '%s\\n' \\
    'upload_max_filesize=16M' \\
    'post_max_size=128M' \\
    'memory_limit=512M' \\
    'max_file_uploads=20' \\
    > /usr/local/etc/php/conf.d/zz-preview-uploads.ini
WORKDIR /app
EXPOSE 8080
