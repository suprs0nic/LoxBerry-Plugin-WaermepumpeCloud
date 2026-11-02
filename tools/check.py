"""Run hardware-free checks. Requires PHP >= 7.4 and Python >= 3.9."""
import argparse
import shutil
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default=shutil.which('php'))
args = parser.parse_args()
if not args.php:
    parser.error('PHP not on PATH; provide --php /path/to/php')
for path in sorted(ROOT.rglob('*.php')):
    if '.runtime' not in path.parts:
        subprocess.run([args.php, '-l', str(path)], check=True, cwd=ROOT, stdout=subprocess.DEVNULL)
print('PHP syntax: all files OK', flush=True)
for test in ('tests/onecta.php', 'tests/energy.php', 'tests/onecta_limits.php', 'tests/integration.php'):
    subprocess.run([args.php, test], check=True, cwd=ROOT)
subprocess.run([args.php, 'bin/befehlsbruecke.php', '--selbsttest'], check=True, cwd=ROOT)


class HeaderFixture(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(429)
        self.send_header('rEtRy-AfTeR', '120')
        self.send_header('X-RateLimit-Remaining-day', '141')
        self.send_header('X-RateLimit-Remaining-minute', '19')
        self.send_header('Set-Cookie', 'test-only-not-a-real-secret')
        self.end_headers()
        self.wfile.write(b'{"fixture":true}')

    def log_message(self, *unused):
        pass


with ThreadingHTTPServer(('127.0.0.1', 0), HeaderFixture) as server:
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    url = f'http://127.0.0.1:{server.server_port}/headers'
    try:
        subprocess.run([args.php, '-n', '-d', 'allow_url_fopen=1', 'tests/http_headers.php', url], check=True, cwd=ROOT)
        has_curl = subprocess.check_output([args.php, '-r', 'echo function_exists("curl_init") ? "yes" : "no";'], text=True).strip() == 'yes'
        curl_dll = Path(args.php).resolve().parent / 'ext' / 'php_curl.dll'
        if has_curl:
            subprocess.run([args.php, 'tests/http_headers.php', url], check=True, cwd=ROOT)
        elif curl_dll.is_file():
            subprocess.run([args.php, '-d', f'extension_dir={curl_dll.parent}', '-d', 'extension=php_curl.dll', 'tests/http_headers.php', url], check=True, cwd=ROOT)
        else:
            print('curl transport not installed; stream transport checked', flush=True)
    finally:
        server.shutdown()
        worker.join(timeout=5)
print('All offline checks passed', flush=True)
