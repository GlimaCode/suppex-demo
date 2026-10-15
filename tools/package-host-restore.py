"""Build a files-only cPanel restore from a verified account backup.

Never extracts account configuration, SQL, mail, receipts or runtime uploads.
ZIP members are relative to public_html, with Unix 0644/0755 permissions.
"""
import argparse
import gzip
import hashlib
import json
from pathlib import Path, PurePosixPath
import tarfile
import time
import zipfile

ROOT = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--backup', required=True, type=Path)
parser.add_argument('--name', required=True)
args = parser.parse_args()
if Path(args.name).name != args.name or not args.name.endswith('.zip'):
    raise SystemExit('Use a ZIP filename without directories')

extensions = {'.php', '.html', '.js', '.css', '.json', '.md', '.txt', '.xml', '.woff', '.woff2',
              '.ttf', '.otf', '.svg', '.webp', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.avif', '.glb', '.gltf', '.bin'}
blocked = {'.git', '.claude', '.codex', 'tests', 'tools', 'node_modules', 'suppex-private', 'receipts', 'products-to-import'}
def allowed(name):
    p = PurePosixPath(name)
    if not p.parts or p.is_absolute() or '..' in p.parts or '\\' in name: return False
    if any(part in blocked for part in p.parts): return False
    if p.name in {'suppex-config.php', 'config.sample.php', 'setup.php', 'admin-reset.php'}: return False
    if p.name.startswith('probe-'): return False
    if name == 'uploads/.htaccess': return True
    if p.parts[0] in {'admin', 'api', 'lib', 'partials', 'assets', 'content'}:
        return p.suffix.lower() in extensions or p.name == '.htaccess'
    if name == 'db/catalog-photos.json': return True
    return len(p.parts) == 1 and (name in {'.htaccess', '404.html', 'index.html', 'product.html', 'BingSiteAuth.xml', 'robots.txt'}
                                 or p.suffix.lower() == '.php' or p.name.startswith('google') and p.suffix == '.html')

source = args.backup.absolute()
before = source.stat()
files = {}; prefixes = set()
with gzip.open(source, 'rb') as gz:
    with tarfile.open(fileobj=gz, mode='r|') as tar:
        for member in tar:
            p = PurePosixPath(member.name)
            if 'public_html' not in p.parts: continue
            index = p.parts.index('public_html')
            relative = '/'.join(p.parts[index + 1:])
            if not allowed(relative): continue
            if not member.isfile():
                if not member.isdir(): raise SystemExit('Runtime link requires review: ' + relative)
                continue
            prefixes.add('/'.join(p.parts[:index + 1]))
            if len(prefixes) != 1 or relative in files: raise SystemExit('Ambiguous or duplicate public root')
            if member.size > 64 * 1024 * 1024: raise SystemExit('Unexpected large runtime file: ' + relative)
            body = tar.extractfile(member).read()
            if len(body) != member.size: raise SystemExit('Truncated member: ' + relative)
            files[relative] = body
    while gz.read(1024 * 1024): pass  # Verify gzip trailer and CRC.
after = source.stat()
if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
    raise SystemExit('Account backup changed during reading')
for required in ['index.php', 'index.html', 'lib/db.php', 'lib/bootstrap.php', 'assets/css/site.css', 'admin/assets/admin.css', '.htaccess']:
    if required not in files: raise SystemExit('Required application file missing: ' + required)

dirs = sorted({str(parent) + '/' for name in files for parent in PurePosixPath(name).parents if str(parent) != '.'})
target = ROOT / args.name
timestamp = time.localtime()[:6]
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for name in dirs + sorted(files):
        info = zipfile.ZipInfo(name, date_time=timestamp)
        info.create_system = 3
        info.external_attr = ((0o40755 if name.endswith('/') else 0o100644) << 16) | (0x10 if name.endswith('/') else 0)
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, b'' if name.endswith('/') else files[name])
with zipfile.ZipFile(target) as z:
    if z.testzip(): raise SystemExit('ZIP CRC failed')
    for info in z.infolist():
        if info.create_system != 3 or (info.external_attr >> 16) & 0o777 != (0o755 if info.is_dir() else 0o644):
            raise SystemExit('Unexpected ZIP permissions')
        if not info.is_dir() and z.read(info) != files[info.filename]: raise SystemExit('ZIP source mismatch')

report = {'zip': str(target), 'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
          'sourceBackup': source.name, 'sourceBytes': before.st_size, 'gzipCRC': 'passed',
          'files': len(files), 'directories': len(dirs), 'fileMode': '0644', 'directoryMode': '0755',
          'members': [{'path': name, 'bytes': len(body), 'sha256': hashlib.sha256(body).hexdigest()} for name, body in sorted(files.items())]}
scratch = ROOT / 'tests/tmp/launch-audit'
scratch.mkdir(parents=True, exist_ok=True)
(scratch / 'host-restore-package.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps({k: v for k, v in report.items() if k != 'members'}, indent=2))
