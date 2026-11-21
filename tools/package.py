#!/usr/bin/env python3
"""RU: Собрать плагин Avito без тестового SDK и инструментов.
EN: Build the Avito plugin without test SDK or development tools.
"""
import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile

RUNTIME_ROOTS = ('lib', 'templates', 'js', 'css', 'img', 'locale')
ARCHIVE_PREFIX = 'wa-apps/mps/plugins/avito/'
REQUIRED_FILES = {'lib/config/plugin.php', 'lib/config/build.php',
                  'lib/mpsAvito.plugin.php', 'locale/en_US/LC_MESSAGES/mps_avito.mo',
                  'locale/ru_RU/LC_MESSAGES/mps_avito.mo'}


def git(repo, *args):
    result = subprocess.run(['git', '-C', str(repo), *args], capture_output=True)
    if result.returncode:
        raise ValueError(result.stderr.decode(errors='replace').strip())
    return result.stdout


def included(path):
    parts = PurePosixPath(path).parts
    if not parts or parts[0] not in RUNTIME_ROOTS:
        return False
    # RU: plugins/ — только верхний каталог; базовый класс lib/classes/plugins нужен приложению.
    # EN: Exclude top-level plugins/ only; lib/classes/plugins contains required application code.
    if any(part in ('.git', '.svn', '__pycache__', 'node_modules', 'tests') for part in parts):
        return False
    name = parts[-1]
    return not (name.startswith('.env') or name in ('.DS_Store', 'Thumbs.db')
                or name.endswith(('~', '.bak', '.orig', '.rej', '.pyc')))



def validate_update_path(path):
    # RU: Для установленных плагинов допустимы штатные timestamp-обновления Webasyst.
    # EN: Installed plugins may ship native Webasyst timestamp updates.
    parts = PurePosixPath(path).parts
    if parts[:2] == ('lib', 'updates'):
        if len(parts) != 3 or not re.fullmatch(r'[0-9]+\.php', parts[-1]):
            raise ValueError('Invalid native Webasyst update path: ' + path)
    elif parts[:2] == ('lib', 'migrations') or parts[:1] in (('updates',), ('migrations',)):
        raise ValueError('Use lib/updates/<timestamp>.php: ' + path)


def metadata(app, build):
    values = {}
    for field in ('version',):
        matches = re.findall(r"['\"]" + field + r"['\"]\s*=>\s*(['\"])([^'\"]+)\1", app.decode())
        if len(matches) != 1:
            raise ValueError('Expected one static application ' + field)
        values[field] = matches[0][1]
    match = re.fullmatch(rb'<\?php\s+return ([0-9]+);\s*', build)
    if not re.fullmatch(r'\d+(?:\.\d+)+', values['version']) or not match:
        raise ValueError('Invalid application identity, version or build')
    number = int(match[1])
    if number < 1:
        raise ValueError('Build must be positive')
    return values['version'], number


def store_metadata(config, version, build, vendor=None):
    if not re.fullmatch(r'[0-9]+\.[0-9]+', version):
        raise ValueError('Store packaging expects a major.minor source version and a separate build')
    text = config.decode('utf-8')
    version_field = re.compile(r"""(['"]version['"]\s*=>\s*)(['"])[^'"]+\2""")
    vendor_key = re.compile(r"""['"]vendor['"]\s*=>""")
    vendor_field = re.compile(r"""(['"]vendor['"]\s*=>\s*)(?:'([1-9][0-9]*)'|"([1-9][0-9]*)"|([1-9][0-9]*))(?=\s*[,\]])""")
    existing = list(vendor_field.finditer(text))
    if len(vendor_key.findall(text)) != len(existing) or len(existing) > 1:
        raise ValueError('Expected a single static positive Webasyst Developer ID')
    if vendor is not None and not re.fullmatch(r'[1-9][0-9]*', vendor):
        raise ValueError('Expected a positive numeric Webasyst Developer ID')
    if existing:
        match = existing[0]
        declared = next(value for value in match.groups()[1:] if value is not None)
        if vendor is not None and vendor != declared:
            raise ValueError('Developer ID does not match the product configuration')
        text = vendor_field.sub(lambda entry: entry[1] + declared, text, count=1)
    elif vendor is not None:
        text = version_field.sub(lambda entry: "'vendor' => " + vendor + ", " + entry[0], text, count=1)
    else:
        raise ValueError('Store packaging requires a Webasyst Developer ID in the product config or --vendor')
    return version_field.sub(lambda entry: entry[1] + entry[2] + f'{version}.{build}' + entry[2],
                             text, count=1).encode('utf-8')


def add_store_root(tar, prefix, epoch):
    directory = tarfile.TarInfo(prefix.rstrip('/'))
    directory.type = tarfile.DIRTYPE
    directory.mode, directory.mtime = 0o755, epoch
    tar.addfile(directory)


def package(repo, output_dir, *, store=False, vendor=None):
    repo = Path(repo).resolve()
    output_dir = Path(output_dir).resolve()
    commit = git(repo, 'rev-parse', '--verify', 'HEAD^{commit}').decode().strip()
    # RU: Публикуем только коммит; незакоммиченный рабочий код не должен незаметно потеряться.
    # EN: Package committed content only and reject uncommitted runtime changes.
    changed = git(repo, 'diff', '--name-only', commit, '--', *RUNTIME_ROOTS).decode().splitlines()
    untracked = git(repo, 'ls-files', '--others', '--exclude-standard', '--', *RUNTIME_ROOTS).decode().splitlines()
    dirty = [path for path in changed + untracked if included(path)]
    if dirty:
        raise ValueError('Commit runtime changes before packaging: ' + ', '.join(dirty))

    prefix = 'avito/' if store else ARCHIVE_PREFIX
    files = []
    for entry in git(repo, 'ls-tree', '-rz', '--full-tree', commit).split(b'\0'):
        if not entry:
            continue
        header, raw_path = entry.split(b'\t', 1)
        path = raw_path.decode('utf-8')
        validate_update_path(path)
        if not included(path):
            continue
        mode, kind, sha = header.decode().split()
        if mode not in ('100644', '100755') or kind != 'blob':
            raise ValueError('Runtime links/submodules are not allowed: ' + path)
        if '\\' in path or '\n' in path or '..' in PurePosixPath(path).parts:
            raise ValueError('Unsafe publication path: ' + path)
        files.append((path, sha))
    missing = REQUIRED_FILES - {path for path, _ in files}
    if missing:
        raise ValueError('Missing runtime files: ' + ', '.join(sorted(missing)))
    blobs = {path: git(repo, 'cat-file', 'blob', sha) for path, sha in sorted(files)}
    config = 'lib/config/plugin.php'
    version, build = metadata(blobs[config], blobs['lib/config/build.php'])
    if vendor is not None and not store:
        raise ValueError('--vendor is available only in store packaging')
    if store:
        blobs[config] = store_metadata(blobs[config], version, build, vendor)
    epoch = int(git(repo, 'show', '-s', '--format=%ct', commit))
    output_dir.mkdir(parents=True, exist_ok=True)
    filename = f'mps-avito-{version}.{build}.tar.gz'
    archive = output_dir / filename
    temporary = None
    manifest_files = []
    try:
        # RU: Фиксируем метаданные, чтобы повторная сборка коммита давала тот же архив.
        # EN: Normalize metadata so rebuilding the same commit produces the same archive.
        with tempfile.NamedTemporaryFile(dir=output_dir, delete=False) as target:
            temporary = Path(target.name)
            with gzip.GzipFile(filename='', mode='wb', fileobj=target, mtime=epoch, compresslevel=9) as compressed:
                with tarfile.open(fileobj=compressed, mode='w|', format=tarfile.USTAR_FORMAT) as tar:
                    if store:
                        add_store_root(tar, prefix, epoch)
                    for path, data in blobs.items():
                        member = tarfile.TarInfo(prefix + path)
                        member.size, member.mtime, member.mode = len(data), epoch, 0o644
                        member.uid = member.gid = 0
                        member.uname = member.gname = ''
                        tar.addfile(member, io.BytesIO(data))
                        manifest_files.append({'path': member.name, 'size': len(data),
                                               'sha256': hashlib.sha256(data).hexdigest()})
        os.replace(temporary, archive)
    finally:
        if temporary and temporary.exists():
            temporary.unlink()
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    checksum = output_dir / (filename + '.sha256')
    checksum.write_text(digest + '  ' + filename + '\n')
    manifest = output_dir / (filename + '.manifest.json')
    manifest.write_text(json.dumps({'application': 'mps', 'plugin': 'avito', 'version': f'{version}.{build}' if store else version, 'build': build,
                                   'format': 'webasyst-store' if store else 'manual',
                                   'commit': commit, 'archive': filename, 'sha256': digest,
                                   'prefix': prefix, 'files': manifest_files},
                                  ensure_ascii=False, indent=2) + '\n')
    return archive, checksum, manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--output-dir', type=Path)
    parser.add_argument('--store', action='store_true', help='Build a Webasyst Store submission archive')
    parser.add_argument('--vendor', help='Webasyst Developer ID for a product without a configured ID')
    args = parser.parse_args()
    try:
        for artifact in package(args.repo, args.output_dir or Path('dist/store' if args.store else 'dist'),
                                store=args.store, vendor=args.vendor):
            print(artifact)
    except (ValueError, OSError, tarfile.TarError) as error:
        parser.exit(1, str(error) + '\n')
