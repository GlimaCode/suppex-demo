"""Build a cPanel web-root ZIP from tracked runtime paths plus reviewed assets."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess
import zipfile

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--name', required=True)
args = parser.parse_args()
if Path(args.name).name != args.name or not args.name.endswith('.zip'):
    raise SystemExit('Use a ZIP filename, without directories')
tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode('utf-8').split('\0')
new_assets = ['lib/catalog_readiness.php'] + [p.relative_to(root).as_posix() for p in (root/'assets/images/brands/verified-20261015').glob('*.webp')]
paths = []
for name in sorted(set(tracked + new_assets)):
    if not name: continue
    p = PurePosixPath(name)
    allowed = p.parts[0] in {'admin', 'api', 'lib', 'partials', 'content', 'assets'}
    allowed = allowed or name in {'db/schema.sql','db/catalog-photos.json','uploads/.htaccess','.htaccess','404.html','index.html','product.html'}
    allowed = allowed or len(p.parts) == 1 and (name.endswith('.php') or name.startswith('google') and name.endswith('.html'))
    if name in {'setup.php','admin-reset.php','config.sample.php','suppex-config.php'}: allowed = False
    if name.startswith('assets/images/products/verified-20261014/'): allowed = False
    if any(part in {'.git','.claude','.codex','tests','tools','products-to-import'} for part in p.parts): allowed = False
    if allowed: paths.append(name)
target = root/args.name
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for name in paths: z.write(root/name,name)
with zipfile.ZipFile(target) as z:
    if z.testzip(): raise SystemExit('Archive CRC verification failed')
    for name in z.namelist():
        p=PurePosixPath(name)
        if p.is_absolute() or '..' in p.parts or '\\' in name: raise SystemExit('Invalid member path')
        if z.read(name)!=(root/name).read_bytes(): raise SystemExit('Archive differs from source: '+name)
    members=z.namelist()
print(json.dumps({'zip':str(target),'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'members':len(members),'paths':members},ensure_ascii=True,indent=2))
