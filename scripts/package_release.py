from pathlib import Path, PurePosixPath
import hashlib, re, sys, zipfile

root = Path(sys.argv[1]); out = Path(sys.argv[2]); expected = sys.argv[3]
base = root / 'syndamorph-unpacker-poc'
main = (base / 'syndamorph-unpacker-poc.php').read_text()
assert re.search(r'^ \* Version: '+re.escape(expected)+r'$', main, re.M), 'Version mismatch'
assert ' * Update URI: https://github.com/gnznovus/syndamorph-unpacker' in main
assert (base / 'includes/updater.php').exists()
paths = sorted(x for x in base.rglob('*') if x.is_file())
assert not any(p.is_symlink() for p in paths)
out.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
    for p in paths:
        name = 'syndamorph-unpacker-poc/' + p.relative_to(base).as_posix()
        assert '\\' not in name and '..' not in PurePosixPath(name).parts
        data = p.read_bytes()
        info = zipfile.ZipInfo(name, date_time=(1980,1,1,0,0,0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = (0o100644 << 16)
        archive.writestr(info, data)
with zipfile.ZipFile(out) as archive:
    assert set(archive.namelist()) == {'syndamorph-unpacker-poc/' + p.relative_to(base).as_posix() for p in paths}
    for p in paths:
        assert archive.read('syndamorph-unpacker-poc/' + p.relative_to(base).as_posix()) == p.read_bytes()
(out.parent / (out.name + '.sha256')).write_text(hashlib.sha256(out.read_bytes()).hexdigest()+'  '+out.name+'\n')
print(out, out.stat().st_size, hashlib.sha256(out.read_bytes()).hexdigest())
