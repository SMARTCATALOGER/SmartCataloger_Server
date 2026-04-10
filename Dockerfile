# 1. استخدام نسخة رسمية من PHP مع سيرفر Apache
FROM php:8.2-apache

# 2. تحديث النظام وتنزيل مكتبة YAZ الأساسية لبروتوكول Z39.50
RUN apt-get update && apt-get install -y \
    yaz \
    libyaz-dev \
    libcurl4-openssl-dev \
    && pecl install yaz \
    && docker-php-ext-enable yaz

# 3. تفعيل بعض الإضافات المهمة للـ API والاتصالات
RUN docker-php-ext-install pdo pdo_mysql curl

# 4. تفعيل وضع إعادة كتابة الروابط في السيرفر
RUN a2enmod rewrite

# 5. نسخ ملفات مشروعنا (api.php) إلى المجلد الرئيسي للسيرفر
COPY . /var/www/html/

# 6. إعطاء الصلاحيات الصحيحة للملفات حتى لا تحدث أخطاء
RUN chown -R www-data:www-data /var/www/html/

# 7. فتح المنفذ 80 للإنترنت الخارجي
EXPOSE 80
