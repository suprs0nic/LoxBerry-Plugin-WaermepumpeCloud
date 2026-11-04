"""Validate actual HTTP token guard/cache endpoint and both exported XML files locally."""
import os
from pathlib import Path
import socket
import shutil
import subprocess
import sys
from contextlib import contextmanager
from uuid import uuid4
import time
from urllib.error import HTTPError, URLError
from urllib.request import urlopen
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
runtime = root / 'tests' / '.runtime'
runtime.mkdir(exist_ok=True)
assert runtime.resolve().is_relative_to(root.resolve())
@contextmanager
def fixture_directory():
    # Default workspace ACLs; Python 3.14's Windows mode-0700 tempfile ACL can
    # exclude the restricted test process from its own temporary directory.
    path = runtime / ('daikin-http-' + uuid4().hex)
    path.mkdir()
    try:
        yield path
    finally:
        assert path.resolve().is_relative_to(runtime.resolve())
        shutil.rmtree(path)

with fixture_directory() as folder:
    target = Path(folder)
    assert target.resolve().is_relative_to(runtime.resolve())
    for name in ('config/plugins', 'data/plugins', 'config/system', 'xml'):
        (target / name).mkdir(parents=True, exist_ok=True)
    (target / 'config/system/general.json').write_text('{}')
    env = dict(os.environ, LBHOMEDIR=str(target), LBPPLUGINDIR='fixture', DAIKIN_TEST_XMLDIR=str(target / 'xml'))
    subprocess.run([sys.argv[1], 'tests/daikin_http_fixture.php'], cwd=root, env=env, check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen([sys.argv[1], '-S', f'127.0.0.1:{port}', '-t', 'webfrontend/html'],
                              cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    def get(query):
        try:
            with urlopen(f'http://127.0.0.1:{port}/index.php?{query}', timeout=2) as response:
                return response.status, response.read().decode('utf-8')
        except HTTPError as error:
            return error.code, error.read().decode('utf-8')
    checks = 0
    def check(ok, message):
        global checks
        checks += 1
        if not ok:
            raise AssertionError(message)
    try:
        for attempt in range(50):
            try:
                status, body = get('aktion=daikin')
                break
            except URLError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Local PHP server did not start')
        check(status == 403 and 'TOKEN' in body, 'Missing token rejected')
        status, body = get('aktion=daikin&token=wrong')
        check(status == 403, 'Wrong token rejected')
        status, body = get('aktion=daikin&token=' + 'a' * 32)
        check(status == 200 and body.startswith('DAIKIN;OK=1;'), 'Actual endpoint delivers cached data')
        for filename in ('VI_Daikin.xml', 'VI_Daikin_Metadaten.xml'):
            tree = ET.parse(target / 'xml' / filename).getroot()
            check(tree.tag == 'VirtualInHttp', 'Same input XML root as Loxone export')
            commands = tree.findall('VirtualInHttpCmd')
            check(len(commands) >= 127, 'Complete input template')
            for command in commands:
                marker = command.attrib['Check'][2:-4]
                check(marker in body, 'XML check matches real endpoint')
        # Only test-created snapshot is removed; cache endpoint must not fetch replacement data.
        stand = next(target.glob('data/plugins/**/stand.json'))
        stand.unlink()
        status, body = get('aktion=daikin&token=' + 'a' * 32)
        check(status == 503 and ';OK=0;' in body, 'No cached data is unavailable, no cloud refresh')
    finally:
        server.terminate()
        server.wait(timeout=5)
print(f'Daikin HTTP/XML: {checks} checks, 0 failures (loopback only)')
