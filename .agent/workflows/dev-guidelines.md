---
description: 开发前必读 - 项目开发规范和注意事项
---

# 项目开发规范

在进行任何编码工作前，请先阅读本文档。

## 开发环境

### Docker 环境

本项目使用 **Docker (Laravel Sail)** 进行本地开发和调试。

**容器信息**：
- Laravel 容器: `classstudentsmanange-laravel.test-1`
- MySQL 容器: `classstudentsmanange-mysql-1`

**重要**：不要直接使用 `php artisan migrate` 命令，因为本地环境无法直接连接 Docker 容器内的 MySQL。

#### 执行数据库迁移

使用 Docker 环境执行迁移命令：

```bash
docker exec classstudentsmanange-laravel.test-1 php artisan migrate
```

或者让用户手动在 Docker 容器内执行：

```bash
# 进入容器
docker exec -it classstudentsmanange-laravel.test-1 bash

# 执行迁移
php artisan migrate
```

#### 执行其他 artisan 命令

```bash
# 清除缓存
docker exec classstudentsmanange-laravel.test-1 php artisan cache:clear

# 队列工作
docker exec classstudentsmanange-laravel.test-1 php artisan queue:work

# Tinker
docker exec -it classstudentsmanange-laravel.test-1 php artisan tinker
```

#### 查看容器

```bash
docker ps
```

---

## 编码规范

### 1. 禁止硬编码

❌ **错误示例**：
```javascript
if (type.name === '病假') { ... }
if (status === 'pending') { ... }
```

✅ **正确做法**：
- 使用配置文件或数据库中的配置
- 使用常量或枚举
- 通过 slug/key 而非显示名称进行判断

```javascript
// 使用 slug 而非名称
if (type.slug === 'sick_leave') { ... }

// 从配置读取
const config = settings.dashboard_stats_config;
if (config.show_pending_approval) { ... }
```

### 2. 数据库配置优先

- 新增功能时，优先考虑通过 `system_settings` 表进行配置
- 避免在代码中固定业务逻辑值
- 请假类型、节次、时段等都应该从数据库读取

### 3. 前端动态渲染

- 统计卡片、菜单项等应根据后端配置动态生成
- 不要硬编码显示项目列表

---

## 文件结构

### 后端

- **Controllers**: `app/Http/Controllers/Api/`
- **Models**: `app/Models/`
- **Migrations**: `database/migrations/`
- **Routes**: `routes/api.php`

### 前端

- **Pages**: `resources/js/pages/`
- **Components**: `resources/js/components/`
- **Store**: `resources/js/store/`

---

## 常见问题

### Q: MySQL 连接失败 (MySQL server has gone away)

原因：本地环境无法直接连接 Docker 容器内的数据库。

解决方案：
1. 在 Docker 容器内执行数据库命令
2. 或者配置 Docker 端口映射后使用正确的连接信息

### Q: 编译后页面没有更新

解决方案：
```bash
npm run build
# 或开发时使用
npm run dev
```

### Q: 如何添加新的系统配置

1. 在 `system_settings` 表中添加配置项
2. 前端从 `/settings` API 获取配置
3. 使用 `JSON.parse()` 解析 JSON 格式的配置值

---

## 检查清单

每次编码前请确认：

- [ ] 是否了解当前使用的是 Docker 开发环境
- [ ] 是否避免了硬编码（使用配置/常量/slug）
- [ ] 新增的配置是否添加到了 `system_settings` 表
- [ ] 前端是否从 API 获取配置而非硬编码
- [ ] 数据库迁移是否提醒用户在 Docker 容器内执行
