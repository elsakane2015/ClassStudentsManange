#!/bin/bash
set -e

echo "Running Laravel initialization..."

cd /var/www/html

# 创建存储符号链接（不需要 .env 文件）
if [ ! -L public/storage ]; then
    php artisan storage:link || true
    echo "Storage link created!"
fi

# 只有当 .env 文件存在时才运行缓存命令
if [ -f .env ]; then
    php artisan package:discover --ansi || true
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
    echo "Laravel cache commands completed!"
else
    echo "Note: .env file not found, skipping cache commands (this is OK if using environment variables)"
fi

echo "Laravel initialization completed!"
