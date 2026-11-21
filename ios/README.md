# SmartCampus iOS 原生客户端

本目录为智慧校园考勤系统（SmartCampus）的纯原生 iPhone / iPad 客户端，采用 **Swift + SwiftUI + SwiftData + URLSession async/await** 架构，零第三方依赖。

## 1. 打开与真机运行（使用您的 Apple 开发者账号）

1. 双击打开 `ios/SmartCampus.xcodeproj`（或终端执行 `open ios/SmartCampus.xcodeproj`）。
2. 在左侧项目导航选择 **SmartCampus** → **Signing & Capabilities**：
   - 勾选 **Automatically manage signing**。
   - **Team** 选择您已登录的 Apple Developer 账号。
   - 将 **Bundle Identifier** 改为您的专属标识（例如 `com.yourorg.smartcampus`）。
3. 连接 iPhone 真机或选择 iOS 模拟器，按 `⌘ + R` 运行。

> 若修改了源码目录结构，可在 `ios/` 目录执行 `xcodegen generate` 自动重新生成 `SmartCampus.xcodeproj`。

## 2. 首次启动连接学校服务器

- App 未写死任何域名，首次打开会自动进入「配置学校服务器」页面。
- 输入您部署的 SmartCampus 后端地址（如 `attendance.yourschool.edu.cn` 或局域网 `192.168.x.x:8080`），点击「验证并连接」将自动探测 `/api/school/info` 并识别学校名称。
- 支持保存多所学校站点，在登录页或「我的 → 当前学校」中随时切换。

## 3. 启用锁屏推送（APNs）指南（日后拿到证书时配置）

App 端与后端均已按最终生产架构内置完整 APNs 推送通道，未配证书时自动以「站内消息通知中心」降级运行；日后开启锁屏推送只需在服务器 `.env` 填入：

```env
MOBILE_PUSH_ENABLED=true
APNS_ENABLED=true
APNS_KEY_ID=你的10位KeyID
APNS_TEAM_ID=你的AppleTeamID
APNS_BUNDLE_ID=与Xcode一致的BundleID
APNS_PRIVATE_KEY_PATH=/绝对路径/AuthKey_XXXXXXXXXX.p8
APNS_SANDBOX=true   # 上架 App Store / TestFlight 时改为 false
```

并在服务器上运行数据库迁移：`php artisan migrate`，无需修改任何代码即可立即启用苹果原生锁屏弹窗推送。
