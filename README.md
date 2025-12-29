# 智慧校园考勤系统 SmartCampus

<p align="center">
  <img src="public/favicon.ico" alt="SmartCampus Logo" width="80">
</p>

<p align="center">
  <strong>现代化的校园考勤管理解决方案</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-11.x-red?logo=laravel" alt="Laravel">
  <img src="https://img.shields.io/badge/React-18.x-blue?logo=react" alt="React">
  <img src="https://img.shields.io/badge/PHP-8.2+-purple?logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-8.0+-orange?logo=mysql" alt="MySQL">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License">
</p>

---

## 📖 简介

智慧校园考勤系统是一个功能完整的校园考勤管理平台，支持多学校、多班级、多角色的考勤管理。系统采用现代化技术栈，提供 WordPress 风格的一键安装体验。

## ✨ 功能特性

### 🏫 学校管理
- 多学校支持
- 院系/部门层级管理
- 学期管理

### 👥 用户角色
| 角色 | 权限说明 |
|------|---------|
| **系统管理员** | 全局配置、用户管理、学校设置 |
| **校级管理员** | 学校内所有班级管理 |
| **院系管理员** | 管辖院系下班级管理 |
| **教师** | 任课班级考勤、请假审批 |
| **班级考勤员** | 学生担任，负责班级日常点名 |
| **学生** | 查看考勤、提交请假 |

### 📋 考勤功能
- **点名管理**：支持批量点名、快速点名
- **考勤状态**：出勤、缺勤、迟到、早退、请假
- **考勤统计**：按日/周/月统计，支持导出 Excel
- **考勤历史**：完整的考勤记录追溯

### 📝 请假管理
- **请假类型**：事假、病假、公假等（可自定义）
- **性别限制**：支持特定假期仅限女生申请（如生理假）
- **附件上传**：支持请假证明图片上传
- **审批流程**：教师在线审批

### 📱 微信通知
- **公众号推送**：考勤结果实时通知家长
- **双模式支持**：
  - 模板消息（传统公众号）
  - 订阅消息（小程序）

### 📊 数据分析
- 班级出勤率统计
- 学生个人考勤报表
- 时间段对比分析
- 数据可视化图表

---

## 🏗️ 系统架构

```
┌─────────────────────────────────────────────────────────────┐
│                        前端 (React)                          │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐           │
│  │ 登录页面 │ │ 管理后台 │ │ 教师端  │ │ 学生端  │           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘           │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     API 层 (Laravel)                         │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  认证 │ 用户管理 │ 班级管理 │ 考勤 │ 请假 │ 通知    │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      数据层 (MySQL)                          │
│  用户 │ 学校 │ 班级 │ 学生 │ 考勤记录 │ 请假申请 │ 设置    │
└─────────────────────────────────────────────────────────────┘
```

### 技术栈

| 层级 | 技术选型 |
|------|---------|
| **前端** | React 18 + Vite + TailwindCSS |
| **后端** | Laravel 11 + PHP 8.2+ |
| **数据库** | MySQL 8.0 / MariaDB 10.5+ |
| **认证** | Laravel Sanctum (SPA Token) |
| **文件存储** | 本地存储 / 可扩展云存储 |

### 目录结构

```
├── app/
│   ├── Http/Controllers/     # 控制器
│   │   ├── Api/             # API 控制器
│   │   └── InstallController.php  # 安装向导
│   ├── Models/              # 数据模型
│   └── Services/            # 服务类
├── database/
│   ├── migrations/          # 数据库迁移
│   └── seeders/             # 数据填充
├── resources/
│   └── js/                  # React 前端
│       ├── components/      # 通用组件
│       ├── pages/           # 页面组件
│       ├── services/        # API 服务
│       └── store/           # 状态管理
├── routes/
│   ├── api.php              # API 路由
│   └── web.php              # Web 路由
└── storage/
    └── installed            # 安装锁定文件
```

---

## 🚀 快速开始

### 环境要求

| 组件 | 版本要求 |
|------|---------|
| PHP | 8.2+ |
| MySQL | 5.7+ / 8.0+ |
| Nginx | 1.18+ |

### 安装方式

#### 方式一：发布包安装（推荐）

1. 下载最新发布包 `smartcampus-x.x.x.zip`
2. 上传到服务器并解压
3. 配置网站运行目录指向 `/public`
4. 访问 `https://你的域名/install` 完成安装向导

详细步骤请参考 [安装教程](install_bt.md)

#### 方式二：源码安装（开发者）

```bash
# 克隆代码
git clone https://github.com/your-repo/smartcampus.git
cd smartcampus

# 安装依赖
composer install
npm install

# 配置环境
cp .env.example .env
php artisan key:generate

# 编辑 .env 配置数据库

# 初始化数据库
php artisan migrate:fresh --seed

# 编译前端
npm run build

# 标记已安装
echo "$(date)" > storage/installed
```

### 默认账号

安装完成后，使用安装向导中设置的管理员账号登录。

---

## 📱 使用说明

### 管理员操作

1. **登录系统** → 使用管理员邮箱登录
2. **创建班级** → 学校管理 → 班级管理 → 添加班级
3. **导入学生** → 班级详情 → 批量导入学生
4. **设置考勤员** → 班级详情 → 指定班级考勤员
5. **配置请假类型** → 系统设置 → 请假类型管理

### 教师操作

1. **查看班级** → 我的班级列表
2. **发起点名** → 选择班级 → 开始点名
3. **审批请假** → 待审批列表 → 审批操作
4. **查看统计** → 考勤统计报表

### 学生操作

1. **查看考勤** → 我的考勤记录
2. **提交请假** → 申请请假 → 填写信息 → 提交
3. **查看结果** → 请假记录 → 查看审批状态

### 考勤员操作

1. **发起点名** → 选择班级 → 开始点名
2. **批量点名** → 一键标记全部出勤/缺勤
3. **完成点名** → 确认提交

---

## 🔧 配置说明

### 环境变量 (.env)

```env
# 应用配置
APP_NAME="智慧校园考勤系统"
APP_URL=https://your-domain.com

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartcampus
DB_USERNAME=root
DB_PASSWORD=

# 微信公众号配置（可选）
WECHAT_APPID=
WECHAT_SECRET=
WECHAT_TOKEN=
WECHAT_AES_KEY=
```

### 微信通知配置

1. 登录微信公众号后台
2. 获取 AppID 和 AppSecret
3. 配置服务器 URL 和 Token
4. 在系统后台填入配置

---

## 📦 发布包构建

```bash
# 构建发布包
./build-release.sh 1.0.0

# 输出位置
releases/smartcampus-1.0.0.zip
```

---

## 🤝 贡献指南

欢迎提交 Issue 和 Pull Request！

1. Fork 项目
2. 创建功能分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 提交 Pull Request

---

## 📄 开源协议

本项目采用 [MIT License](LICENSE) 开源协议。

---

## 📞 联系我们

- **问题反馈**：提交 [Issue](../../issues)
- **功能建议**：提交 [Discussion](../../discussions)

---

<p align="center">Made with ❤️ for Education</p>
