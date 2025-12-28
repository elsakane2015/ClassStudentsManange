#!/bin/bash

# ============================================
# 智慧校园 - 发布包构建脚本
# ============================================
# 用法: ./build-release.sh [版本号]
# 例如: ./build-release.sh 1.0.0
# ============================================

set -e

VERSION=${1:-$(date +%Y%m%d)}
RELEASE_NAME="smartcampus-${VERSION}"
RELEASE_DIR="releases"
TEMP_DIR="/tmp/${RELEASE_NAME}"

echo "============================================"
echo "  智慧校园 发布包构建工具"
echo "  版本: ${VERSION}"
echo "============================================"
echo ""

# 检查必要工具
command -v npm >/dev/null 2>&1 || { echo "❌ 需要安装 npm"; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "❌ 需要安装 composer"; exit 1; }
command -v zip >/dev/null 2>&1 || { echo "❌ 需要安装 zip"; exit 1; }

# 步骤1: 安装前端依赖并编译
echo "📦 步骤 1/5: 编译前端资源..."
npm install --silent
npm run build

# 步骤2: 安装后端依赖（生产模式）
echo "📦 步骤 2/5: 安装后端依赖..."
composer install --no-dev --optimize-autoloader --quiet

# 步骤3: 准备临时目录
echo "📦 步骤 3/5: 准备发布文件..."
rm -rf "${TEMP_DIR}"
mkdir -p "${TEMP_DIR}"

# 复制必要文件（排除开发文件）
rsync -a --quiet \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='node_modules' \
    --exclude='.env' \
    --exclude='.env.local' \
    --exclude='releases' \
    --exclude='storage/logs/*' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='storage/app/public/*' \
    --exclude='storage/installed' \
    --exclude='tests' \
    --exclude='phpunit.xml' \
    --exclude='.phpunit*' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='docs/debug_logs' \
    --exclude='build-release.sh' \
    ./ "${TEMP_DIR}/"

# 确保必要的空目录存在
mkdir -p "${TEMP_DIR}/storage/app/public"
mkdir -p "${TEMP_DIR}/storage/framework/cache/data"
mkdir -p "${TEMP_DIR}/storage/framework/sessions"
mkdir -p "${TEMP_DIR}/storage/framework/views"
mkdir -p "${TEMP_DIR}/storage/logs"
mkdir -p "${TEMP_DIR}/bootstrap/cache"

# 创建 .gitkeep 文件
touch "${TEMP_DIR}/storage/app/public/.gitkeep"
touch "${TEMP_DIR}/storage/framework/cache/data/.gitkeep"
touch "${TEMP_DIR}/storage/framework/sessions/.gitkeep"
touch "${TEMP_DIR}/storage/framework/views/.gitkeep"
touch "${TEMP_DIR}/storage/logs/.gitkeep"
touch "${TEMP_DIR}/bootstrap/cache/.gitkeep"

# 复制 .env.example 作为初始配置模板
cp .env.example "${TEMP_DIR}/.env.example"

# 步骤4: 创建发布目录并打包
echo "📦 步骤 4/5: 创建发布包..."
mkdir -p "${RELEASE_DIR}"
cd /tmp
zip -rq "${RELEASE_NAME}.zip" "${RELEASE_NAME}"
mv "${RELEASE_NAME}.zip" "${OLDPWD}/${RELEASE_DIR}/"
cd "${OLDPWD}"

# 清理临时目录
rm -rf "${TEMP_DIR}"

# 步骤5: 恢复开发依赖
echo "📦 步骤 5/5: 恢复开发环境..."
composer install --quiet

# 计算文件大小
SIZE=$(du -h "${RELEASE_DIR}/${RELEASE_NAME}.zip" | cut -f1)

echo ""
echo "============================================"
echo "✅ 发布包创建成功！"
echo "============================================"
echo "📁 文件: ${RELEASE_DIR}/${RELEASE_NAME}.zip"
echo "📊 大小: ${SIZE}"
echo ""
echo "部署说明:"
echo "1. 上传 ${RELEASE_NAME}.zip 到服务器"
echo "2. 解压到网站根目录"
echo "3. 设置目录权限: chmod -R 755 storage bootstrap/cache"
echo "4. 将网站运行目录指向 /public"
echo "5. 访问网站，按照安装向导完成配置"
echo "============================================"
