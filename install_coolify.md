# Coolify 部署指南

Coolify 是一个强大的自托管平台，可以像 Vercel 或 Netlify 一样轻松部署应用。通过 Coolify，你可以在自己的服务器上轻松部署和管理本项目。

## 目录
1. [服务器准备](#1-服务器准备)
2. [安装 Coolify](#2-安装-coolify)
3. [创建项目](#3-创建项目)
4. [部署 Laravel 应用](#4-部署-laravel-应用)
5. [配置数据库](#5-配置数据库)
6. [配置环境变量](#6-配置环境变量)
7. [完成部署与验证](#7-完成部署与验证)

---

## 1. 服务器准备

你需要一台 Linux 服务器（推荐 Ubuntu 22.04 LTS）：
*   **CPU**: 建议 2核及以上
*   **内存**: 建议 4GB 及以上
*   **端口**: 确保开放 8000, 22, 80, 443 端口

## 2. 安装 Coolify

使用 SSH 登录你的服务器，执行以下官方一键安装命令：

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

安装完成后，控制台会输出访问地址，类似于 `http://<你的服务器IP>:8000`。

打开浏览器，访问该地址，注册管理员账号并登录。

## 3. 创建项目

1.  登录 Coolify 后，点击主界面的 **Projects**。
2.  点击 **+ Add** 创建新项目。
3.  输入项目名称（比如 `SmartCampus`），点击 **Continue**。
4.  在环境选择中，点击 **Production**（生产环境）。

## 4. 部署 Laravel 应用

此时你位于项目资源列表页面，开始添加应用：

1.  点击 **+ New** 按钮。
2.  选择 **Git Source** -> **Public Repository** (如果是私有仓库请选择 Private Repository 并配置密钥)。
3.  输入本项目的 Git 地址（例如 `https://github.com/username/project.git`）。
4.  点击 **Check Repository**，选择分支 (通常是 `main` 或 `master`)。
5.  在 Build Pack 选项中，Coolify 通常会自动识别为 **Nixpacks**，保持默认即可。
    *   *注意：本项目推荐 PHP 版本为 8.2 或 8.3。Coolify 的 Nixpacks 默认配置通常能很好地支持。*
6.  点击 **Manage Resource (配置资源)**。

### 关键配置

在应用的配置页面中：
*   **Name**: 给应用起个名字，如 `smart-campus-app`。
*   **Domains (For Https)**: 填入你的域名，例如 `http://campus.yoursite.com` (如果你配置了DNS指向该IP，Coolify会自动申请SSL证书变成https)。
*   **Build Settings**:
    *   Install Command: `npm install && npm run build && composer install --no-dev --optimize-autoloader`
    *   Build Command: (通常为空，或者与上面合并)
    *   Start Command: `php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=80`
    *   *提示：如果使用默认 Nixpacks 配置，它可能会自动处理启动。如果启动失败，请填入上述 Start Command。*
*   **Storage (存储/挂载)**:
    *   点击 **Storage** 标签页。
    *   添加一个新的 Volume：
        *   **Source path** (服务器路径): `/etc/localtime`
        *   **Destination path** (容器内路径): `/etc/localtime`
        *   此操作可让容器直接同步宿主机的系统时间。

## 5. 配置数据库

应用需要数据库支持，我们在 Coolify 中添加一个 MySQL 数据库：

1.  回到项目概览页面，或者在左侧菜单点击 **New Resource**。
2.  选择 **Database** -> **MySQL**。
3.  配置参数：
    *   **Name**: `smart-campus-db`
    *   **Version**: 推荐 `8.0`（如果担心兼容性问题，可以选择 `5.7`，但本项目建议 8.0）。
    *   **Destination Docker Network**: 确保与你的应用在同一个网络（Coolify 默认会自动处理）。
4.  点击 **Start** 启动数据库。
5.  数据库启动后，查看其 **Connection Details**。你会看到：
    *   User (通常是 `root`)
    *   Password
    *   Database Name
    *   **Internal Connection URL** (类似于 `mysql://root:password@uuid:3306/db`)
    *   **注意：** 请复制 `Host` 字段（通常是一个 UUID 字符串），这是容器间的内部通信地址。

## 6. 配置环境变量

回到刚才创建的 **Laravel 应用** 配置页面，点击 **Environment Variables** 标签页。

你需要添加或修改以下变量（参考本地 `.env`）：

```dotenv
APP_NAME="Smart Campus"
APP_ENV=production
APP_KEY=base64:你的KEY...  # 务必生成一个新的或复制本地的
APP_DEBUG=false
APP_URL=https://你的域名.com
APP_TIMEZONE=Asia/Shanghai

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=请填入数据库的InternalHost(UUID)  # 重要互通地址
DB_PORT=3306
DB_DATABASE=请填入数据库名
DB_USERNAME=root
DB_PASSWORD=请填入数据库密码

# 其他推荐配置
BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
```

点击 **Save** 保存配置。

## 7. 完成部署与验证

1.  在应用配置页面右上角，点击 **Deploy**。
2.  点击 **Logs** 查看部署进度。
    *   系统会拉取代码。
    *   构建前端（Vite）。
    *   安装 PHP 依赖。
    *   启动容器。
3.  当日志显示 "Healthy" 或部署成功后，尝试访问你配置的域名。

### 常见问题排查

*   **500 错误**:
    *   检查 `APP_KEY` 是否已设置。
    *   检查数据库连接信息是否正确（特别是 `DB_HOST` 必须是 Coolify 内部的 UUID 主机名，不能是 localhost）。
    *   检查 `storage` 目录权限（Coolify 通常处理得当，但如果报错日志提示 permission denied，可能需要在构建脚本加 `chmod -R 777 storage`）。

*   **样式丢失**:
    *   确保构建过程中执行了 `npm run build`。
    *   确保 `public/build` 目录存在。

*   **数据库迁移失败**:
    *   确保数据库容器已在该应用部署前启动完毕（Healthy 状态）。
