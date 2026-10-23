#!/bin/bash
# Markdown shares, API keys, api.php and the API documentation. Run through tests/run.sh.
set -u
BASE=http://127.0.0.1
JAR=/tmp/psst-api-jar
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
htp() {
  local value tries=0
  while [ $tries -lt 500 ]; do
    tries=$((tries + 1))
    value=$(php -r 'date_default_timezone_set("Europe/Zurich"); echo random_int(1, 9), date("m"), date("i"), random_int(0, 9);')
    [ "$(sql "SELECT COUNT(*) FROM htp_used WHERE value = $value")" = 0 ] && { echo "$value"; return; }
  done
}
manager() { local action=$1; shift; curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' "$BASE/manage-api.php?action=$action" "$@"; }
api() { local key=$1 action=$2; shift 2; curl -s -H "Authorization: Bearer $key" "$BASE/api.php?action=$action" "$@"; }
api_code() { local key=$1 action=$2; shift 2; curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $key" "$BASE/api.php?action=$action" "$@"; }
json() {
  php -r '$v = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; }
    echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v) : (string) $v);' "$1"
}

curl -s -o /dev/null -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
  -d "{\"username\":\"admin\",\"password\":\"psst\",\"htp\":\"$(htp)\"}" "$BASE/manage-api.php?action=login"

echo "== API keys (manager)"
r=$(manager api_key_create -H 'Content-Type: application/json' -d '{"name":"PLM"}')
KEY=$(echo "$r" | json token)
KEY_ID=$(echo "$r" | json key.id)
contains "key has the psst_ prefix" "psst_" "$KEY"
check "key is 48 characters" 48 "${#KEY}"
check "only a hash is stored" 0 "$(sql "SELECT COUNT(*) FROM api_keys WHERE token_hash = '$KEY' OR token_prefix = '$KEY'")"
lacks "key list does not reveal the token" "$KEY" "$(manager api_keys)"
OTHER=$(manager api_key_create -H 'Content-Type: application/json' -d '{"name":"Other"}' | json token)
check "empty key name rejected" 400 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Content-Type: application/json' -d '{"name":" "}' "$BASE/manage-api.php?action=api_key_create")"
check "key management needs a login" 401 "$(code "$BASE/manage-api.php?action=api_keys")"

echo "== authentication"
h=$(curl -s -D - -o /dev/null "$BASE/api.php?action=status" | tr -d '\r')
contains "no key answers 401" "HTTP/1.1 401" "$h"
contains "401 names the Bearer scheme" 'WWW-Authenticate: Bearer realm="PSST"' "$h"
check "wrong key answers 401" 401 "$(api_code psst_wrong status)"
r=$(api "$KEY" status)
check "Bearer key accepted" true "$(echo "$r" | json ok)"
check "status names the key" PLM "$(echo "$r" | json key)"
check "X-API-Key header accepted" 200 "$(code -H "X-API-Key: $KEY" "$BASE/api.php?action=status")"
check "key in the URL is ignored" 401 "$(code "$BASE/api.php?action=status&api_key=$KEY&key=$KEY&token=$KEY")"
check "last use recorded" 1 "$(sql "SELECT COUNT(*) FROM api_keys WHERE id = $KEY_ID AND last_used_at IS NOT NULL")"
contains "API responses are not cached" "Cache-Control: no-store" "$(headers -H "Authorization: Bearer $KEY" "$BASE/api.php?action=status")"

echo "== create shares"
MD='# Release notes\n\nSee [the docs](https://example.com) and <script>alert(1)</script>.\n\n| a | b |\n|---|---|\n| 1 | 2 |'
r=$(api "$KEY" create -H 'Content-Type: application/json' -d "{\"type\":\"markdown\",\"title\":\"Release notes 2.4\",\"content\":\"$MD\",\"max_views\":3}")
U=$(echo "$r" | json share.uuid)
check "markdown share created" markdown "$(echo "$r" | json share.type)"
check "create returns the view limit" 3 "$(echo "$r" | json share.max_views)"
contains "create returns the share URL" "index.php?id=$U" "$(echo "$r" | json share.url)"
lacks "create does not echo the content" "Release notes\\n" "$r"
check "share is linked to the key" "$KEY_ID" "$(sql "SELECT api_key_id FROM shares WHERE uuid = '$U'")"
check "markdown via GET create is 405" 405 "$(api_code "$KEY" create)"
check "upload type refused" 400 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{"type":"upload"}')"
check "encrypted share refused" 400 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{"type":"text","content":"x","is_encrypted":true}')"
check "empty markdown refused" 400 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{"type":"markdown","content":"  "}')"
check "invalid JSON refused" 400 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{nope')"
printf '%%PDF-1.4\n%%EOF\n' > /tmp/api.pdf
r=$(api "$KEY" create -F type=file -F title=Diagram -F "file=@/tmp/api.pdf;type=application/pdf")
check "file share created" api.pdf "$(echo "$r" | json share.file_name)"
check "text share created" 201 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{"type":"text","content":"hi","secret_code":"abc"}')"
check "link share created" 201 "$(api_code "$KEY" create -H 'Content-Type: application/json' -d '{"type":"link","content":"https://example.com"}')"

echo "== markdown share page"
p=$(curl -s "$BASE/index.php?id=$U")
contains "view-limited markdown shows the reveal page" "Show document" "$p"
p=$(curl -s -X POST -d reveal=1 "$BASE/index.php?id=$U")
contains "heading rendered" '<h1 id="md-release-notes">Release notes</h1>' "$p"
contains "link rendered" '<a href="https://example.com" target="_blank" rel="nofollow noopener noreferrer">the docs</a>' "$p"
contains "table rendered" '<td>2</td>' "$p"
lacks "script in markdown not executed" '<script>alert' "$p"
contains "source kept for copy/download" 'id="psst-markdown-source"' "$p"
h=$(headers -X POST -d reveal=1 "$BASE/index.php?id=$U")
contains "markdown page sends a strict CSP" "Content-Security-Policy: default-src 'none'; script-src 'self'" "$h"
h=$(curl -s -D - -o /tmp/md.out -X POST -d reveal=1 "$BASE/index.php?id=$U&download=1" | tr -d '\r')
contains "download is an attachment" 'Content-Disposition: attachment; filename="Release_notes_2.4.md"' "$h"
contains "download is the raw source" "<script>alert(1)</script>" "$(cat /tmp/md.out)"
check "used-up markdown share gone" 404 "$(code "$BASE/index.php?id=$U")"
r=$(manager create -F type=markdown -F 'content=*hello*')
M=$(echo "$r" | json share.uuid)
contains "unlimited markdown renders on GET" "<em>hello</em>" "$(curl -s "$BASE/index.php?id=$M")"
check "manager cannot encrypt markdown" 400 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -F type=markdown -F content=x -F is_encrypted=1 "$BASE/manage-api.php?action=create")"

echo "== get and delete"
r=$(api "$KEY" create -H 'Content-Type: application/json' -d '{"type":"markdown","content":"# x"}')
U=$(echo "$r" | json share.uuid)
check "owner reads its share" "$U" "$(api "$KEY" share --url-query "uuid=$U" | json share.uuid)"
check "other key gets 404" 404 "$(api_code "$OTHER" share --url-query "uuid=$U")"
check "manager-created share is 404 for the API" 404 "$(api_code "$KEY" share --url-query "uuid=$M")"
check "invalid uuid is 400" 400 "$(api_code "$KEY" share --url-query "uuid=x")"
check "other key cannot delete" 404 "$(api_code "$OTHER" delete -X DELETE --url-query "uuid=$U")"
check "owner deletes with DELETE" true "$(api "$KEY" delete -X DELETE --url-query "uuid=$U" | json deleted)"
check "deleted share is 404" 404 "$(api_code "$KEY" share --url-query "uuid=$U")"
check "unknown action is 404" 404 "$(api_code "$KEY" nope)"

echo "== manager view of API shares"
r=$(manager shares)
contains "manager list shows the key name" '"api_key_name":"PLM"' "$r"
check "key list counts its shares" 3 "$(manager api_keys | php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d["keys"] as $k) { if ($k["name"] === "PLM") echo $k["share_count"]; }')"

echo "== revoking"
F=$(sql "SELECT uuid FROM shares WHERE type = 'link' AND api_key_id = $KEY_ID LIMIT 1")
check "revoke works" true "$(curl -s -b "$JAR" -X POST "$BASE/manage-api.php?action=api_key_revoke&id=$KEY_ID" | json revoked)"
check "revoked key answers 401" 401 "$(api_code "$KEY" status)"
check "shares of a revoked key stay online" 302 "$(code "$BASE/index.php?id=$F")"
contains "key list marks it revoked" '"revoked_at":"' "$(manager api_keys)"

echo "== failed attempts are limited"
sql "DELETE FROM request_events WHERE event_type = 'failed_api_auth'"
for i in 1 2 3 4 5 6 7 8 9 10; do code -H "Authorization: Bearer psst_guess$i" "$BASE/api.php?action=status" >/dev/null; done
check "11th failed attempt answers 429" 429 "$(api_code psst_guess11 status)"
check "a valid key is blocked too while limited" 429 "$(api_code "$OTHER" status)"
sql "DELETE FROM request_events WHERE event_type = 'failed_api_auth'"
check "valid key works again after the window" 200 "$(api_code "$OTHER" status)"

echo "== documentation"
h=$(headers "$BASE/api-docs.php")
contains "docs need a login" "Location: manage.php" "$h"
p=$(curl -s -b "$JAR" "$BASE/api-docs.php")
contains "docs render for the manager" '<h1 id="md-psst-api">PSST API</h1>' "$p"
contains "docs show the real base URL" "http://localhost:8092/api.php" "$p"
lacks "no placeholders left" "{{" "$p"
check "markdown source not downloadable" 403 "$(code "$BASE/docs/api.md")"
check "docs folder not listable" 403 "$(code "$BASE/docs/")"
echo "DONE"
