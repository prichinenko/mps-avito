import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('package', ROOT / 'tools/package.py')
package = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(package)
PRODUCT = 'avito'


class StorePackageTest(unittest.TestCase):
    def test_store_requires_verified_vendor(self):
        source = b"<?php return ['version' => '1.0'];"
        with self.assertRaisesRegex(ValueError, 'Developer ID'):
            package.store_metadata(source, '1.0', 7)
        with self.assertRaisesRegex(ValueError, 'positive numeric'):
            package.store_metadata(source, '1.0', 7, 'not-a-number')
        with self.assertRaisesRegex(ValueError, 'does not match'):
            package.store_metadata(b"<?php return ['version'=>'1.0','vendor'=>42];", '1.0', 7, '43')

    def test_store_package_layout_and_metadata(self):
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp) / 'repo'
            repo.mkdir()
            def git(*args):
                subprocess.run(['git', *args], cwd=repo, check=True, capture_output=True)
            git('init')
            git('config', 'user.name', 'Package test')
            git('config', 'user.email', 'package@example.invalid')
            for name in package.REQUIRED_FILES:
                path = repo / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('runtime')
            config = 'lib/config/plugin.php'
            (repo / config).write_text("<?php return ['prefix'=>'mps', 'version'=>'1.0'];")
            (repo / 'lib/config/build.php').write_text('<?php return 7;')
            git('add', '.')
            git('commit', '-m', 'Store fixture')
            archive, checksum, manifest = package.package(repo, Path(tmp) / 'store', store=True, vendor='123456')
            with tarfile.open(archive) as tar:
                members = tar.getmembers()
                self.assertEqual(members[0].name, PRODUCT)
                self.assertTrue(members[0].isdir())
                self.assertTrue(all(member.isreg() and member.name.startswith(PRODUCT + '/') for member in members[1:]))
                config_bytes = tar.extractfile(PRODUCT + '/' + config).read()
                self.assertRegex(config_bytes, rb"'version'\s*=>\s*'1\.0\.7'")
                self.assertRegex(config_bytes, rb"'vendor'\s*=>\s*123456")
            info = json.loads(manifest.read_text())
            self.assertEqual(info['format'], 'webasyst-store')
            self.assertEqual(info['version'], '1.0.7')
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            self.assertTrue(checksum.read_text().startswith(digest))
            self.assertEqual(info['sha256'], digest)
            self.assertNotIn('vendor', (repo / config).read_text())


if __name__ == '__main__':
    unittest.main()
