"""Build and verify a LoxBerry test ZIP using only the plugin installation tree."""
import argparse
import hashlib
import re
import stat
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIRS = ('bin', 'cron', 'dpkg', 'icons', 'templates', 'uninstall', 'webfrontend')
FILES = ('plugin.cfg', 'release.cfg', 'prerelease.cfg', 'preinstall.sh', 'postinstall.sh',
         'preupgrade.sh', 'postupgrade.sh', 'LICENSE', 'README.md', 'CHANGELOG.md',
         'config/mqtt_subscriptions.cfg')
REQUIRED = ('plugin.cfg', 'preinstall.sh', 'postinstall.sh', 'preupgrade.sh', 'postupgrade.sh',
            'bin/wp_abruf.php', 'bin/befehlsbruecke.sh', 'bin/befehle_zuordnung.php', 'cron/cron.01min',
            'webfrontend/html/index.php', 'webfrontend/htmlauth/index.php',
            'webfrontend/htmlauth/wp_lib.php', 'webfrontend/htmlauth/wp_onecta_extended.php',
            'webfrontend/htmlauth/wp_onecta_limits.php',
            'webfrontend/htmlauth/wp_onecta_energy.php',
            'webfrontend/htmlauth/wp_onecta_extended_ui.php', 'config/mqtt_subscriptions.cfg')


def verify(path):
    with zipfile.ZipFile(path) as archive:
        assert archive.testzip() is None, 'ZIP CRC error'
        names = archive.namelist()
        roots = {n.split('/')[0] for n in names}
        assert len(roots) == 1, 'Expected one top-level directory'
        prefix = roots.pop() + '/'
        members = {n[len(prefix):]: n for n in names}
        for name in REQUIRED:
            assert name in members, f'Missing installation file: {name}'
        for rel, name in members.items():
            assert '..' not in Path(rel).parts and not rel.startswith('/'), 'Unsafe ZIP path'
            assert not any(x in rel.lower() for x in ('geheim.json', 'token_onecta', 'response_179', '.git/', '.runtime/')), f'Private file: {rel}'
            data = archive.read(name)
            if rel.endswith(('.sh', '.php', '.cfg')) or rel.startswith('cron/'):
                assert b'\r\n' not in data and not data.startswith(b'\xef\xbb\xbf'), f'Line endings/BOM: {rel}'
            if rel.endswith('.sh') or rel.startswith(('cron/', 'uninstall/')):
                mode = archive.getinfo(name).external_attr >> 16
                assert mode & stat.S_IXUSR, f'Executable bit missing: {rel}'
        version = re.search(rb'^VERSION=([0-9]+\.[0-9]+\.[0-9]+)\r?$', archive.read(members['plugin.cfg']), re.M)
        assert version and prefix == 'waermepumpe-' + version[1].decode('ascii') + '/', 'Archive root/version mismatch'
        assert b'AUTOMATIC_UPDATES=false' in archive.read(members['plugin.cfg'])
        print(f'Package verified: {len(names)} files, structure/CRC/LF/permissions/private-file exclusions OK')


def build(output=None):
    version = re.search(r'^VERSION=(.+)$', (ROOT / 'plugin.cfg').read_text(encoding='utf-8'), re.M)[1]
    output = Path(output) if output else ROOT / 'dist' / f'WaermepumpeCloud-{version}-onecta-test.zip'
    output.parent.mkdir(parents=True, exist_ok=True)
    paths = [ROOT / name for name in FILES]
    for folder in (*DIRS, 'docs'):
        paths.extend(p for p in (ROOT / folder).rglob('*') if p.is_file())
    prefix = f'waermepumpe-{version}/'
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(paths):
            rel = path.relative_to(ROOT).as_posix()
            data = path.read_bytes()
            if path.suffix in ('.php', '.sh', '.cfg', '.md', '.json', '.txt') or rel.startswith(('cron/', 'uninstall/')):
                data = data.replace(b'\r\n', b'\n')
            info = zipfile.ZipInfo(prefix + rel, (2026, 11, 2, 0, 0, 0))
            info.create_system = 3
            executable = path.suffix == '.sh' or rel.startswith(('bin/', 'cron/', 'uninstall/'))
            info.external_attr = (stat.S_IFREG | (0o755 if executable else 0o644)) << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    verify(output)
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix('.zip.sha256').write_text(f'{digest}  {output.name}\n', encoding='ascii')
    print(f'{output}\nSHA256 {digest}')
    return output


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output')
    parser.add_argument('--verify', type=Path)
    args = parser.parse_args()
    verify(args.verify) if args.verify else build(args.output)
