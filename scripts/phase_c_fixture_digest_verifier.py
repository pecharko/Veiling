"""Pure Phase C sandbox fixture digest validator. No API client or writes."""
import hashlib

PATH = "tests/phase_c_sandbox_test.php"
ORIGINAL_HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
ORIGINAL_BLOB = "a16bb38acbde1422389e1fcb4835c20688f594b3"
EXPECTED_SHA256 = "013a57991e6285797587766f1bfb422d5f27045a1ca91531dd772b44958e9323"
EXPECTED_BLOB = "9bb4e9774e6244c143975c00bcac4826d4895317"


def git_blob_sha1(raw):
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + bytes([0]) + raw).hexdigest()


def verify_fixture(old_content, *, path, head, original_blob):
    """Return evidence only; never authorization or a GitHub mutation."""
    denied = {"verified": False, "write_allowed": False, "reason": "mismatch"}
    if not isinstance(old_content, bytes) or len(old_content) > 2048:
        return denied
    if path != PATH or head != ORIGINAL_HEAD or original_blob != ORIGINAL_BLOB:
        return denied
    if git_blob_sha1(old_content) != ORIGINAL_BLOB:
        return denied
    if old_content.count(b"return flase;") != 1:
        return denied
    new_content = old_content.replace(b"return flase;", b"return false;")
    if hashlib.sha256(new_content).hexdigest() != EXPECTED_SHA256:
        return denied
    if git_blob_sha1(new_content) != EXPECTED_BLOB:
        return denied
    return {"verified": True, "write_allowed": False,
            "new_content_sha256": EXPECTED_SHA256, "new_blob": EXPECTED_BLOB}
