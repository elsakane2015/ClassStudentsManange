#!/usr/bin/env python3
"""Loopback-only OTA HTTP server behind Tailscale Serve (/smartcampus)."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import sys
from urllib.parse import urlsplit

if len(sys.argv) < 2:
    raise SystemExit("Usage: serve-ota.py <site_dir> [port]")

root = Path(sys.argv[1]).resolve()
port = int(sys.argv[2]) if len(sys.argv) >= 3 else 8850


def allowed_paths() -> set[str]:
    return {'/', '/index.html', '/SHA256SUMS'} | {
        '/' + p.name
        for pattern in ('*.ipa', '*.apk', 'manifest*.plist')
        for p in root.glob(pattern)
    }


class Handler(SimpleHTTPRequestHandler):
    extensions_map = {
        **SimpleHTTPRequestHandler.extensions_map,
        '.plist': 'application/xml',
        '.ipa': 'application/octet-stream',
        '.apk': 'application/vnd.android.package-archive',
    }

    def do_GET(self):
        if urlsplit(self.path).path not in allowed_paths():
            return self.send_error(404)
        return super().do_GET()

    def do_HEAD(self):
        if urlsplit(self.path).path not in allowed_paths():
            return self.send_error(404)
        return super().do_HEAD()

    def end_headers(self):
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        return super().end_headers()

    def log_message(self, format, *args):
        sys.stdout.write(f"[SmartCampus OTA] {self.address_string()} - {format % args}\n")
        sys.stdout.flush()


if __name__ == '__main__':
    print(f'Serving SmartCampus OTA from {root} on http://127.0.0.1:{port}', flush=True)
    ThreadingHTTPServer(('127.0.0.1', port), partial(Handler, directory=str(root))).serve_forever()
