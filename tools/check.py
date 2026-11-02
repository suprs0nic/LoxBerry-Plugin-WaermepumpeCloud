"""Run hardware-free checks. Requires PHP >= 7.4 and Python >= 3.9."""
import argparse
import shutil
import subprocess
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
for test in ('tests/onecta.php', 'tests/integration.php'):
    subprocess.run([args.php, test], check=True, cwd=ROOT)
subprocess.run([args.php, 'bin/befehlsbruecke.php', '--selbsttest'], check=True, cwd=ROOT)
print('All offline checks passed', flush=True)
