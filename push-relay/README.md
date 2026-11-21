# 智慧校园 APNs 推送中转服务 (`push-relay/`)

面向多校独立部署的轻量级 Apple APNs (HTTP/2 + ES256) 统一推送中转服务（纯 Go 标准库，零外部依赖，内存占用约 10 MB）。

## 设计要点
- **集中保管 Apple `.p8` 私钥**：各校独立部署的智慧校园后端无需存放开发者 `.p8` 私钥，仅需配置 `APNS_RELAY_URL` 与 `APNS_RELAY_TOKEN`。
- **安全边界**：非 root (`10001:10001`) 运行、`read_only: true`、`cap_drop: [ALL]`、`no-new-privileges:true`，只读挂载 `.p8`，强校验 Bearer Token 与 Bundle ID 白名单。
- **自动区分开发/生产环境**：按设备上报的 `environment`（`development` / `production`）精准投递至 `api.sandbox.push.apple.com` 或 `api.push.apple.com`。

## 接口规范
- `GET /healthz`：健康检查（无需鉴权），返回 `{"status":"ok", ...}`。
- `POST /v1/apns/send`：需 `Authorization: Bearer <RELAY_AUTH_TOKEN>` 与 `Content-Type: application/json`。
  ```json
  {
    "device_token": "64+ hex",
    "bundle_id": "com.litotime.smartcampus",
    "environment": "development",
    "title": "新的请假申请",
    "body": "张三 提交了 病假 申请，请及时审批。",
    "badge": 1,
    "sound": "default",
    "data": { "type": "leave_request_submitted" }
  }
  ```

## Dokploy 部署步骤
1. 在宿主机同步本目录并构建本地镜像：
   ```bash
   docker build -t smartcampus-push-relay:20261121-v1 /opt/smartcampus-push-relay/src
   ```
2. 在 Dokploy UI 新建 Compose 服务（Provider 选择 `Raw`），粘贴 `compose.dokploy.yaml`。
3. 在 Dokploy **Environment** 中配置：
   - `SMARTCAMPUS_RELAY_IMAGE=smartcampus-push-relay:20261121-v1`
   - `APNS_TEAM_ID=HCDD4TQBT8`
   - `APNS_KEY_ID=JF4XKNHTQG`
   - `APNS_KEY_PATH=/opt/hideck-push-relay/secrets/AuthKey_JF4XKNHTQG.p8`
   - `APNS_ALLOWED_BUNDLE_IDS=com.litotime.smartcampus`
   - `RELAY_AUTH_TOKEN=<至少32位随机密钥>`
4. 在 Dokploy **Domains** 中绑定域名 -> Service `relay` -> Port `8092` -> 开启 HTTPS (`Let's Encrypt`)，点击 Deploy 即可。
