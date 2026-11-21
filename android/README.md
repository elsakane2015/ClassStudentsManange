# SmartCampus Android 原生客户端

本目录为智慧校园考勤系统（SmartCampus）的纯原生 Android 客户端，采用 **Kotlin 2.0 + Jetpack Compose + Material Design 3 + Room + DataStore + OkHttp + Coil** 现代官方架构。

## 1. 打开与编译打包

- **使用 Android Studio**：直接用 Android Studio 打开 `android/` 目录，同步 Gradle 后点击运行即可。
- **使用命令行构建 APK**：
  ```bash
  cd android
  ./gradlew assembleDebug
  ```
  构建产物位于 `android/app/build/outputs/apk/debug/app-debug.apk`。

## 2. 功能与架构亮点

- **动态多校服务器**：不写死任何固定域名，首次打开自动探测 `/api/school/info` 识别学校名称，支持多学校站点历史记录与一键切换。
- **全角色高频业务闭环**：
  - 学生端：考勤统计 + 原生月历 + 节次智能合并明细 + 动态请假申请（相册 Photo Picker 与现场拍照自动压缩直传）+ 请假历史撤销 + 班级考勤员工作台。
  - 课堂点名：发起点名、1~4 列自适应网格、原生触觉震动反馈、**Room 本地断网防丢草稿**、一键全勤与完成后单人修改。
  - 夜自习与停宿：夜自习节次/日期巡查、快速彩色状态点选、暂存/完成/重开、历史查询与临时停宿办理/撤销。
  - 教师/管理看板：多周期统计与明细弹窗、院系→班级→学生三级折叠、手动补录与删除、**Excel 报表下载并通过系统分享面板发送**、请假审批与分项勾选审批。
- **消息推送与通知中心**：
  - 完整支持 Android 13+ `POST_NOTIFICATIONS` 动态权限、通知渠道、设备注册 `/api/devices/register`、站内消息中心与点击通知 Deep Link 路由。
  - 日后接入 Firebase FCM 或国内厂商推送 SDK 时，在服务端 `.env` 填入 `FCM_ENABLED=true` 及凭据路径（或配置 `ANDROID_PUSH_WEBHOOK_URL`），客户端收到推送 Token 后调用 `SmartCampusApp.container.pushManager.onNewPushToken(token)` 即可零业务改动启用锁屏弹窗推送。
