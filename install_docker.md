# 智慧校园考勤系统 - Docker Compose 安装教程

本教程适用于使用 **Docker Compose** 部署智慧校园考勤系统。

---

## 📋 环境要求

| 组件 | 版本要求 | 备注 |
|------|---------|------|
| Docker | 20.10+ | - |
| Docker Compose | 2.0+ | 或 docker-compose 1.29+ |
| Git | 2.0+ | 用于克隆代码 |

---

## 🚀 快速开始

### 第一步：克隆代码

```bash
git clone https://github.com/你的仓库地址.git attendance-system
cd attendance-system
```

### 第二步：配置环境变量

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑环境配置
nano .env
```

**修改以下配置：**
```env
APP_NAME="智慧校园"
APP_ENV=local          # 开发环境用 local，生产环境用 production
APP_DEBUG=true         # 生产环境设为 false
APP_URL=http://localhost

# 数据库配置（使用 Docker 内置 MySQL）
DB_CONNECTION=mysql
DB_HOST=mysql          # Docker 服务名
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password
```

### 第三步：启动容器

**使用 Laravel Sail（推荐）：**
```bash
# 安装依赖（首次运行）
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# 启动容器
./vendor/bin/sail up -d

# 查看状态
./vendor/bin/sail ps
```

**使用 Docker Compose（通用）：**
```bash
docker compose up -d
docker compose ps
```

### 第四步：安装系统

```bash
# 进入容器执行安装
./vendor/bin/sail artisan app:install --force

# 或使用 docker compose
docker compose exec laravel.test php artisan app:install --force
```

**安装过程会自动完成：**
- ✅ 数据库迁移
- ✅ 初始数据填充（权限、请假类型等）
- ✅ 创建管理员账户
- ✅ 创建 storage 链接
- ✅ 清理缓存

### 第五步：编译前端资源

```bash
# 安装前端依赖
./vendor/bin/sail npm install

# 开发模式（热更新）
./vendor/bin/sail npm run dev

# 生产模式（编译优化）
./vendor/bin/sail npm run build
```

---

## ✅ 验证安装

1. 访问 `http://localhost`（或你配置的端口）
2. 使用默认管理员账户登录：
   - **邮箱**：`admin@demo.com`
   - **密码**：`password`
3. 登录后请立即修改密码！

---

## 🔧 常用命令

### 容器管理

```bash
# 启动容器
./vendor/bin/sail up -d

# 停止容器
./vendor/bin/sail down

# 重启容器
./vendor/bin/sail restart

# 查看日志
./vendor/bin/sail logs -f

# 进入容器 Shell
./vendor/bin/sail shell
```

### Artisan 命令

```bash
# 运行 Artisan 命令
./vendor/bin/sail artisan <command>

# 示例
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan tinker
```

### 数据库操作

```bash
# 进入 MySQL 命令行
./vendor/bin/sail mysql

# 或使用 docker compose
docker compose exec mysql mysql -u sail -ppassword laravel
```

### NPM 命令

```bash
# 安装依赖
./vendor/bin/sail npm install

# 开发模式
./vendor/bin/sail npm run dev

# 生产构建
./vendor/bin/sail npm run build
```

---

## 🐳 Docker Compose 配置

项目默认使用 Laravel Sail，配置文件为 `docker-compose.yml`。

### 默认服务

| 服务 | 端口 | 说明 |
|-----|------|------|
| laravel.test | 80 | PHP 应用 |
| mysql | 3306 | MySQL 数据库 |
| vite | 5173 | Vite 开发服务器 |

### 自定义端口

编辑 `.env` 文件：
```env
APP_PORT=8080          # Web 端口
FORWARD_DB_PORT=33060  # MySQL 外部端口
VITE_PORT=5174         # Vite 端口
```

### 持久化数据

数据库数据存储在 Docker 卷中：
```bash
# 查看卷
docker volume ls | grep sail

# 备份数据库
./vendor/bin/sail artisan db:backup  # 如有备份命令

# 或导出 SQL
docker compose exec mysql mysqldump -u sail -ppassword laravel > backup.sql
```

---

## ⏰ 定时任务

在容器内配置 Laravel 调度器：

```bash
# 进入容器设置 crontab
./vendor/bin/sail shell
crontab -e
```

添加以下行：
```
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

**或者启动独立的调度容器（推荐生产环境）：**

在 `docker-compose.yml` 中添加：
```yaml
scheduler:
    image: sail-8.4/app
    depends_on:
        - mysql
    volumes:
        - '.:/var/www/html'
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
```

---

## 🔄 系统升级

```bash
# 拉取最新代码
git pull origin main

# 更新 PHP 依赖
./vendor/bin/sail composer install

# 更新前端依赖
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# 运行迁移
./vendor/bin/sail artisan migrate --force

# 清理缓存
./vendor/bin/sail artisan optimize:clear

# 重启容器
./vendor/bin/sail restart
```

---

## 🔧 常见问题

### 1. 容器启动失败

```bash
# 查看日志
docker compose logs laravel.test

# 重新构建镜像
./vendor/bin/sail build --no-cache
```

### 2. 数据库连接失败

```bash
# 等待 MySQL 完全启动
sleep 30

# 检查 MySQL 状态
docker compose exec mysql mysqladmin -u sail -ppassword ping

# 确保 .env 中 DB_HOST=mysql（Docker 服务名）
```

### 3. 图片不显示

```bash
# 在容器内创建 storage 链接
./vendor/bin/sail artisan storage:link --force

# 注意：容器内链接路径应为绝对路径
docker compose exec laravel.test bash -c "rm -f public/storage && ln -s /var/www/html/storage/app/public public/storage"
```

### 4. 权限问题

```bash
# 修复权限
./vendor/bin/sail shell
chmod -R 775 storage bootstrap/cache
```

### 5. 端口被占用

```bash
# 检查端口占用
lsof -i :80

# 修改 .env 使用其他端口
APP_PORT=8080
```

---

## 🛡️ 生产环境部署

### 使用独立 Docker Compose

创建 `docker-compose.prod.yml`：

```yaml
version: '3'
services:
    app:
        image: your-registry/attendance-system:latest
        restart: always
        environment:
            - APP_ENV=production
            - APP_DEBUG=false
        ports:
            - "80:80"
        volumes:
            - ./storage:/var/www/html/storage
        depends_on:
            - mysql
    
    mysql:
        image: mysql:8.0
        restart: always
        environment:
            MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
            MYSQL_DATABASE: ${DB_DATABASE}
            MYSQL_USER: ${DB_USERNAME}
            MYSQL_PASSWORD: ${DB_PASSWORD}
        volumes:
            - mysql_data:/var/lib/mysql

volumes:
    mysql_data:
```

### 构建生产镜像

```bash
# 创建 Dockerfile.prod
docker build -f Dockerfile.prod -t attendance-system:latest .

# 推送到镜像仓库
docker push your-registry/attendance-system:latest
```

---

## 📞 技术支持

如遇到问题，请：
1. 查看容器日志 `./vendor/bin/sail logs`
2. 查看 Laravel 日志 `storage/logs/laravel.log`
3. 提交 Issue 到项目仓库
