FROM serversideup/php:8.4-fpm-nginx

# 安装 Node.js 和 NPM (用于前端构建)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    nodejs \
    npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 切换到 root 以进行安装
USER root

# 复制项目文件
COPY . /var/www/html

# 设置权限
RUN chown -R webuser:webuser /var/www/html

# 切换回 webuser 运行应用
USER webuser

# 安装 PHP 依赖
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# 安装前端依赖并构建
RUN npm install && npm run build

# 暴露端口 (Coolify 默认需要 80)
EXPOSE 80
