# 智慧校园考勤系统 - 安装教程

本教程适用于在 **宝塔面板** 或类似环境（如 mdserver-web）下部署智慧校园考勤系统。

---

## 📋 环境要求

| 组件 | 版本要求 | 备注 |
|------|---------|------|
| PHP | 8.2+ | 需安装扩展：fileinfo, bcmath, ctype, json, mbstring, openssl, pdo, tokenizer, xml |
| MySQL | 5.7+ / 8.0+ | 推荐 8.0，字符集 utf8mb4 |
| Nginx | 1.18+ | 或 Apache 2.4+ |

> 💡 使用预编译发布包时，**不需要** 安装 Composer 或 Node.js！

---

## ⚡ 快速安装（推荐）

使用预编译发布包，简单快速！

### 第一步：下载发布包

从 GitHub Releases 下载最新版本的 `smartcampus-x.x.x.zip`

### 第二步：创建站点和数据库

1. 登录面板
2. 点击 **网站** → **添加站点**
3. 填写信息：
   - **域名**：你的域名（如 `attendance.example.com`）
   - **数据库**：选择 **MySQL**，字符集选择 **utf8mb4**
   - **PHP版本**：选择 **PHP 8.2** 或更高
4. **记录数据库信息**（数据库名、用户名、密码）

### 第三步：上传并解压

1. 在面板 **文件管理** 中进入站点目录
2. 上传 `smartcampus-x.x.x.zip`
3. 解压，将所有文件移动到站点根目录

### 第四步：配置网站

1. **网站目录** → 将 **运行目录** 设置为 `/public`
2. **伪静态** → 选择 **laravel** 模板，或手动添加：
   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$query_string;
   }
   ```
3. **SSL** → 配置 HTTPS 证书（推荐）

### 第五步：配置 PHP-FPM 超时（重要！）

安装过程需要执行数据库迁移，可能耗时超过30秒。需要增加 PHP-FPM 超时时间：

```bash
# 查找 PHP-FPM 配置文件
find /www -name "www.conf" 2>/dev/null | grep php | head -1

# 编辑配置文件（以 PHP 8.5 为例）
nano /www/server/php/85/etc/php-fpm.d/www.conf

# 找到并修改：
# request_terminate_timeout = 300

# 或一键修改（根据实际PHP版本路径调整）
sed -i 's/request_terminate_timeout = 30/request_terminate_timeout = 300/' /www/server/php/85/etc/php-fpm.d/www.conf

# 重启 PHP-FPM
systemctl restart php85-fpm
```

### 第六步：访问安装向导

1. 访问 `https://你的域名/install`
2. 按照安装向导完成以下步骤：
   - 环境检测
   - 数据库配置
   - 学校信息
   - 管理员账户
3. 等待安装完成（可能需要1-2分钟）

---

## 🔧 手动安装（备选方案）

如果安装向导出现502错误或其他问题，可以使用命令行安装：

通过 SSH 连接到服务器，执行以下命令：

```bash
cd /www/wwwroot/你的站点目录

# 1. 设置权限
chown -R www:www .
chmod -R 755 storage bootstrap/cache

# 2. 生成应用密钥
php artisan key:generate

# 3. 配置数据库连接
nano .env
# 修改以下配置：
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=你的数据库名
# DB_USERNAME=你的数据库用户名
# DB_PASSWORD=你的数据库密码

# 4. 执行数据库迁移
php artisan migrate:fresh --force

# 5. 创建学校记录
php artisan tinker --execute="App\Models\School::create(['name' => '你的学校名称']); echo 'OK';"

# 6. 运行数据填充
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=LeaveTypeSeeder --force
php artisan db:seed --class=SystemSettingsSeeder --force

# 7. 创建管理员账户（密码: 123456）
php artisan tinker --execute="App\Models\User::create(['uuid'=>Str::uuid(),'name'=>'Admin','email'=>'admin@demo.com','password'=>Hash::make('123456'),'role'=>'system_admin']); echo 'OK';"

# 8. 完成安装
echo $(date) > storage/installed
php artisan optimize:clear
php artisan storage:link

echo "✅ 安装完成！"
```

### 第六步：登录系统

1. 访问 `https://你的域名`
2. 使用管理员账户登录：
   - **邮箱**：`admin@demo.com`
   - **密码**：`123456`
3. **登录后请立即修改密码！**

---

## ⏰ 配置定时任务

在面板 **计划任务** 中添加：

| 任务名称 | 执行周期 | 脚本内容 |
|---------|---------|---------|
| Laravel 调度器 | 每分钟 | `cd /www/wwwroot/你的站点目录 && php artisan schedule:run >> /dev/null 2>&1` |

---

## 🔧 常见问题

### 1. 页面显示 500 错误
```bash
# 检查日志
tail -100 storage/logs/laravel.log

# 确保权限正确
chown -R www:www /www/wwwroot/你的站点目录
chmod -R 775 storage bootstrap/cache
```

### 2. 页面显示 502/503 错误
```bash
# 检查 PHP-FPM 是否正常运行
# 检查 .env 文件中的数据库配置是否正确
php artisan config:clear
```

### 3. 登录密码错误
```bash
# 重置管理员密码为 123456
php artisan tinker --execute="App\Models\User::where('email','admin@demo.com')->update(['password'=>Hash::make('123456')]); echo 'OK';"
```

### 4. 图片上传失败
```bash
# 检查 storage 链接
ls -la public/storage

# 重新创建链接
rm -f public/storage
php artisan storage:link
```

### 5. 数据库迁移报错"列已存在"
这通常是因为有重复的迁移文件。使用 `migrate:fresh` 命令全新安装：
```bash
php artisan migrate:fresh --force
```

---

## 📦 系统升级

```bash
cd /www/wwwroot/你的站点目录

# 1. 备份数据库（重要！）
mysqldump -u用户名 -p 数据库名 > backup_$(date +%Y%m%d).sql

# 2. 上传新版本发布包并解压覆盖

# 3. 运行迁移
php artisan migrate --force

# 4. 清理缓存
php artisan optimize:clear
```

---

## 🔧 从源码安装（开发者）

如果需要从源码安装（用于开发或自定义），请参考以下步骤：

### 额外环境要求

| 组件 | 版本要求 |
|------|---------|
| Composer | 2.x |
| Node.js | 18+ |

### 安装步骤

```bash
cd /www/wwwroot/你的站点目录

# 1. 克隆代码
git clone https://github.com/你的仓库地址.git .

# 2. 安装 PHP 依赖
composer install --no-dev --optimize-autoloader

# 3. 安装前端依赖并编译
npm install
npm run build

# 4. 配置环境变量
cp .env.example .env
nano .env  # 修改数据库配置

# 5. 生成密钥
php artisan key:generate

# 6. 后续步骤同快速安装的第五步...
```

---

## 📞 技术支持

如遇到问题，请：
1. 查看 `storage/logs/laravel.log` 中的错误日志
2. 查看面板的 Nginx/PHP 错误日志
3. 提交 Issue 到项目仓库
