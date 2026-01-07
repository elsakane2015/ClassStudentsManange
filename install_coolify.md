# Coolify 部署指南 (Dockerfile 方式)

本文档详细介绍如何使用 **Dockerfile** 方式在 Coolify 上部署本 Laravel + React 项目。

> **推荐方式**: 本项目已包含完整的 `Dockerfile`、`docker-entrypoint.sh` 和 `.dockerignore`，使用 Dockerfile 方式部署更稳定可控。

## 目录
1. [服务器准备](#1-服务器准备)
2. [安装 Coolify](#2-安装-coolify)
3. [创建项目](#3-创建项目)
4. [创建 MySQL 数据库](#4-创建-mysql-数据库)
5. [部署 Laravel 应用](#5-部署-laravel-应用)
6. [配置环境变量](#6-配置环境变量)
7. [配置域名与 HTTPS](#7-配置域名与-https)
8. [部署与运行](#8-部署与运行)
9. [运行数据库迁移](#9-运行数据库迁移)
10. [常见问题排查](#10-常见问题排查)

---

## 1. 服务器准备

### 硬件要求
| 配置项 | 最低要求 | 推荐配置 |
|--------|---------|---------|
| CPU | 1核 | 2核及以上 |
| 内存 | 2GB | 4GB及以上 |
| 硬盘 | 20GB | 40GB及以上 |

### 操作系统
- **推荐**: Ubuntu 22.04 LTS / 24.04 LTS
- **支持**: Debian 11+, CentOS 8+

### 端口要求
确保防火墙开放以下端口：
- `22` - SSH（安装时需要）
- `80` - HTTP
- `443` - HTTPS  
- `8000` - Coolify 管理面板

---

## 2. 安装 Coolify

### 2.1 SSH 登录服务器

```bash
ssh root@你的服务器IP
```

### 2.2 执行官方安装脚本

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

安装过程约 3-5 分钟，完成后会显示：
```
Coolify installed successfully!
Access your Coolify at: http://你的服务器IP:8000
```

### 2.3 初始化 Coolify

1. 浏览器访问 `http://你的服务器IP:8000`
2. 注册管理员账号
3. 登录后完成初始向导（选择本地服务器作为部署目标）

---

## 3. 创建项目

1. 在 Coolify 首页，点击 **Projects**
2. 点击 **+ Add** 创建新项目
3. 输入项目名称，例如 `SmartCampus`
4. 点击 **Continue**
5. 选择 **Production** 环境

---

## 4. 创建 MySQL 数据库

### 4.1 添加数据库资源

1. 在项目页面，点击 **+ New**
2. 选择 **Database** → **MySQL**
3. 配置：
   - **Name**: `smart-campus-db`
   - **Version**: `8.0`（推荐）或 `5.7`
4. 点击 **Start** 启动数据库

### 4.2 记录连接信息

数据库启动后，点击进入查看连接详情，记录以下信息：

| 字段 | 说明 | 示例值 |
|------|------|--------|
| **Host** | 内部主机名（UUID格式） | `jco8wkcg004wwgcgwgc44c4o` |
| **Port** | 端口 | `3306` |
| **Database** | 数据库名 | `default` |
| **Username** | 用户名 | `root` |
| **Password** | 密码 | `自动生成的密码` |

> ⚠️ **重要**: `Host` 是一个 UUID 字符串，不是 `localhost`！这是 Docker 内部网络通信地址。

---

## 5. 部署 Laravel 应用

### 5.1 添加应用资源

1. 回到项目页面，点击 **+ New**
2. 选择 **Public Repository**（公开仓库）或 **Private Repository**（私有仓库）
3. 输入 Git 仓库地址：
   ```
   https://github.com/你的用户名/ClassStudentsManange.git
   ```
4. 点击 **Check Repository**
5. 选择分支：`main`

### 5.2 选择 Build Pack

**关键步骤**：选择 **Dockerfile** 作为 Build Pack。

![选择 Dockerfile](https://img.shields.io/badge/Build_Pack-Dockerfile-blue)

Coolify 会自动检测到项目根目录的 `Dockerfile`。

### 5.3 基本配置

点击 **Continue** 进入配置页面：

- **Name**: `smart-campus-app`
- **Ports Exposes**: `8080`（重要！基础镜像使用 8080 端口）

---

## 6. 配置环境变量

点击应用的 **Environment Variables** 标签页，添加以下变量：

### 6.1 必需的环境变量

```dotenv
# 应用基础配置
APP_NAME="Smart Campus"
APP_ENV=production
APP_KEY=base64:你的密钥...
APP_DEBUG=false
APP_URL=https://你的域名.com
APP_TIMEZONE=Asia/Shanghai
APP_LOCALE=zh_CN

# 数据库配置（使用第4步记录的信息）
DB_CONNECTION=mysql
DB_HOST=jco8wkcg004wwgcgwgc44c4o
DB_PORT=3306
DB_DATABASE=default
DB_USERNAME=root
DB_PASSWORD=你的数据库密码

# HTTPS/代理配置（Coolify 使用 Traefik 反向代理）
TRUSTED_PROXIES=*
ASSET_URL=https://你的域名.com

# 时区配置
TZ=Asia/Shanghai

# 其他配置
BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120
```

### 6.2 生成 APP_KEY

如果没有 APP_KEY，可以在本地运行：
```bash
php artisan key:generate --show
```
复制输出的 `base64:...` 字符串作为 `APP_KEY` 的值。

### 6.3 保存配置

点击 **Save** 保存环境变量。

---

## 7. 配置域名与 HTTPS

### 7.1 DNS 配置

在你的 DNS 服务商处添加 A 记录：
```
类型: A
名称: stu (或其他子域名)
值: 你的服务器IP
TTL: 300
```

### 7.2 配置域名

在应用的 **General** 标签页：

1. 找到 **Domains** 字段
2. 填入完整域名，例如：`https://stu.art-design.top`
3. 点击 **Save**

Coolify 会自动：
- 配置 Traefik 反向代理
- 申请 Let's Encrypt SSL 证书
- 实现 HTTPS 访问

---

## 8. 部署与运行

### 8.1 开始部署

点击应用右上角的 **Deploy** 按钮。

### 8.2 查看部署日志

点击 **Deployments** 标签页，实时查看构建日志：

```
Sending build context to Docker daemon...
Step 1/12 : FROM serversideup/php:8.4-fpm-nginx
Step 2/12 : USER root
Step 3/12 : ENV TZ=Asia/Shanghai
...
Step 11/12 : RUN npm install && npm run build
...
Successfully built abc123def456
```

### 8.3 确认部署成功

- 状态显示 **Running** 或 **Healthy**
- 访问 `https://你的域名.com` 能正常打开

---

## 9. 运行数据库迁移

部署成功后，需要执行数据库迁移。

### 9.1 进入容器终端

1. 在应用页面，点击 **Terminal** 标签页
2. 或者 SSH 到服务器后执行：

```bash
# 找到容器 ID
docker ps | grep smart-campus

# 进入容器
docker exec -it 容器ID bash
```

### 9.2 执行迁移

```bash
cd /var/www/html
php artisan migrate --force
```

### 9.3 导入初始数据（可选）

如果有 SQL 导入文件：
```bash
# 使用 mysql 命令导入
mysql -h 数据库Host -u root -p数据库密码 default < /path/to/data.sql
```

---

## 10. 常见问题排查

### 10.1 Mixed Content 错误（HTTPS 页面加载 HTTP 资源）

**症状**: 浏览器控制台显示 "Mixed Content" 警告，页面样式/脚本无法加载。

**解决方案**:

1. 确保环境变量正确设置：
   ```dotenv
   APP_URL=https://你的域名.com
   ASSET_URL=https://你的域名.com
   TRUSTED_PROXIES=*
   ```

2. 项目已在 `bootstrap/app.php` 中配置了 `trustProxies`：
   ```php
   $middleware->trustProxies(at: '*');
   ```

3. 重新部署应用。

### 10.2 时区显示不正确

**症状**: 时间显示为 UTC 而非北京时间。

**解决方案**:

1. `Dockerfile` 已包含时区设置：
   ```dockerfile
   ENV TZ=Asia/Shanghai
   RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
   ```

2. 确保环境变量设置：
   ```dotenv
   APP_TIMEZONE=Asia/Shanghai
   TZ=Asia/Shanghai
   ```

3. 重新部署应用。

### 10.3 数据库连接失败

**症状**: 报错 "SQLSTATE[HY000] [2002] Connection refused"

**排查步骤**:

1. **确认数据库正在运行**: 在 Coolify 中检查数据库状态是否为 `Running`
2. **检查 DB_HOST**: 必须是 Coolify 分配的 UUID 主机名，不能是 `localhost` 或 `127.0.0.1`
3. **检查网络**: 应用和数据库必须在同一个 Docker 网络中（Coolify 默认会自动处理）

### 10.4 500 Internal Server Error

**排查步骤**:

1. **查看日志**:
   ```bash
   docker logs 容器ID
   cat /var/www/html/storage/logs/laravel.log
   ```

2. **常见原因**:
   - `APP_KEY` 未设置
   - 数据库配置错误
   - `storage` 目录权限问题

3. **修复权限**（如果需要）:
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### 10.5 前端样式丢失

**症状**: 页面加载但没有 CSS 样式

**解决方案**:

1. 确认 `public/build` 目录存在
2. 检查部署日志，确保 `npm run build` 成功执行
3. 如果失败，可能是 Node.js 内存不足：
   ```bash
   export NODE_OPTIONS="--max-old-space-size=4096"
   npm run build
   ```

### 10.6 重新部署后数据丢失

**说明**: Coolify 默认的容器是无状态的，重新部署会重置容器内文件。

**需要持久化的数据**:
- 数据库数据（Coolify 已自动持久化）
- 上传的文件（`storage/app/public`）

**配置文件存储持久化**:

1. 在应用的 **Storages** 标签页
2. 添加 Volume:
   - Source: `/data/storage/smart-campus`
   - Destination: `/var/www/html/storage/app/public`

---

## 附录：项目文件说明

### Dockerfile

```dockerfile
FROM serversideup/php:8.4-fpm-nginx

# 切换到 root 以进行安装
USER root

# 设置时区
ENV TZ=Asia/Shanghai
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# 安装 Node.js、NPM 和 GD 扩展
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    nodejs npm libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 复制启动脚本
COPY docker-entrypoint.sh /etc/entrypoint.d/99-laravel-init.sh
RUN chmod +x /etc/entrypoint.d/99-laravel-init.sh

# 复制项目文件
COPY --chown=www-data:www-data . /var/www/html

# 切换回 www-data 用户
USER www-data

# 安装依赖
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
RUN npm install && npm run build

EXPOSE 8080
```

### docker-entrypoint.sh

容器启动时自动执行的初始化脚本：
- `php artisan package:discover`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`

### .dockerignore

排除不需要复制到容器的文件，加快构建速度。

---

## 快速检查清单

部署完成后，请确认以下项目：

- [ ] 访问 `https://你的域名.com` 显示登录页面
- [ ] 页面样式正常加载（无 Mixed Content 警告）
- [ ] 时间显示正确（北京时间）
- [ ] 能正常登录/注册
- [ ] 数据库迁移已执行

---

**部署遇到问题？** 查看 Coolify 的部署日志和容器日志获取更多信息。
