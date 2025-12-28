# 项目任务清单：移动优先的请假与考勤系统

## ⚠️ 开发规范 (Standards)
- **UI 语言**：所有前端界面（按钮、提示、文本）必须使用 **简体中文**。
- **UI Language**: All frontend interfaces MUST use Simplified Chinese.

## 第一阶段：项目初始化 (Local Setup)
- [x] **代码库设置**
    - [x] 初始化 Laravel 11 骨架 (仅代码结构)
    - [x] 初始化 React + Vite 环境
    - [x] 配置 `vite.config.js` 输出目录及其与 Laravel 的集成
    - [x] 创建 `.env.production.example` (供服务器参考)
    - [x] 创建 `Layout` 组件与路由配置
- [x] **依赖安装**
    - [x] 本地安装 Composer 依赖
    - [x] 本地安装 NPM 依赖
    - [x] 安装 Tailwind, Headless UI, Excel 等库

## 第二阶段：数据库与逻辑开发 (Coding)
- [x] **核心架构迁移**
    - [x] 创建 `users`, `schools`, `grades`, `classes`, `students`, `class_periods` 表 Migrations
- [x] **业务架构迁移**
    - [x] 创建 `leave_requests`, `attendance_records` 表 Migrations
- [x] **模型与填充**
    - [x] 定义 Eloquent 关联
    - [x] 编写 Seeders
    - [x] 导入功能 (ImportService) 与 冲突检测 (ConflictService)
- [x] **自动生成代码**
    - [x] 使用 Artisan 命令生成 Controller/Model 骨架

## 第三阶段：前端页面开发 (React)
- [x] **组件开发**
    - [x] 封装 API 请求客户端 (Axios 拦截器)
    - [x] 开发公共 UI 组件 (Layout, Button, Form 等)
- [x] **业务页面**
    - [x] 登录页 (`/login`)
    - [x] 学生端：日历仪表盘 (`/student/dashboard`)
    - [x] 学生端：请假申请页 (`/student/request`)
    - [x] 教师端：班级概览 (`/teacher/dashboard`)
    - [x] 教师端：请假审批 (`/teacher/approvals`)
    - [x] 教师端：学生导入 (`/teacher/import`)
- [x] **构建测试**
    - [x] 运行 `npm run build` 确保无编译错误

## 第四阶段：首轮上传与部署 (Deploy V1)
- [x] **认证模块**
    - [x] 配置 Sanctum
    - [x] 实现 API: 登录、获取用户信息
- [x] **学生管理与导入**
    - [x] 安装 `maatwebsite/excel`
    - [x] 实现 API: 下载导入模版
    - [x] 实现 API: 批量导入学生
- [x] **请假业务模块**
    - [x] 实现 `ConflictCheckService`
    - [x] 实现 API: 提交请假、请假列表
- [x] **审批与考勤模块**
    - [x] 实现 API: 审批/驳回
    - [x] 实现 Listener: 审批通过 -> 生成考勤记录
- [x] **服务器部署**
    - [x] 解决 PHP 8.2 依赖与被禁函数问题
    - [x] 修复 Migration 执行顺序问题 (`students` 依赖 `classes`, `classes` 依赖 `grades`)
    - [x] 成功运行 `migrate:fresh --seed`
    - [x] 部署前端资源 (`public/build`)

## 第五阶段：迭代与完善 (Completion)
- [x] **缺失页面开发**
    - [x] 学生端：我的请假记录 (`/student/history`)
    - [x] 教师端：学生列表管理 (`/teacher/students`) - 真实 API 对接
- [x] **功能增强与 Bug 修复**
    - [x] 完善导入导出的错误提示与真实下载功能
    - [x] 修复 axios `baseURL` 导致的 API 404 问题
    - [x] 修复 `useAuthStore` 导致的无限循环 (Error #185)
    - [x] 修复 SPA 路由刷新 404 问题 (添加 Laravel Catch-all Route)
- [ ] **最终交付**
    - [ ] 清理无用文件
    - [ ] 编写部署操作文档 (`DEPLOY.md`)
