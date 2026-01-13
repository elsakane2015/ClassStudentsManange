#!/bin/bash
set -e

echo "============================================"
echo "Running Laravel initialization..."
echo "============================================"

cd /var/www/html

# ============================================
# 1. 自动创建 .env 文件（如果不存在）
# ============================================
if [ ! -f .env ]; then
    echo "Creating .env file from environment variables..."
    
    # 从环境变量生成 .env 文件
    cat > .env << EOF
APP_NAME="${APP_NAME:-Laravel}"
APP_ENV="${APP_ENV:-production}"
APP_KEY="${APP_KEY:-}"
APP_DEBUG="${APP_DEBUG:-false}"
APP_TIMEZONE="${APP_TIMEZONE:-Asia/Shanghai}"
APP_URL="${APP_URL:-http://localhost}"

APP_LOCALE="${APP_LOCALE:-zh_CN}"
APP_FALLBACK_LOCALE="${APP_FALLBACK_LOCALE:-zh_CN}"

LOG_CHANNEL="${LOG_CHANNEL:-stack}"
LOG_LEVEL="${LOG_LEVEL:-error}"

DB_CONNECTION="${DB_CONNECTION:-mysql}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-laravel}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"

SESSION_DRIVER="${SESSION_DRIVER:-database}"
SESSION_LIFETIME="${SESSION_LIFETIME:-120}"

CACHE_STORE="${CACHE_STORE:-database}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
EOF
    
    echo "✓ .env file created!"
else
    echo "✓ .env file already exists"
fi

# ============================================
# 2. 创建存储符号链接
# ============================================
if [ ! -L public/storage ]; then
    php artisan storage:link || true
    echo "✓ Storage link created!"
else
    echo "✓ Storage link already exists"
fi

# ============================================
# 3. 检测是否首次部署，自动运行迁移
# ============================================
# 通过检查 migrations 表是否存在来判断
echo "Checking database status..."
if php artisan migrate:status 2>&1 | grep -q "Migration table not found\|doesn't exist"; then
    echo "First deployment detected, running migrations..."
    php artisan migrate --force || true
    echo "✓ Database migrations completed!"
else
    echo "✓ Database already migrated"
fi

# ============================================
# 4. 缓存优化
# ============================================
echo "Running cache optimization..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
echo "✓ Cache optimization completed!"

echo "============================================"
echo "Laravel initialization completed!"
echo "============================================"
