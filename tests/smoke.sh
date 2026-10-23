#!/bin/bash
# Core features: HTP, login block, trusted proxies, expiry and view limits, file headers,
# drop-box quotas, error masking. Run through tests/run.sh.
set -u
# 127.0.0.1, not localhost: the test config trusts 127.0.0.1 as a proxy, and localhost resolves to ::1.
BASE=http://127.0.0.1
JAR=/tmp/psst-jar
DB=/var/www/html/data/psst.sqlite
rm -f "$JAR"
# The first web request creates the database; the helpers below query it directly.
curl -s -o /dev/null "$BASE/health.php"

ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$2', got '$3')"; fi; }
contains() { case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 (missing '$2')" ;; esac; }
lacks() { case "$3" in *"$2"*) bad "$1 (unexpected '$2')" ;; *) ok "$1" ;; esac; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
headers() { curl -s -D - -o /dev/null "$@" | tr -d '\r'; }
sql() {
  php -r '$p = new PDO("sqlite:" . $argv[1]); $s = $p->query($argv[2]);
    if ($s) { foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) { echo implode("|", $r), "\n"; } }' "$DB" "$1"
}
# A valid HTP for the current minute that has not been used yet.
htp() {
  local value tries=0
  while [ $tries -lt 500 ]; do
    tries=$((tries + 1))
    value=$(php -r 'date_default_timezone_set("Europe/Zurich"); echo random_int(1, 9), date("m"), date("i"), random_int(0, 9);')
    [ "$(sql "SELECT COUNT(*) FROM htp_used WHERE value = $value")" = 0 ] && { echo "$value"; return; }
  done
}
api() { local action=$1; shift; curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' "$BASE/manage-api.php?action=$action" "$@"; }
api_code() { local action=$1; shift; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -H 'Accept: application/json' "$BASE/manage-api.php?action=$action" "$@"; }
json() {
  php -r '$v = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; }
    echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v) : (string) $v);' "$1"
}
login() {
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
    -d "{\"username\":\"admin\",\"password\":\"$1\",\"htp\":\"$2\"}" "$BASE/manage-api.php?action=login"
}
files_on_disk() { ls /var/www/html/data/files | wc -l; }

echo "== 9. health check"
r=$(curl -s -w '\n%{http_code}' "$BASE/health.php")
check "health returns 200" 200 "$(echo "$r" | tail -1)"
contains "health reports ok" '"status":"ok"' "$r"
contains "health checks pdo_sqlite" '"pdo_sqlite":true' "$r"

echo "== 1. HTP rule"
php -r 'require "/var/www/html/functions.php";
  $cases = [["103412","10","34",true],["341099","10","34",true],["510347","10","34",true],["134101","10","34",true],
            ["101555","10","01",false],["100155","10","01",true],["101055","10","10",true],["210155","10","10",false],
            ["123456","10","34",false],["103434","10","34",true],["1034","10","34",true]];
  foreach ($cases as [$v, $m, $i, $e]) { echo psst_htp_contains_pair($v, $m, $i) === $e ? "  ok  " : "  FAIL", " pair $v month=$m minute=$i\n"; }'
check "old 7001-7999 bypass rejected" 401 "$(login psst 7500)"
check "5-digit HTP rejected" 401 "$(login psst 12345)"
H=$(htp)
check "valid HTP + wrong password rejected" 401 "$(login wrong "$H")"
check "same HTP still usable after failed login" 200 "$(login psst "$H")"
check "used HTP rejected the second time" 401 "$(login psst "$H")"
check "HTP stored once" 1 "$(sql "SELECT COUNT(*) FROM htp_used WHERE value = $H")"
check "fresh HTP logs in" 200 "$(login psst "$(htp)")"

echo "== 2. login block keeps failed logins for 24h"
now=$(date +%s)
sql "INSERT INTO request_events (ip_address, event_type, created_at) VALUES ('10.9.9.9', 'failed_login', $((now - 7200))), ('10.9.9.9', 'request', $((now - 7200)))"
code "$BASE/index.php?id=not-a-uuid" >/dev/null
check "2h-old failed login kept after pruning" 1 "$(sql "SELECT COUNT(*) FROM request_events WHERE ip_address = '10.9.9.9' AND event_type = 'failed_login'")"
check "2h-old request event pruned" 0 "$(sql "SELECT COUNT(*) FROM request_events WHERE ip_address = '10.9.9.9' AND event_type = 'request'")"
for i in 1 2 3 4 5 6 7 8 9 10; do sql "INSERT INTO request_events (ip_address, event_type, created_at) VALUES ('127.0.0.1', 'failed_login', $((now - 7200)))"; done
check "10 failures 2h ago still block the login" 429 "$(login psst "$(htp)")"
sql "DELETE FROM request_events WHERE event_type = 'failed_login'"

echo "== 7. trusted proxies"
php -r 'require "/var/www/html/functions.php";
  $cases = [["10.1.2.3",["10.0.0.0/8"],true],["11.0.0.1",["10.0.0.0/8"],false],["192.168.1.130",["192.168.1.128/25"],true],
            ["192.168.1.127",["192.168.1.128/25"],false],["2001:db8::1",["2001:db8::/32"],true],["2001:db9::1",["2001:db8::/32"],false],
            ["127.0.0.1",["127.0.0.1"],true],["::1",["127.0.0.1"],false],["not-an-ip",["0.0.0.0/0"],false]];
  foreach ($cases as [$ip, $ranges, $e]) { echo psst_ip_in_list($ip, $ranges) === $e ? "  ok  " : "  FAIL", " ip_in_list $ip ", implode(",", $ranges), "\n"; }'
r=$(php -r 'require "/var/www/html/functions.php"; $_SERVER["REMOTE_ADDR"] = "8.8.8.8"; $_SERVER["HTTP_X_FORWARDED_FOR"] = "1.2.3.4"; echo psst_client_ip();')
check "untrusted peer: X-Forwarded-For ignored" 8.8.8.8 "$r"

echo "== 11. expiry and view limits"
r=$(api create -F type=text -F title=plain -F content=hello-plain)
U=$(echo "$r" | json share.uuid)
check "unlimited text served as text/plain" hello-plain "$(curl -s "$BASE/index.php?id=$U")"
curl -s -I "$BASE/index.php?id=$U" >/dev/null
check "HEAD does not count a view" 1 "$(sql "SELECT view_count FROM shares WHERE uuid = '$U'")"
contains "share pages send Cache-Control: no-store" "Cache-Control: no-store" "$(headers "$BASE/index.php?id=$U")"

r=$(api create -F type=text -F title=burn -F content=secret-text-xyz -F max_views=2)
U=$(echo "$r" | json share.uuid)
check "API returns max_views" 2 "$(echo "$r" | json share.max_views)"
p=$(curl -s "$BASE/index.php?id=$U")
contains "GET shows the reveal page" "can be opened 2 more times" "$p"
lacks "reveal page does not contain the text" "secret-text-xyz" "$p"
check "GET does not count a view" 0 "$(sql "SELECT view_count FROM shares WHERE uuid = '$U'")"
p=$(curl -s -X POST -d reveal=1 "$BASE/index.php?id=$U")
contains "POST shows the text" "secret-text-xyz" "$p"
contains "first view mentions one more view" "can be opened one more time" "$p"
p=$(curl -s -X POST -d reveal=1 "$BASE/index.php?id=$U")
contains "last view warns the text is deleted" "This was the last view" "$p"
check "used-up share is gone" 404 "$(code "$BASE/index.php?id=$U")"
check "used-up share deleted from DB" 0 "$(sql "SELECT COUNT(*) FROM shares WHERE uuid = '$U'")"

r=$(api create -F type=link -F content=https://example.com/target -F max_views=1)
U=$(echo "$r" | json share.uuid)
h=$(headers -X POST -d reveal=1 "$BASE/index.php?id=$U")
contains "view-limited link redirects with 303" "HTTP/1.1 303" "$h"
contains "redirect goes to the target" "Location: https://example.com/target" "$h"

head -c 2048 /dev/urandom > /tmp/burn.bin
before=$(files_on_disk)
r=$(api create -F type=file -F max_views=1 -F "file=@/tmp/burn.bin;type=image/png")
U=$(echo "$r" | json share.uuid)
check "file stored" $((before + 1)) "$(files_on_disk)"
h=$(curl -s -D - -o /tmp/burn.out -X POST -d reveal=1 "$BASE/index.php?id=$U" | tr -d '\r')
contains "view-limited file is downloaded, not shown inline" "Content-Disposition: attachment" "$h"
check "downloaded bytes match" "$(md5sum < /tmp/burn.bin)" "$(md5sum < /tmp/burn.out)"
check "file removed from disk after the last view" "$before" "$(files_on_disk)"

check "past expiry rejected" 400 "$(api_code create -F type=text -F content=x -F expires_at=2020-01-01T00:00:00Z)"
check "view limit 0 rejected" 400 "$(api_code create -F type=text -F content=x -F max_views=0)"
r=$(api create -F type=text -F content=expiring -F expires_at=$(( $(date +%s) + 3600 )))
U=$(echo "$r" | json share.uuid)
contains "API returns expires_at as ISO time" "T" "$(echo "$r" | json share.expires_at)"
sql "UPDATE shares SET expires_at = $(( $(date +%s) - 5 )) WHERE uuid = '$U'"
check "expired share answers 404" 404 "$(code "$BASE/index.php?id=$U")"
check "expired share deleted" 0 "$(sql "SELECT COUNT(*) FROM shares WHERE uuid = '$U'")"

r=$(api create -F type=text -F content=counting -F max_views=5)
U=$(echo "$r" | json share.uuid)
curl -s -X POST -d reveal=1 "$BASE/index.php?id=$U" >/dev/null
curl -s -X POST -d reveal=1 "$BASE/index.php?id=$U" >/dev/null
check "lowering the limit to the view count is rejected" 400 "$(api_code update -F type=text -F max_views=2 --url-query "uuid=$U")"
check "raising it above the view count works" 200 "$(api_code update -F type=text -F max_views=3 --url-query "uuid=$U")"

r=$(api create -F type=text -F is_encrypted=1 -F content=CIPHERTEXTBASE64 -F max_views=1 \
  -F 'encryption_meta={"kind":"text","crypto":{"algorithm":"AES-GCM","iterations":600000,"salt":"AA==","iv":"AA=="}}')
U=$(echo "$r" | json share.uuid)
p=$(curl -s "$BASE/index.php?id=$U")
contains "encrypted page renders" "This share is encrypted" "$p"
lacks "encrypted page does not embed the ciphertext" "CIPHERTEXTBASE64" "$p"
check "page view does not count" 0 "$(sql "SELECT view_count FROM shares WHERE uuid = '$U'")"
check "raw fetch returns the ciphertext" CIPHERTEXTBASE64 "$(curl -s "$BASE/index.php?id=$U&raw=1")"
check "second raw fetch is refused" 404 "$(code "$BASE/index.php?id=$U&raw=1")"

r=$(api create -F type=text -F content=x -F secret_code=abc)
U=$(echo "$r" | json share.uuid)
check "has_secret_code set" true "$(echo "$r" | json share.has_secret_code)"
r=$(api update -F type=text -F secret_code= --url-query "uuid=$U")
check "secret code can be removed" false "$(echo "$r" | json share.has_secret_code)"

echo "== 3. file responses cannot run script on this origin"
printf '<script>alert(1)</script>' > /tmp/x.html
printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' > /tmp/x.svg
printf '%%PDF-1.4\n%%EOF\n' > /tmp/x.pdf
U=$(api create -F type=file -F "file=@/tmp/x.html;type=text/html" | json share.uuid)
h=$(headers "$BASE/index.php?id=$U")
contains "HTML file is a download" "Content-Disposition: attachment" "$h"
contains "HTML file is sandboxed" "Content-Security-Policy: sandbox" "$h"
U=$(api create -F type=file -F "file=@/tmp/x.svg;type=image/svg+xml" | json share.uuid)
contains "SVG file is a download" "Content-Disposition: attachment" "$(headers "$BASE/index.php?id=$U")"
U=$(api create -F type=file -F "file=@/tmp/burn.bin;type=image/png" | json share.uuid)
h=$(headers "$BASE/index.php?id=$U")
contains "PNG shown inline" "Content-Disposition: inline" "$h"
contains "PNG sandboxed" "Content-Security-Policy: sandbox" "$h"
U=$(api create -F type=file -F "file=@/tmp/x.pdf;type=application/pdf" | json share.uuid)
h=$(headers "$BASE/index.php?id=$U")
contains "PDF shown inline" "Content-Disposition: inline" "$h"
lacks "PDF not sandboxed (Chrome viewer)" "Content-Security-Policy" "$h"
php -r 'require "/var/www/html/functions.php";
  $cases = [["image/png","image/png"],["IMAGE/PNG; charset=x","image/png"],["text/html\r\nX-Evil: 1","application/octet-stream"],
            ["text/html x","application/octet-stream"],["","application/octet-stream"],["application/vnd.ms-excel","application/vnd.ms-excel"]];
  foreach ($cases as [$in, $e]) { echo psst_safe_mime($in) === $e ? "  ok  " : "  FAIL", " safe_mime ", json_encode($in), "\n"; }'

echo "== 8. drop box storage limit"
r=$(api create -F type=upload -F title=box -F require_token=0)
B=$(echo "$r" | json share.uuid)
check "new drop box gets the default quota" 1048576 "$(echo "$r" | json share.upload_quota_bytes)"
head -c 614400 /dev/urandom > /tmp/600k.bin
head -c 102400 /dev/urandom > /tmp/100k.bin
head -c 204800 /dev/urandom > /tmp/200k.bin
check "600 KB upload accepted" 201 "$(code -F file=@/tmp/600k.bin "$BASE/index.php?id=$B")"
r=$(curl -s -w '\n%{http_code}' -F file=@/tmp/600k.bin "$BASE/index.php?id=$B")
check "second 600 KB upload rejected" 413 "$(echo "$r" | tail -1)"
contains "rejection explains the space left" "Not enough space left" "$r"
check "raw 600 KB upload rejected" 413 "$(code --data-binary @/tmp/600k.bin -H 'Content-Type: application/octet-stream' "$BASE/index.php?id=$B&filename=a.bin")"
r=$(curl -s -H 'Accept: application/json' -H 'X-Forwarded-For: 198.51.100.7, 127.0.0.1' -F file=@/tmp/100k.bin "$BASE/index.php?id=$B")
check "100 KB upload accepted" true "$(echo "$r" | json ok)"
check "response reports remaining bytes" $((1048576 - 614400 - 102400)) "$(echo "$r" | json remaining_bytes)"
check "client IP taken from X-Forwarded-For via trusted proxy" 198.51.100.7 "$(sql "SELECT remote_ip FROM upload_files ORDER BY id DESC LIMIT 1")"
count=$(sql "SELECT COUNT(*) FROM upload_files")
check "batch over the limit rejected" 413 "$(code -F 'file[]=@/tmp/200k.bin' -F 'file[]=@/tmp/200k.bin' "$BASE/index.php?id=$B")"
check "rejected batch stored nothing" "$count" "$(sql "SELECT COUNT(*) FROM upload_files")"
r=$(api share --url-query "uuid=$B")
check "manager sees used bytes" $((614400 + 102400)) "$(echo "$r" | json share.upload_used_bytes)"
contains "upload page shows space left" "Space left:" "$(curl -s "$BASE/index.php?id=$B")"
r=$(api update -F type=upload -F require_token=0 -F upload_quota_bytes= --url-query "uuid=$B")
check "quota can be removed" "" "$(echo "$r" | json share.upload_quota_bytes)"
check "unlimited box accepts the upload" 201 "$(code -F file=@/tmp/600k.bin "$BASE/index.php?id=$B")"
sql "UPDATE shares SET expires_at = $(( $(date +%s) - 5 )) WHERE uuid = '$B'"
check "closed box page answers 410" 410 "$(code "$BASE/index.php?id=$B")"
check "closed box refuses uploads" 410 "$(code -F file=@/tmp/100k.bin "$BASE/index.php?id=$B")"
check "closed box keeps its files" 3 "$(sql "SELECT COUNT(*) FROM upload_files f JOIN shares s ON s.id = f.share_id WHERE s.uuid = '$B'")"
check "manager reports the box as closed" true "$(api share --url-query "uuid=$B" | json share.upload_closed)"

echo "== 4. errors are not leaked"
U=$(api create -F type=text -F content=x | json share.uuid)
chmod 444 "$DB"
r=$(curl -s -w '\n%{http_code}' "$BASE/index.php?id=$U")
check "DB failure answers 500" 500 "$(echo "$r" | tail -1)"
lacks "public page hides the exception" "readonly" "$r"
r=$(curl -s -H 'Content-Type: application/json' -d '{"username":"x","password":"y","htp":"1"}' "$BASE/manage-api.php?action=login")
check "API error is generic" '{"error":"Server error."}' "$r"
chmod 644 "$DB"

echo "DONE"
