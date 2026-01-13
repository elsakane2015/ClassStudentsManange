# Dokploy 部署指南 (Docker + Laravel)

本指南将教你如何使用 Dokploy 和 Dockerfile 部署这个 Laravel 项目。

---

## 目录

1. [Dokploy 简介](#1-dokploy-简介)
2. [前置条件](#2-前置条件)
3. [安装 Dokploy](#3-安装-dokploy)
4. [创建应用](#4-创建应用)
5. [配置环境变量](#5-配置环境变量)
6. [配置数据库](#6-配置数据库)
7. [配置持久化存储](#7-配置持久化存储)
8. [配置域名和 HTTPS](#8-配置域名和-https)
9. [部署应用](#9-部署应用)
10. [常见问题解决](#10-常见问题解决)

---

## 1. Dokploy 简介

**Dokploy** 是一个开源的自托管 PaaS（平台即服务），类似于 Heroku、Vercel 或 Coolify。它的主要特点：

- ✅ 完全开源免费
- ✅ 支持 Docker 和 Docker Compose
- ✅ 内置 Traefik 反向代理，自动配置 HTTPS
- ✅ 支持 Git 仓库自动部署
- ✅ 支持自定义 Dockerfile
- ✅ 内置数据库管理（MySQL、PostgreSQL、Redis 等）

**官网**：https://dokploy.com

---

## 2. 前置条件

### 2.1 服务器要求

- **操作系统**：Ubuntu 20.04+ / Debian 11+ / CentOS 8+
- **内存**：最低 1GB RAM（推荐 2GB+）
- **存储**：最低 20GB 磁盘空间
- **网络**：公网 IP 和开放的端口（80, 443, 3000）

### 2.2 域名配置（可选但推荐）

如果你有域名，请提前将域名 DNS 解析到你的服务器 IP：

```
A 记录: example.com → 你的服务器IP
A 记录: *.example.com → 你的服务器IP (用于子域名)
```

### 2.3 代码托管

确保你的项目已推送到 Git 仓库（GitHub、GitLab、Gitea 等）。

---

## 3. 安装 Dokploy

### 3.1 一键安装

SSH 登录到你的服务器，运行以下命令：

```bash
curl -sSL https://dokploy.com/install.sh | sh
```

安装完成后会显示：
- Dokploy 面板地址（默认 `http://你的IP:3000`）
- 初始管理员账号信息

### 3.2 访问 Dokploy 面板

打开浏览器访问 `http://你的服务器IP:3000`，按提示创建管理员账号。

### 3.3 配置 Dokploy 域名（可选）

如果有域名，可以在 **Settings → Server → Domain** 中设置 Dokploy 的访问域名。

---

## 4. 创建应用

### 4.1 创建项目

1. 登录 Dokploy 面板
2. 点击 **Create Project**
3. 输入项目名称，例如：`class-students-manage`

### 4.2 添加应用

1. 在项目中点击 **+ Add Service** → **Application**
2. 填写应用信息：
   - **Name**: `app`（或其他名称）
   - **Description**: 学生考勤管理系统

### 4.3 配置 Git 仓库

1. 进入应用设置 → **General** 标签页
2. 选择 **Provider**: `GitHub` / `GitLab` / `Git`（取决于你的代码托管平台）
3. 配置仓库：
   - **Repository**: 选择或输入你的仓库地址
   - **Branch**: `main` 或 `master`
   - **Build Path**: `/`（根目录，因为 Dockerfile 在根目录）

### 4.4 配置构建方式

1. 在 **General** → **Build Type** 选择 **Dockerfile**
2. **Dockerfile Path**: `./Dockerfile`（默认即可）

> 💡 **重要**：你的项目已经有完善的 Dockerfile，Dokploy 会自动使用它来构建镜像。

---

## 5. 配置环境变量

### 5.1 在 Dokploy 中添加环境变量

进入应用 → **Environment** 标签页，添加以下环境变量：

```env
# 应用配置
APP_NAME=学生考勤管理系统
APP_ENV=production
APP_KEY=base64:你的密钥（用 php artisan key:generate --show 生成）
APP_DEBUG=false
APP_TIMEZONE=Asia/Shanghai
APP_URL=https://你的域名.com

# 语言配置
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=zh_CN

# 日志配置
LOG_CHANNEL=stack
LOG_LEVEL=error

# 数据库配置（如果使用 MySQL，见下一节配置）
DB_CONNECTION=mysql
DB_HOST=数据库服务名称（如 mysql）
DB_PORT=3306
DB_DATABASE=class_students
DB_USERNAME=root
DB_PASSWORD=你的数据库密码

# Session 配置
SESSION_DRIVER=database
SESSION_LIFETIME=120

# 缓存和队列
CACHE_STORE=database
QUEUE_CONNECTION=database

# 如果使用 SQLite（简单部署）
# DB_CONNECTION=sqlite
```

### 5.2 生成 APP_KEY

本地运行以下命令获取密钥：

```bash
php artisan key:generate --show
```

复制输出的 `base64:xxxxx...` 作为 `APP_KEY` 的值。

---

## 6. 配置数据库

### 6.1 使用 Dokploy 内置数据库

1. 在项目中点击 **+ Add Service** → **Database**
2. 选择 **MySQL** 或 **MariaDB**
3. 配置数据库：
   - **Name**: `mysql`
   - **Database Name**: `class_students`
   - **Username**: `root`（或自定义）
   - **Password**: 设置一个强密码
   - **Root Password**: 设置 root 密码

### 6.2 获取数据库连接信息

创建数据库后，Dokploy 会显示内部服务名称，通常是：
- **Host**: `mysql`（服务名称，在同一项目内可直接使用）
- **Port**: `3306`

将这些信息填入应用的环境变量中。

### 6.3 使用 SQLite（更简单）

如果不需要 MySQL，可以使用 SQLite：

```env
DB_CONNECTION=sqlite
```

SQLite 数据库文件会保存在 `/var/www/html/database/database.sqlite`。

---

## 7. 配置持久化存储

### 7.1 挂载存储目录

进入应用 → **Advanced** → **Volumes**，添加以下挂载：

| 类型 | 宿主机路径 / 卷名 | 容器路径 | 说明 |
|------|-------------------|----------|------|
| Volume | `storage` | `/var/www/html/storage` | 上传文件、日志等 |
| Volume | `database` | `/var/www/html/database` | SQLite 数据库（如果使用） |

> ⚠️ **重要**：不挂载这些目录，每次重新部署后上传的文件会丢失！

### 7.2 配置示例

在 Dokploy 中添加 Volume：

```
Volume Name: storage
Mount Path: /var/www/html/storage

Volume Name: database  
Mount Path: /var/www/html/database
```

---

## 8. 配置域名和 HTTPS

### 8.1 添加域名

1. 进入应用 → **Domains** 标签页
2. 点击 **Add Domain**
3. 填写：
   - **Host**: `你的域名.com`（或子域名如 `app.example.com`）
   - **Path**: `/`（默认）
   - **Container Port**: `8080`（因为我们的 Dockerfile 暴露的是 8080 端口）
   - **HTTPS**: ✅ 选中（自动申请 Let's Encrypt 证书）

### 8.2 等待 SSL 证书

添加域名后，Dokploy 会自动：
1. 配置 Traefik 反向代理
2. 申请 Let's Encrypt SSL 证书
3. 配置 HTTPS 重定向

> 💡 证书申请可能需要几分钟，请确保域名 DNS 已正确解析。

---

## 9. 部署应用

### 9.1 首次部署

1. 进入应用 → **Deployments** 标签页
2. 点击 **Deploy** 按钮
3. 等待构建和部署完成

### 9.2 查看构建日志

点击部署记录可以查看详细的构建日志，包括：
- Docker 镜像构建过程
- Composer 依赖安装
- NPM 依赖安装和前端构建

### 9.3 首次部署后的初始化

部署成功后，需要执行数据库迁移。有两种方式：

#### 方式一：通过 Dokploy 终端

1. 进入应用 → **Advanced** → **Terminal**（或 **Monitoring** → **Terminal**）
2. 执行：

```bash
cd /var/www/html
php artisan migrate --force
php artisan db:seed --force  # 如果需要初始数据
```

#### 方式二：修改入口脚本自动迁移

编辑 `docker-entrypoint.sh`，取消迁移命令的注释：

```bash
# 运行数据库迁移
php artisan migrate --force || true
```

> ⚠️ 注意：自动迁移在某些情况下可能带来风险，请根据实际需求决定。

### 9.4 自动部署（Webhook）

1. 进入应用设置 → **General** → **Webhook**
2. 复制 Webhook URL
3. 在 GitHub/GitLab 仓库的 **Webhooks** 设置中添加此 URL
4. 选择 **Push** 事件触发

配置后，每次推送代码到仓库，Dokploy 会自动重新部署。

---

## 10. 常见问题解决

### 10.1 构建失败：Permission Denied

**问题**：权限错误，laravel 无法写入 storage 目录

**解决**：确保 Dockerfile 中有正确的权限设置（你的 Dockerfile 已经处理了这个问题）

### 10.2 数据库连接失败

**问题**：`SQLSTATE[HY000] [2002] Connection refused`

**解决**：
1. 确认 `DB_HOST` 使用的是 Dokploy 数据库服务的名称（如 `mysql`）
2. 确认数据库服务已启动
3. 确认用户名和密码正确

### 10.3 Mixed Content 错误

**问题**：HTTPS 页面加载 HTTP 资源

**解决**：确保 `APP_URL` 使用 `https://` 开头

### 10.4 缓存问题

**问题**：修改配置后不生效

**解决**：进入容器终端执行：

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 10.5 502 Bad Gateway

**问题**：Traefik 无法连接到容器

**解决**：
1. 确认域名配置的端口是 `8080`（不是 80）
2. 检查容器是否正常运行
3. 查看容器日志排查错误

### 10.6 前端资源 404

**问题**：CSS/JS 文件加载失败

**解决**：
1. 确认 `npm run build` 成功执行（查看构建日志）
2. 确认 `APP_URL` 配置正确

---

## 附录：完整部署检查清单

- [ ] Dokploy 已安装并可访问
- [ ] Git 仓库已连接
- [ ] 构建类型选择 Dockerfile
- [ ] 环境变量已配置（特别是 APP_KEY、数据库信息）
- [ ] 数据库服务已创建（MySQL/SQLite）
- [ ] 存储卷已挂载（storage、database）
- [ ] 域名已添加
- [ ] 容器端口配置为 8080
- [ ] HTTPS 已启用
- [ ] 数据库迁移已执行
- [ ] Webhook 已配置（可选）

---

## 与 Coolify 的对比

如果你之前用过 Coolify，这里是主要区别：

| 功能 | Dokploy | Coolify |
|------|---------|---------|
| 开源 | ✅ 完全开源 | ✅ 完全开源 |
| Docker 支持 | ✅ | ✅ |
| 自定义 Dockerfile | ✅ | ✅ |
| 内置数据库 | ✅ | ✅ |
| 自动 HTTPS | ✅ (Traefik) | ✅ (Traefik) |
| 界面风格 | 现代简洁 | 功能丰富 |
| 资源占用 | 较低 | 较高 |

两者配置方式非常相似，你的现有 Dockerfile 和配置文件无需修改即可在 Dokploy 上使用。

---

## 相关文档

- [Dokploy 官方文档](https://docs.dokploy.com)
- [本项目 Coolify 部署指南](./install_coolify.md)
- [本项目 Docker 部署指南](./install_docker.md)
