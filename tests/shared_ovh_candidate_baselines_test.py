"""Real Git adversarial inputs and mocked SFTP: never contacts production."""
import errno
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from scripts import shared_ovh_readonly_baselines as baseline
from scripts.premerge_shared_release_manifest import build_manifest


class CandidateBaselineTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.git('config', 'user.name', 'Offline fixture')
        self.git('config', 'commit.gpgsign', 'false')
        (self.root / 'catalogus-public').mkdir()
        (self.root / 'catalogus-public/index.php').write_bytes(b'old source')
        self.git('add', '.')
        self.git('commit', '-qm', 'trusted main')
        self.main = self.git('rev-parse', 'HEAD').strip().decode()
        self.path = 'catalogus-public/vorige-editie.php'
        self.raw = b'<?php throw new Exception("must never execute candidate");'
        (self.root / self.path).write_bytes(self.raw)
        (self.root / 'catalogus-public/symlink.php').symlink_to('/etc/passwd')
        (self.root / 'catalogus-public/empty.php').touch()
        (self.root / 'catalogus-public/folder.php').mkdir()
        (self.root / 'catalogus-public/folder.php/child').write_text('directory')
        (self.root / 'catalogus-public/photo.webp').write_bytes(b'RIFF\x00\xffWEBP\x00')
        self.git('add', '.')
        self.git('update-index', '--add', '--cacheinfo', '160000', self.main, 'catalogus-public/submodule.php')
        self.git('commit', '-qm', 'candidate data only')
        self.head = self.git('rev-parse', 'HEAD').strip().decode()
        self.git('checkout', '-q', self.main)

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

    def test_new_candidate_file_read_while_main_remains_checked_out(self):
        self.assertFalse((self.root / self.path).exists())
        self.assertEqual(baseline.source_bytes(self.path, self.head, self.root), self.raw)
        self.assertEqual(baseline.source_bytes('catalogus-public/photo.webp', self.head, self.root), b'RIFF\x00\xffWEBP\x00')
        self.assertEqual(self.git('rev-parse','HEAD').strip().decode(), self.main)
        self.assertFalse((self.root / self.path).exists())

    def test_ref_expression_tree_directory_missing_symlink_and_empty_rejected(self):
        for ref in ['main', 'HEAD', self.head+'~1', ';echo injected',
                    self.git('rev-parse', self.head+'^{tree}').strip().decode()]:
            with self.subTest(ref=ref), self.assertRaises((ValueError, subprocess.CalledProcessError)):
                baseline.source_bytes(self.path, ref, self.root)
        for path in ['catalogus-public/symlink.php', 'catalogus-public/empty.php',
                     'catalogus-public/folder.php', 'catalogus-public/submodule.php',
                     'catalogus-public/missing.php', 'private/config.php',
                     'catalogus-public/../secret.php', 'catalogus-public/x$(id).php']:
            with self.subTest(path=path), self.assertRaises((ValueError, subprocess.CalledProcessError)):
                baseline.source_bytes(path, self.head, self.root)

    def test_invalid_source_never_opens_sftp(self):
        with patch.dict(os.environ, {'RELEASE_FILES':self.path, 'SOURCE_SHA':'main'}), \
                patch.object(baseline, 'connect') as connect:
            with self.assertRaises(ValueError): baseline.main()
            connect.assert_not_called()

    def test_oversize_blob_fails_before_contents_are_read(self):
        self.git('checkout','-q',self.head)
        with (self.root / self.path).open('wb') as stream:
            stream.truncate(8 * 1024 * 1024 + 1)
        self.git('add',self.path)
        self.git('commit','-qm','oversize candidate')
        head = self.git('rev-parse','HEAD').strip().decode()
        self.git('checkout','-q',self.main)
        with self.assertRaisesRegex(ValueError, 'large candidate'):
            baseline.source_bytes(self.path, head, self.root)

    def scan(self, *, exists=False, denied=False):
        class ReadOnlySftp:
            def __enter__(inner): return inner
            def __exit__(inner, *args): return False
            def lstat(inner, path):
                if denied: raise PermissionError(errno.EACCES, 'denied')
                if not exists: raise FileNotFoundError(errno.ENOENT, 'absent')
                return SimpleNamespace(st_mode=stat.S_IFREG|0o600, st_size=5)
            def open(inner, path, mode):
                import io
                if mode != 'rb': raise AssertionError('SFTP write attempted')
                return io.BytesIO(b'prior')
        class Client:
            def open_sftp(inner): return ReadOnlySftp()
            def close(inner): pass
        old = Path.cwd()
        try:
            os.chdir(self.root)
            with patch.dict(os.environ, {'RELEASE_FILES':self.path, 'SOURCE_SHA':self.head}), \
                    patch.object(baseline,'connect',return_value=Client()), \
                    patch.object(baseline,'remote_root',return_value='/fixture'):
                baseline.main()
            return json.loads((self.root/'shared-ovh-readonly-baselines.json').read_text())
        finally: os.chdir(old)

    def test_real_absence_and_existing_hash_are_bound_to_candidate(self):
        for exists in [False, True]:
            evidence = self.scan(exists=exists)
            self.assertEqual(evidence['head_sha'], self.head)
            self.assertEqual(evidence['files'][0]['source_sha256'], hashlib.sha256(self.raw).hexdigest())
            self.assertEqual(evidence['files'][0]['remote_sha256'], hashlib.sha256(b'prior').hexdigest() if exists else 'ABSENT')
            self.git('checkout','-q',self.head)
            manifest = build_manifest(self.root, evidence, self.head, [self.path])
            self.assertEqual(set(manifest), {'schema','files'})
            self.git('checkout','-q',self.main)

    def test_denied_remote_is_never_recorded_as_absent(self):
        with self.assertRaises(PermissionError): self.scan(denied=True)
        self.assertFalse((self.root/'shared-ovh-readonly-baselines.json').exists())

    def test_legacy_main_source_is_unchanged_and_escape_is_rejected(self):
        self.assertEqual(baseline.source_bytes('catalogus-public/index.php', root=self.root), b'old source')
        (self.root/'catalogus-public/escape.php').symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            baseline.source_bytes('catalogus-public/escape.php', root=self.root)


if __name__ == '__main__': unittest.main()
