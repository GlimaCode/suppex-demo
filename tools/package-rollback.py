"""Package the pre-release Git blobs with explicit Unix permissions for cPanel."""
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import subprocess
import tarfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASE = '780b529'
NAME = 'suppex-rollback-2026-10-15.zip'

def runtime(name):
    p = PurePosixPath(name)
    allowed = p.parts[0] in {'admin', 'api', 'lib', 'partials', 'content', 'assets'}
    allowed |= name in {'db/schema.sql', 'db/catalog-photos.json', 'uploads/.htaccess', '.htaccess', '404.html', 'index.html', 'product.html'}
    allowed |= len(p.parts) == 1 and (name.endswith('.php') or name.startswith('google') and name.endswith('.html'))
    return allowed and name not in {'setup.php', 'admin-reset.php', 'config.sample.php', 'suppex-config.php'} and not name.startswith('assets/images/products/verified-20261014/')

data = {}
archive = subprocess.check_output(['git', 'archive', '--format=tar', BASE], cwd=ROOT)
with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
    for entry in tar:
        if entry.isfile() and runtime(entry.name):
            data[entry.name] = tar.extractfile(entry).read()

# The historical Git blob has CRLF; build-css.php produces the identical CSS
# with LF. Use the generated form so the shipped bundle matches its sources.
data['assets/css/site.css'] = data['assets/css/site.css'].replace(b'\r\n', b'\n')

# This manifest contains only reviewed source paths and hashes, never shop data.
manifest = '<?php\nreturn ' + '[\n' + ''.join(
    '    ' + repr(name) + ' => ' + repr(hashlib.sha256(body).hexdigest()) + ',\n'
    for name, body in sorted(data.items())
) + '];\n'
(ROOT / 'lib/rollback_manifest.php').write_text(manifest, encoding='utf-8', newline='\n')
for name in ['lib/rollback_manifest.php', 'lib/release_rollback.php', 'admin/rollback-release.php']:
    data[name] = (ROOT / name).read_bytes()

directories = sorted({str(parent) + '/' for name in data for parent in PurePosixPath(name).parents if str(parent) != '.'})
target = ROOT / NAME
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for name in directories + sorted(data):
        info = zipfile.ZipInfo(name)
        info.create_system = 3
        info.external_attr = ((0o40755 if name.endswith('/') else 0o100644) << 16) | (0x10 if name.endswith('/') else 0)
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, b'' if name.endswith('/') else data[name])

with zipfile.ZipFile(target) as z:
    assert z.testzip() is None
    for info in z.infolist():
        p = PurePosixPath(info.filename)
        assert not p.is_absolute() and '..' not in p.parts and '\\' not in info.filename
        assert info.create_system == 3
        assert (info.external_attr >> 16) & 0o777 == (0o755 if info.is_dir() else 0o644)
        if not info.is_dir():
            assert z.read(info) == data[info.filename]
    assert '.htaccess' in z.namelist() and 'admin/assets/admin.css' in z.namelist() and 'assets/css/site.css' in z.namelist()
    assert not any(name.startswith(('tests/', 'tools/', '.git/', '.claude/', '.codex/')) for name in z.namelist())
    assert not any('config' in name.lower() and name.endswith('.php') for name in z.namelist())

report = {'zip': str(target), 'baseline': BASE, 'baselineFiles': len(data) - 3, 'helperFiles': 3,
          'files': len(data), 'directories': len(directories), 'bytes': target.stat().st_size,
          'sha256': hashlib.sha256(target.read_bytes()).hexdigest(), 'filesMode': '0644', 'directoriesMode': '0755'}
(ROOT / 'tests/tmp/launch-audit/rollback-package.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
