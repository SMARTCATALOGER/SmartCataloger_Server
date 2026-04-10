FROM php:8.2-cli

# تنصيب المكتبات الأساسية لـ YAZ
RUN apt-get update && apt-get install -y \
    yaz \
    libyaz-dev \
    libcurl4-openssl-dev \
    && pecl install yaz \
    && docker-php-ext-enable yaz

# تنصيب إضافات PHP الضرورية
RUN docker-php-ext-install curl

# نسخ الملفات
COPY . /var/www/html
WORKDIR /var/www/html

# تشغيل سيرفر PHP المدمج على منفذ 80
EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "index.php"]
