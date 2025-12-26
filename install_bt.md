# 智慧校园考勤系统 - 宝塔面板安装教程

本教程适用于在 **宝塔面板 (BT Panel)** 环境下部署智慧校园考勤系统。

---

## 📋 环境要求

| 组件 | 版本要求 | 备注 |
|------|---------|------|
| PHP | 8.2+ | 需安装扩展：fileinfo, bcmath, ctype, json, mbstring, openssl, pdo, tokenizer, xml |
| MySQL | 5.7+ / 8.0+ | 推荐 8.0 |
| Nginx | 1.18+ | 或 Apache 2.4+ |
| Composer | 2.x | PHP 依赖管理 |
| Node.js | 18+ | 用于编译前端资源 |
| npm | 8+ | - |

---

## 🚀 安装步骤

### 第一步：创建站点

1. 登录宝塔面板
2. 点击 **网站** → **添加站点**
3. 填写信息：
   - **域名**：你的域名（如 `attendance.example.com`）
   - **根目录**：默认即可，系统会自动创建
   - **FTP**：按需创建
   - **数据库**：选择 **MySQL**，记住数据库名、用户名、密码
   - **PHP版本**：选择 **PHP 8.2** 或更高

### 第二步：上传代码

**方式一：Git 克隆（推荐）**
```bash
cd /www/wwwroot/你的站点目录
rm -rf * .*  # 清空目录（如果有默认文件）
git clone https://github.com/你的仓库地址.git .
```

**方式二：上传压缩包**
1. 在宝塔 **文件** 管理中进入站点目录
2. 上传代码压缩包
3. 解压到当前目录

### 第三步：配置环境变量

```bash
cd /www/wwwroot/你的站点目录

# 复制环境配置文件
cp .env.example .env

# 编辑环境配置
nano .env  # 或在宝塔文件管理中编辑
```

**修改以下配置：**
```env
APP_NAME="智慧校园"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的域名

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=你的数据库名
DB_USERNAME=你的数据库用户名
DB_PASSWORD=你的数据库密码
```

### 第四步：安装依赖

```bash
cd /www/wwwroot/你的站点目录

# 安装 PHP 依赖
composer install --no-dev --optimize-autoloader

# 生成应用密钥
php artisan key:generate

# 安装前端依赖并编译
npm install
npm run build
```

### 第五步：运行安装命令

```bash
# 一键安装（推荐）
php artisan app:install --force

# 或分步执行
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=LeaveTypeSeeder --force
php artisan db:seed --class=SystemSettingsSeeder --force
php artisan storage:link
php artisan optimize:clear
```

### 第六步：设置目录权限

```bash
# 设置目录权限
chown -R www:www /www/wwwroot/你的站点目录
chmod -R 755 /www/wwwroot/你的站点目录
chmod -R 775 storage bootstrap/cache
```

### 第七步：配置网站目录

在宝塔面板中：

1. 点击站点 → **设置** → **网站目录**
2. 将 **运行目录** 设置为 `/public`
3. 保存

### 第八步：配置伪静态

在宝塔面板中：

1. 点击站点 → **设置** → **伪静态**
2. 选择 **laravel** 模板，或手动添加：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

3. 保存

### 第九步：配置 HTTPS（推荐）

1. 点击站点 → **设置** → **SSL**
2. 可选择：
   - **Let's Encrypt**：免费证书
   - **宝塔SSL**：付费证书
   - **其他证书**：上传已有证书
3. 开启 **强制HTTPS**

---

## ⏰ 配置定时任务

系统需要配置定时任务来执行：
- 自动标记出勤
- 清理过期图片
- 其他后台任务

在宝塔面板中：

1. 点击 **计划任务**
2. 添加以下任务：

| 任务名称 | 执行周期 | 脚本内容 |
|---------|---------|---------|
| Laravel 调度器 | 每分钟 | `cd /www/wwwroot/你的站点目录 && php artisan schedule:run >> /dev/null 2>&1` |
| 队列处理（如有） | 每分钟 | `cd /www/wwwroot/你的站点目录 && php artisan queue:work --stop-when-empty >> /dev/null 2>&1` |

---

## ✅ 验证安装

1. 访问 `https://你的域名`
2. 使用默认管理员账户登录：
   - **邮箱**：`admin@demo.com`
   - **密码**：`password`
3. 登录后请立即修改密码！

---

## 🔧 常见问题

### 1. 页面显示 500 错误
```bash
# 检查日志
tail -100 storage/logs/laravel.log

# 确保权限正确
chmod -R 775 storage bootstrap/cache
chown -R www:www storage bootstrap/cache
```

### 2. 图片上传失败
```bash
# 检查 storage 链接
ls -la public/storage

# 重新创建链接
rm -f public/storage
php artisan storage:link
```

### 3. 样式/脚本加载失败
```bash
# 重新编译前端
npm run build

# 清理缓存
php artisan optimize:clear
```

### 4. 数据库连接失败
- 检查 `.env` 中的数据库配置
- 确保数据库用户有权限访问该数据库
- 检查 MySQL 是否正在运行

### 5. 502 Bad Gateway
- 检查 PHP-FPM 是否正在运行
- 查看 Nginx 错误日志
- 确保 PHP 版本与配置匹配

---

## 📦 系统升级

```bash
cd /www/wwwroot/你的站点目录

# 拉取最新代码
git pull origin main

# 更新依赖
composer install --no-dev --optimize-autoloader
npm install
npm run build

# 运行迁移
php artisan migrate --force

# 清理缓存
php artisan optimize:clear

# 重启服务
php artisan queue:restart  # 如果使用队列
```

---

## 📞 技术支持

如遇到问题，请：
1. 查看 `storage/logs/laravel.log` 中的错误日志
2. 查看宝塔面板的 Nginx/PHP 错误日志
3. 提交 Issue 到项目仓库
