# 班主任个人邮箱配置

家长邮件通知支持两种发送通道：

- 系统 Resend：由学校管理员统一配置，适合作为默认通道。
- 班主任个人邮箱：支持 QQ、网易 163、网易 126 和 Microsoft Outlook/Hotmail。

两种通道共用“系统设置 -> Resend 发送”中维护的家长通知主题和 HTML 模板。班主任只能选择发送通道和通知事件，不能修改系统模板。

## QQ、网易邮箱

班主任在“家长通知 -> 邮件发送账户 -> 个人邮箱”中选择服务商并填写：

1. 完整邮箱地址。
2. 发件人名称。
3. 邮箱服务商生成的客户端授权码。

不要填写邮箱登录密码。保存后必须点击“发送测试邮件”，测试成功后个人邮箱才会用于自动通知。

系统使用固定的服务商配置，不允许用户自定义 SMTP 主机：

| 服务商 | SMTP 主机 | 端口 | 加密 |
| --- | --- | --- | --- |
| QQ | `smtp.qq.com` | `465` | TLS/SSL |
| 网易 163 | `smtp.163.com` | `465` | TLS/SSL |
| 网易 126 | `smtp.126.com` | `465` | TLS/SSL |

授权码需要在邮箱网页版的账号或客户端设置中开启 SMTP 服务后生成。修改授权码或邮箱地址后，系统会取消原有验证状态，需要重新发送测试邮件。

## Microsoft Outlook / Hotmail

Microsoft 邮箱使用 OAuth2 和 Microsoft Graph，不保存邮箱密码。配置分为两个层级：

- 系统管理员配置 Microsoft OAuth 应用身份，包括 Client ID、Client Secret 和回调地址。这里不配置发件邮箱。
- 每位班主任在“家长通知”中登录并授权自己的 Outlook、Hotmail 或 Microsoft 365 邮箱，邮件以该班主任的邮箱身份发出。

### 管理员注册 Microsoft OAuth 应用

1. 登录 [Microsoft Entra 管理中心](https://entra.microsoft.com/)，进入“应用程序 -> 应用注册 -> 新注册”。
2. 输入应用名称，例如“智慧校园家长通知”。
3. “支持的账户类型”选择“任何组织目录中的账户和个人 Microsoft 账户”。这样才能同时支持学校 Microsoft 365 账号以及个人 Outlook、Hotmail 账号。
4. 完成注册后，在应用“概述”中复制 `Application (client) ID`。
5. 进入“证书和密码 -> 客户端密码 -> 新客户端密码”。创建后立即复制客户端密码的“值”；不要复制“机密 ID”，密码值离开页面后不会再次完整显示。
6. 在本系统打开“系统设置 -> Microsoft OAuth”，复制页面中的 Web Redirect URI。
7. 返回 Entra 应用，进入“身份验证 -> 添加平台 -> Web”，粘贴该回调地址。协议、域名、端口和路径必须完全一致，生产环境应使用 HTTPS。
8. 进入“API 权限 -> 添加权限 -> Microsoft Graph -> 委托的权限”，添加 `User.Read` 和 `Mail.Send`。系统在连接时还会申请 `openid`、`profile`、`email` 和 `offline_access`，用于识别邮箱和刷新授权。
9. 回到本系统，填写 Application (client) ID、Client Secret 和 Web Redirect URI，打开启用开关并保存。

配置生效后，班主任端的 Microsoft Outlook/Hotmail 选项会解除禁用。Client ID 和 Client Secret 标识的是系统应用，不是管理员或班主任的邮箱账号和密码。

Microsoft 官方参考：

- [注册 Microsoft identity platform 应用](https://learn.microsoft.com/en-us/graph/auth-register-app-v2)
- [添加 Web Redirect URI](https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-redirect-uri)
- [Microsoft Graph 权限参考](https://learn.microsoft.com/en-us/graph/permissions-reference)

### 班主任连接自己的 Microsoft 邮箱

1. 班主任进入“家长通知 -> 邮件发送账户”，切换到“个人邮箱”。
2. 邮箱服务商选择“Microsoft Outlook / Hotmail”。
3. 点击“连接 Microsoft 邮箱”，在 Microsoft 页面选择自己的邮箱账号并同意授权。
4. 授权成功后会自动返回家长通知页面，并显示已连接的班主任邮箱。
5. 点击“发送测试邮件”确认通道可用，再保存通知规则。

每位班主任只能连接并使用自己的邮箱。系统保存的是加密后的 OAuth Token，不会读取或保存 Microsoft 邮箱密码。

### 常见连接错误

- `AADSTS50011`：Entra 中登记的 Redirect URI 与系统设置中的地址不完全一致。重点检查 `http/https`、域名、端口和路径。
- Client Secret 无效：填写了“机密 ID”而不是客户端密码“值”，或者客户端密码已经过期。
- 个人 Outlook/Hotmail 无法登录：应用的支持账户类型没有包含“个人 Microsoft 账户”。
- 组织账号提示需要管理员批准：所在 Microsoft 365 组织禁止用户自行同意权限，需要该组织的管理员批准应用权限。

也可以选择通过 Dokploy 环境变量预置配置：

```env
MICROSOFT_MAIL_CLIENT_ID=应用客户端ID
MICROSOFT_MAIL_CLIENT_SECRET=应用客户端密钥
MICROSOFT_MAIL_REDIRECT_URI=https://你的域名/api/teacher-email/microsoft/callback
```

环境变量是可选的兼容方式，网页系统设置的配置优先。修改环境变量后需要重新部署应用，或在容器中执行：

```bash
php artisan config:clear
```

配置完成后，班主任点击“连接 Microsoft 邮箱”，在 Microsoft 页面授权后会自动返回家长通知页面。每位班主任只会授权自己的邮箱账号。

## 失败处理

班主任可以选择“个人邮箱发送失败时，使用系统 Resend 重试”。该选项仅在系统 Resend 已正确配置时有效。

- 未开启：个人邮箱失败后记录失败，不会悄悄更换发件人。
- 已开启：个人邮箱失败后使用系统 Resend 再尝试一次。
- 同一考勤事件继续使用原有去重键，不会因切换通道重复发送成功邮件。

## 安全与运维

- SMTP 授权码、Microsoft OAuth Client Secret、access token 和 refresh token 均使用 Laravel 加密存储。
- `APP_KEY` 丢失后将无法解密已有凭据，生产环境必须持久保存同一个 `APP_KEY`。
- API 和页面不会返回授权码或 OAuth Token 明文。
- Laravel 容器需要能够发起到 SMTP `465` 端口及 Microsoft Graph HTTPS `443` 的出站连接，不需要映射或开放入站 SMTP 端口。本功能当前固定使用 `465`，无需额外开放 `587`；如以后切换为 STARTTLS 才需要允许 `587` 出站。
- 个人邮箱通常存在发送频率和每日数量限制，学校级批量通知应优先使用系统 Resend。

部署新版本后需要执行数据库迁移：

```bash
php artisan migrate --force
```
