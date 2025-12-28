# 数据显示问题完整排查指南

## 已完成的修复

### 1. 修复了axios配置冲突
- ✅ 更新了 `bootstrap.js` 
- ✅ 更新了 `auth.js`
- 现在两个文件都正确指向 `http://localhost/api`（Docker容器）

### 2. 添加了详细的调试日志
- ✅ Dashboard.jsx 现在会输出详细的请求信息
- ✅ 创建了专门的调试页面 `/debug`

### 3. 验证了后端数据
- ✅ 数据库中有8个学生
- ✅ API返回正确的数据
- ✅ Docker容器正常运行

## 下一步操作

### 步骤1：清除浏览器缓存并重新登录

1. 打开浏览器开发者工具（F12）
2. 进入 Application/应用程序 标签
3. 清除以下内容：
   - Local Storage
   - Session Storage  
   - Cookies
4. 刷新页面
5. 重新登录（admin@demo.com / password）

### 步骤2：检查调试页面

访问 `http://localhost:5173/debug` 查看配置信息

### 步骤3：查看控制台日志

登录后访问 `http://localhost:5173/teacher/dashboard`

在浏览器控制台（Console）中查找：
```
[Dashboard] Starting data fetch, scope: today
[Dashboard] API baseURL: http://localhost/api
[Dashboard] Token: Present
[Dashboard] Stats response: {...}
[Dashboard] Overview response: [...]
```

## 可能的问题和解决方案

### 问题1：Token Missing
**症状**：控制台显示 `Token: Missing`

**解决方案**：
1. 退出登录
2. 清除 localStorage
3. 重新登录

### 问题2：CORS错误
**症状**：控制台显示 CORS policy 错误

**解决方案**：
检查 `config/cors.php` 确保允许 localhost:5173

### 问题3：401 Unauthorized
**症状**：API返回401错误

**解决方案**：
```bash
# 在Docker容器中清除缓存
docker exec classstudentsmanange-laravel.test-1 php artisan config:clear
docker exec classstudentsmanange-laravel.test-1 php artisan cache:clear
```

### 问题4：数据仍然为0
**症状**：API返回成功但数据为0

**可能原因**：
- 用户权限问题（不是admin/teacher角色）
- 数据库中确实没有数据

**验证**：
```bash
# 检查当前用户
docker exec classstudentsmanange-laravel.test-1 php artisan tinker --execute="
\$user = App\Models\User::where('email', 'admin@demo.com')->first();
echo 'User: ' . \$user->name . ' Role: ' . \$user->role . PHP_EOL;
"

# 检查数据
docker exec classstudentsmanange-laravel.test-1 php artisan tinker --execute="
echo 'Students: ' . App\Models\Student::count() . PHP_EOL;
echo 'Classes: ' . App\Models\SchoolClass::count() . PHP_EOL;
"
```

## 测试API（命令行）

```bash
# 1. 获取CSRF token
curl -c /tmp/cookies.txt http://localhost/sanctum/csrf-cookie

# 2. 登录
curl -b /tmp/cookies.txt -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.com","password":"password"}' | jq .

# 3. 测试stats API（使用返回的token）
curl -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  http://localhost/api/attendance/stats?scope=today | jq .
```

## 联系支持

如果以上步骤都无法解决问题，请提供：
1. 浏览器控制台的完整日志
2. `/debug` 页面的输出
3. 网络标签中的API请求详情
