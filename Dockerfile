FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    curl unzip git nodejs npm libpng-dev libjpeg-dev libfreetype6-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli gd

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

COPY ca.pem /etc/ssl/certs/aiven-ca.pem

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8080}