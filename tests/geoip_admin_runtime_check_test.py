"""Static regression for private, authenticated GeoLite2 admin diagnostics."""
from pathlib import Path

s = (Path(__file__).resolve().parents[1] / "catalogus-public/admin/systeemcheck.php").read_text()
assert s.index("require_auth();") < s.index("visitor_geo_resolve('8.8.8.8')")
assert "require_no_index();" in s
assert "visitor_geo_resolve('8.8.8.8')" in s
assert "($geo['country_code'] ?? '') === 'US'" in s
assert "report_item($rows, 'GeoLite2 City op OVH', $geoReady, $geoDetail, true);" in s
assert "$_SERVER['REMOTE_ADDR']" not in s
assert "echo $ignored" not in s
print("Authenticated private GeoIP runtime diagnostic contract OK")
