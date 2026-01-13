#!/bin/bash
set -e

# Laravel 初始化命令
echo "Running Laravel initialization..."

# 只有当 .env 文件存在时才运行这些命令
if [ -f /var/www/html/.env ]; then
    cd /var/www/html
    
    # 创建存储符号链接（如果不存在）
    if [ ! -L public/storage ]; then
        php artisan storage:link || true
        echo "Storage link created!"
    fi
    
    # 发现包
    php artisan package:discover --ansi || true
    
    # 缓存配置 (生产环境推荐)
    php artisan config:cache || true
    
    # 缓存路由 (生产环境推荐)
    php artisan route:cache || true
    
    # 缓存视图
    php artisan view:cache || true
    
    # 运行数据库迁移 (如果需要的话)
    # php artisan migrate --force || true
    
    echo "Laravel initialization completed!"
else
    echo "Warning: .env file not found, skipping Laravel initialization"
fi

