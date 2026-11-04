FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libcurl4-openssl-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_mysql mbstring gd curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
EXPOSE 8080
