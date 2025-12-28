# 浏览器控制台测试脚本

## 🧪 在浏览器控制台中运行以下代码

### 测试1：检查axios配置

```javascript
console.log('Axios baseURL:', axios.defaults.baseURL);
console.log('Axios withCredentials:', axios.defaults.withCredentials);
console.log('Axios headers:', axios.defaults.headers.common);
```

### 测试2：直接调用API

```javascript
axios.get('/api/attendance/student-records', {
    params: {
        student_id: 6,
        scope: 'today'
    }
})
.then(response => {
    console.log('✅ Success!');
    console.log('Response data:', response.data);
    console.log('Is array:', Array.isArray(response.data));
    console.log('Record count:', response.data.length);
})
.catch(error => {
    console.error('❌ Error!');
    console.error('Error:', error);
    console.error('Response:', error.response);
    console.error('Response data:', error.response?.data);
    console.error('Status:', error.response?.status);
});
```

### 测试3：检查认证状态

```javascript
axios.get('/api/me')
.then(response => {
    console.log('✅ Authenticated!');
    console.log('User:', response.data);
})
.catch(error => {
    console.error('❌ Not authenticated!');
    console.error('Error:', error.response);
});
```

---

## 📋 预期结果

### 如果认证正常

**测试1**：
```
Axios baseURL: /api
Axios withCredentials: true
```

**测试2**：
```
✅ Success!
Response data: [{...}, {...}, {...}, {...}]
Is array: true
Record count: 4
```

**测试3**：
```
✅ Authenticated!
User: {id: 7, name: 'Admin User', role: 'system_admin', ...}
```

### 如果认证失败

**测试2**：
```
❌ Error!
Status: 401
Response data: {message: 'Unauthenticated.'}
```

**测试3**：
```
❌ Not authenticated!
Status: 401
```

---

## 🔧 如果认证失败

### 解决方案1：重新登录

1. 退出登录
2. 重新登录
3. 再次测试

### 解决方案2：检查Session

```javascript
// 检查是否有session cookie
document.cookie.split(';').forEach(c => console.log(c.trim()));
```

---

## 📝 请执行测试并提供结果

1. **打开浏览器控制台** (F12)
2. **运行测试1、2、3**
3. **复制所有输出**
4. **提供给我**

---

*测试目的: 确定API调用失败的具体原因*
*可能原因: 认证失败、路由问题、CORS问题*
