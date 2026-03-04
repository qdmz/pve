FROM php:8.2-fpm-alpine

# 安装依赖
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    curl \
    git \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install gd pdo pdo_mysql zip

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件
COPY . /var/www/html

# 设置文件权限
RUN chown -R www-data:www-data /var/www/html

# 暴露端口
EXPOSE 9000