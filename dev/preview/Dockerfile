FROM php:8.5.12-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libonig-dev libcurl4-openssl-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j2 pdo_mysql mbstring gd curl \
 && rm -rf /var/lib/apt/lists/*
# Verify the same photo decoding and WebP optimization capabilities as runtime.
RUN php -r 'foreach (["imagewebp","imagecreatefromwebp","imagecreatefromjpeg","imagecreatefrompng"] as $fn) { if (!function_exists($fn)) { fwrite(STDERR, "Missing image function: $fn\n"); exit(1); } }'
# Local test-only limits: four artist uploads can be submitted with one form.
RUN printf '%s\n' 'upload_max_filesize=16M' 'post_max_size=128M' 'memory_limit=512M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/zz-preview-uploads.ini
WORKDIR /app
EXPOSE 8080
