FROM serversideup/php:8.4-fpm-nginx

# 切换到 root 以进行安装 (必须在 apt-get 之前)
USER root

# 安装 Node.js、NPM 和 GD 扩展依赖
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    nodejs \
    npm \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 复制项目文件并设置权限 (serversideup/php v3 使用 www-data 用户)
COPY --chown=www-data:www-data . /var/www/html

# 切换回 www-data 运行应用
USER www-data

# 安装 PHP 依赖
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# 安装前端依赖并构建
RUN npm install && npm run build

# 暴露端口 (serversideup/php 使用 8080，因为非 root 用户无法绑定 80)
EXPOSE 8080
