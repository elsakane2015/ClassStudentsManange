# Docker环境修复说明

## 问题原因

您在使用Docker环境，但同时在**宿主机**上运行了`php artisan serve --port=8000`。

宿主机上的PHP无法连接到Docker容器内的MySQL（因为`.env`中`DB_HOST=mysql`是Docker网络内的主机名）。

## 已完成的修复

### 1. 停止了宿主机上的Laravel服务
- 已终止端口8000上的`php artisan serve`进程

### 2. 更新了前端API配置  
- 修改了`resources/js/bootstrap.js`
- 前端现在连接到`http://localhost/api`（Docker容器的端口80）

### 3. 验证了Docker环境
✅ MySQL容器正常运行（端口3306）
✅ Laravel容器正常运行（端口80）
✅ 数据库中有数据：
   - 8个学生
   - 2个班级
   - 11个用户
   - 2个部门

## 下一步操作

### 1. 刷新浏览器
访问 `http://localhost:5173` 并刷新页面

### 2. 如果还有问题，运行迁移
```bash
docker exec classstudentsmanange-laravel.test-1 php artisan migrate
```

### 3. 查看日志（如需调试）
```bash
# Laravel日志
docker exec classstudentsmanange-laravel.test-1 tail -f storage/logs/laravel.log

# MySQL日志
docker logs classstudentsmanange-mysql-1
```

## 端口说明

- **5173**: Vite开发服务器（前端）
- **80**: Laravel应用（Docker容器）
- **3306**: MySQL数据库（Docker容器）

## 重要提示

以后请**不要**在宿主机上运行`php artisan serve`，所有Laravel命令都应该在Docker容器内执行：

```bash
# 正确方式
docker exec classstudentsmanange-laravel.test-1 php artisan [command]

# 或者进入容器
docker exec -it classstudentsmanange-laravel.test-1 bash
```
