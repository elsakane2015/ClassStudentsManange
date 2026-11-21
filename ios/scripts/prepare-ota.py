#!/usr/bin/env python3
"""Package a signed SmartCampus .app archive for private Tailscale HTTPS OTA installation."""
import hashlib
import html
from pathlib import Path
import plistlib
import shutil
import subprocess
import sys
import tempfile
from urllib.parse import quote, urlsplit
import zipfile

if len(sys.argv) != 4:
    raise SystemExit("Usage: prepare-ota.py <SmartCampus.app> <output_dir> <https://host.ts.net/path>")

app = Path(sys.argv[1]).resolve()
output = Path(sys.argv[2]).resolve()
base = sys.argv[3].rstrip('/') + '/'

url = urlsplit(base)
if url.scheme != 'https' or not (url.hostname or '').endswith('.ts.net') or url.username or url.password or url.query or url.fragment:
    raise SystemExit('An HTTPS Tailscale (.ts.net) URL without credentials is required.')

info = plistlib.loads((app / 'Info.plist').read_bytes())

# Verify embedded provisioning profile & code signature
embedded_profile = app / 'embedded.mobileprovision'
if not embedded_profile.exists():
    raise SystemExit('Missing embedded.mobileprovision in signed app.')
subprocess.run(['codesign', '--verify', '--deep', '--strict', str(app)], check=True)

if output.exists():
    shutil.rmtree(output)
output.mkdir(parents=True, exist_ok=True)

version = info['CFBundleShortVersionString']
build = info['CFBundleVersion']
bundle_id = info['CFBundleIdentifier']
display_name = info.get('CFBundleDisplayName') or info.get('CFBundleName') or '智慧校园'
filename = f'SmartCampus-{version}-{build}.ipa'

with tempfile.TemporaryDirectory(prefix='smartcampus-ota-') as staging:
    payload = Path(staging) / 'Payload'
    payload.mkdir()
    shutil.copytree(app, payload / app.name, symlinks=True)
    subprocess.run(
        ['ditto', '-c', '-k', '--norsrc', '--noextattr', '--noqtn', '--keepParent', str(payload), str(output / filename)],
        check=True,
    )

with zipfile.ZipFile(output / filename) as archive:
    if any(name.lower().endswith(('.p8', '.p12', '.key', '.env')) for name in archive.namelist()):
        raise SystemExit('Unexpected secret file in IPA package.')
    if any(part.startswith('._') or part == '__MACOSX' for name in archive.namelist() for part in Path(name).parts):
        raise SystemExit('Unexpected AppleDouble metadata in IPA package.')
    with tempfile.TemporaryDirectory(prefix='smartcampus-ipa-verify-') as verification:
        archive.extractall(verification)
        subprocess.run(['codesign', '--verify', '--deep', '--strict', str(Path(verification) / 'Payload' / app.name)], check=True)

manifest = {
    'items': [
        {
            'assets': [
                {'kind': 'software-package', 'url': base + filename},
            ],
            'metadata': {
                'bundle-identifier': bundle_id,
                'bundle-version': build,
                'kind': 'software',
                'title': display_name,
            },
        }
    ]
}
manifest_name = f'manifest-{build}.plist'
with (output / manifest_name).open('wb') as f:
    plistlib.dump(manifest, f)

install_url = 'itms-services://?action=download-manifest&url=' + quote(base + manifest_name, safe='')
page = f'''<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装 {html.escape(display_name)}</title>
<style>
  body{{font:17px -apple-system,BlinkMacSystemFont,"SF Pro Text",sans-serif;background:#f3f5f9;color:#17212d;margin:0;padding:42px 20px}}
  main{{max-width:460px;margin:auto;background:#fff;border-radius:24px;padding:30px;box-shadow:0 12px 32px rgba(23,33,45,.08)}}
  .badge{{display:inline-block;background:#eef2ff;color:#4f46e5;font-size:13px;font-weight:600;padding:4px 10px;border-radius:999px;margin-bottom:12px}}
  h1{{margin:0 0 8px;font-size:26px}}
  p{{line-height:1.65;color:#536170;margin:10px 0}}
  a.install{{display:block;text-align:center;background:#4f46e5;color:#fff;font-weight:600;text-decoration:none;padding:16px;border-radius:14px;margin:24px 0}}
  small{{display:block;color:#687787;line-height:1.6}}
</style>
</head>
<body>
<main>
  <span class="badge">Tailscale 私有无线分发</span>
  <h1>{html.escape(display_name)}</h1>
  <p>版本 {html.escape(version)} · 构建 {html.escape(build)}<br>智慧校园考勤系统纯原生 iOS 客户端（支持动态配置学校服务器地址）。</p>
  <a class="install" href="{html.escape(install_url, quote=True)}">一键安装到 iPhone</a>
  <p>请使用 <strong>Safari</strong> 浏览器打开本页面，确保手机已连接 <strong>Tailscale</strong>，点击上方按钮后在系统弹窗中确认「安装」。</p>
  <small>Bundle ID: {html.escape(bundle_id)}<br>提示：如首次安装开发测试包，请确认 iPhone「设置 → 隐私与安全性 → 开发者模式」已开启。</small>
</main>
</body>
</html>
'''
(output / 'index.html').write_text(page, encoding='utf-8')

digest = hashlib.sha256((output / filename).read_bytes()).hexdigest()
(output / 'SHA256SUMS').write_text(f'{digest}  {filename}\n', encoding='utf-8')
print(f'OTA package prepared: {filename}; manifest={manifest_name}; SHA256={digest}')
