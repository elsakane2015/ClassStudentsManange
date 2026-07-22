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

Microsoft 邮箱使用 OAuth2 和 Microsoft Graph，不保存邮箱密码。系统管理员需要先在 Microsoft Entra 注册应用，并为应用配置：

- Microsoft Graph delegated permission：`User.Read`
- Microsoft Graph delegated permission：`Mail.Send`
- 允许公共 Microsoft 账号和组织账号登录
- Web Redirect URI：系统设置“Microsoft 邮箱”中显示的回调地址

然后进入“系统设置 -> Microsoft 邮箱”，填写 Application (client) ID、Client Secret 和 Web Redirect URI，启用后保存。配置生效后，班主任端的 Microsoft Outlook/Hotmail 选项会立即解除禁用。

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
