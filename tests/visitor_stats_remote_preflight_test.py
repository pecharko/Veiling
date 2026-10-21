#!/usr/bin/env python3
"""Fake-SFTP tests for T-062, no network and no credentials."""
import errno
import hashlib
import io
import stat
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from preflight_visitor_stats_remote import FILES, NEW, PreflightStop, inspect, path_at

ROOT = "/home/catalog"
OLD = {
    "private/bootstrap.php": b"<?php function admin_top(",
    "catalogus-public/index.php": b"<?php echo 'home';",
    "catalogus-public/kavel.php": b"<?php echo 'lot';",
    "catalogus-public/admin/index.php": b"<?php Kaveloverzicht",
}
class Attr:
    def __init__(self, kind, length=0):
        self.st_mode = kind
        self.st_size = length

class FakeSFTP:
    def __init__(self):
        self.data = {ROOT + "/" + p: b for p, b in OLD.items()}
        self.dirs = {ROOT, *(ROOT + "/" + p for p in ("private","private/backups","catalogus-public","catalogus-public/admin","catalogus-public/uploads"))}
        self.names = {ROOT + "/private/backups": [], ROOT + "/catalogus-public": []}
        self.calls = []
    def lstat(self,p):
        self.calls.append(("lstat",p))
        if p in self.dirs: return Attr(stat.S_IFDIR | 0o700)
        if p in self.data: return Attr(stat.S_IFREG | 0o600,len(self.data[p]))
        raise FileNotFoundError(errno.ENOENT, "absent",p)
    def open(self,p,mode):
        self.calls.append(("open",p,mode))
        if mode != "rb": raise AssertionError("Unsafe open")
        return io.BytesIO(self.data[p])
    def listdir(self,p):
        self.calls.append(("listdir",p))
        return self.names[p]

def manifest():
    return {"release_sha":"a"*40,"files":[{"path":p,"sha256":hashlib.sha256(p.encode()).hexdigest()} for p in FILES]}

class Tests(unittest.TestCase):
    def test_inventory_no_mutation(self):
        fake=FakeSFTP()
        result=inspect(fake,ROOT,manifest())
        self.assertTrue(result["requires_manual_drift_review"])
        self.assertFalse(result["remote_baseline_verified"])
        self.assertFalse(result["upload_allowed"])
        self.assertEqual(len(result["runtime_files"]),5)
        self.assertEqual({x[0] for x in fake.calls},{"lstat","open","listdir"})
        self.assertTrue(all(x[2]=="rb" for x in fake.calls if x[0]=="open"))
    def test_existing_file_missing(self):
        fake=FakeSFTP()
        fake.data.pop(ROOT+"/catalogus-public/kavel.php")
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())
    def test_unexpected_new_file(self):
        fake=FakeSFTP()
        fake.data[ROOT+"/private/visitor_stats.php"]=b"x"
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())
    def test_active_restore_stops(self):
        fake=FakeSFTP()
        fake.names[ROOT+"/private/backups"].append(".restore-active.json")
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())
    def test_root_identity_stops(self):
        fake=FakeSFTP()
        fake.data[ROOT+"/catalogus-public/admin/index.php"]=b"unrelated"
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())
    def test_symlink_stops(self):
        fake=FakeSFTP()
        original=fake.lstat
        def lstat(path):
            if path == ROOT+"/private/bootstrap.php":
                return Attr(stat.S_IFLNK | 0o777,10)
            return original(path)
        fake.lstat=lstat
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())
    def test_disallow_path_traversal(self):
        for val in ("../config.php","/private/config.php","a/../b"):
            with self.subTest(val=val), self.assertRaises(PreflightStop):
                path_at(ROOT,val)
    def test_changed_during_second_read(self):
        fake=FakeSFTP()
        original=fake.open
        count=[0]
        def open_changing(p,mode):
            if p == ROOT+"/catalogus-public/kavel.php":
                count[0]+=1
                if count[0]==2: return io.BytesIO(b"<?php echo 'new';")
            return original(p,mode)
        fake.open=open_changing
        with self.assertRaises(PreflightStop): inspect(fake,ROOT,manifest())

if __name__=="__main__":
    unittest.main()
