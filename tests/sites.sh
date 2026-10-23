#!/bin/bash
# Sites and site storage: sandbox, tokens, modes, quotas, owner access, API. Run through tests/run.sh.
set -u
BASE=http://127.0.0.1
JAR=/tmp/psst-sites-jar
DB=/var/www/html/data/psst.sqlite
FILES=/var/www/html/data/files
rm -f "$JAR" /tmp/v1 /tmp/v2 /tmp/v3
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
json() {
  php -r '$v = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; }
    echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v) : (string) $v);' "$1"
}
raw_json() { php -r 'echo json_encode(json_decode(stream_get_contents(STDIN))->{$argv[1]});' "$1"; }
manager() { local action=$1; shift; curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' "$BASE/manage-api.php?action=$action" "$@"; }
manager_code() { local action=$1; shift; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Accept: application/json' "$BASE/manage-api.php?action=$action" "$@"; }
mjson() { local action=$1 query=$2 body=$3; manager "$action" --url-query "+$query" -H 'Content-Type: application/json' -d "$body"; }
# Token from a served page; $1 = URL, rest = curl options (cookie jar)
page_token() { local url=$1; shift; curl -s "$@" "$url" | php -r 'preg_match("/\"token\":\"([^\"]+)\"/", stream_get_contents(STDIN), $m); echo $m[1] ?? "";'; }
claim() { php -r '$p = explode(".", $argv[1])[0]; $c = json_decode(base64_decode(strtr($p, "-_", "+/")), true); echo $c[$argv[2]] ?? "";' "$1" "$2"; }
store() { local token=$1 action=$2 body=${3:-}; shift $(( $# < 3 ? $# : 3 ))
  if [ -n "$body" ]; then curl -s -H "Authorization: Bearer $token" -H 'Content-Type: application/json' -d "$body" "$BASE/storage.php?action=$action" "$@"
  else curl -s -H "Authorization: Bearer $token" "$BASE/storage.php?action=$action" "$@"; fi; }
store_code() { local token=$1 action=$2 body=${3:-}
  if [ -n "$body" ]; then curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $token" -H 'Content-Type: application/json' -d "$body" "$BASE/storage.php?action=$action"
  else curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $token" "$BASE/storage.php?action=$action"; fi; }
create_site() { manager create -F type=site -F title="$1" -F storage_mode="$2" -F "file=@/tmp/site.html;type=text/html" "${@:3}"; }

cat > /tmp/site.html <<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Test site</title>
</head>
<body><h1>Hello from the site</h1><script>document.title = 'ran';</script></body>
</html>
HTML

curl -s -o /dev/null -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
  -d "{\"username\":\"admin\",\"password\":\"psst\",\"htp\":\"$(htp)\"}" "$BASE/manage-api.php?action=login"

echo "== publishing and the sandbox"
r=$(create_site Shared shared)
S=$(echo "$r" | json share.uuid)
S_ID=$(sql "SELECT id FROM shares WHERE uuid = '$S'")
check "site created" site "$(echo "$r" | json share.type)"
check "storage mode returned" shared "$(echo "$r" | json share.site.storage_mode)"
contains "editor link returned" "&edit=" "$(echo "$r" | json share.site.editor_url)"
check "default shared quota" 1048576 "$(echo "$r" | json share.site.storage_quota_bytes)"
check "default visitor lifetime" 90 "$(echo "$r" | json share.site.visitor_lifetime_days)"
h=$(headers "$BASE/index.php?id=$S")
contains "served as HTML" "Content-Type: text/html; charset=utf-8" "$h"
contains "served in the sandbox" "Content-Security-Policy: sandbox allow-scripts allow-forms" "$h"
lacks "sandbox keeps an anonymous origin" "allow-same-origin" "$h"
p=$(curl -s "$BASE/index.php?id=$S")
contains "original HTML kept" "<h1>Hello from the site</h1>" "$p"
contains "storage script added right after <head>" '<head><script>(function () {' "$p"
contains "storage endpoint in the config" '"endpoint":"/storage.php"' "$p"
lacks "config placeholder replaced" "__PSST_CONFIG__" "$p"
check "page loads are counted" 2 "$(sql "SELECT view_count FROM shares WHERE id = $S_ID")"
r=$(create_site Off off)
O=$(echo "$r" | json share.uuid)
lacks "no script when storage is off" "window.psst" "$(curl -s "$BASE/index.php?id=$O")"
printf '<p>bare</p>' > /tmp/bare.html
B=$(manager create -F type=site -F storage_mode=shared -F "file=@/tmp/bare.html;type=text/html" | json share.uuid)
p=$(curl -s "$BASE/index.php?id=$B")
check "script prepended to a page without head" '<script>(' "${p:0:9}"
P=$(manager create -F type=site --form-string 'content=<p>pasted</p>' | json share.uuid)
contains "pasted HTML works" "<p>pasted</p>" "$(curl -s "$BASE/index.php?id=$P")"
X=$(create_site Secret shared -F secret_code=abc | json share.uuid)
p=$(curl -s "$BASE/index.php?id=$X")
contains "secret code asked before the site" 'name="secret_code"' "$p"
lacks "site not shown before the code" "Hello from the site" "$p"
contains "site shown with the code" "Hello from the site" "$(curl -s "$BASE/index.php?id=$X&_secretCode=abc")"
check "sites cannot be encrypted" 400 "$(manager_code create -F type=site --form-string 'content=<p>x</p>' -F is_encrypted=1)"
check "sites need HTML" 400 "$(manager_code create -F type=site)"
check "invalid storage mode refused" 400 "$(manager_code create -F type=site --form-string 'content=<p>x</p>' -F storage_mode=wild)"
check "view limit ignored for sites" "" "$(manager create -F type=site --form-string 'content=<p>x</p>' -F max_views=1 | json share.max_views)"

echo "== page tokens"
T=$(page_token "$BASE/index.php?id=$S")
h=$(curl -s -D - -o /dev/null -X OPTIONS -H 'Origin: null' -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: authorization, content-type' "$BASE/storage.php?action=set" | tr -d '\r')
contains "preflight answers 204" "HTTP/1.1 204" "$h"
contains "preflight allows any origin" "Access-Control-Allow-Origin: *" "$h"
contains "preflight allows the Authorization header" "Access-Control-Allow-Headers: Authorization, Content-Type" "$h"
check "no token is 401" 401 "$(code "$BASE/storage.php?action=list")"
check "garbage token is 401" 401 "$(store_code psst.garbage list)"
TAMPERED=$(php -r '[$p, $s] = explode(".", $argv[1]); $c = json_decode(base64_decode(strtr($p, "-_", "+/")), true); $c["ed"] = 1;
  echo rtrim(strtr(base64_encode(json_encode($c)), "+/", "-_"), "="), ".", $s;' "$T")
check "tampered token is 401" 401 "$(store_code "$TAMPERED" list)"
EXPIRED=$(php -r 'require "/var/www/html/functions.php"; echo psst_site_token_create(["u" => $argv[1], "v" => "", "ed" => 0, "e" => time() - 1]);' "$S")
check "expired token is 401" 401 "$(store_code "$EXPIRED" list)"
contains "storage answers CORS on normal requests" "Access-Control-Allow-Origin: *" "$(headers -H "Authorization: Bearer $T" "$BASE/storage.php?action=list")"

echo "== storage operations (shared)"
check "set returns version 1" 1 "$(store "$T" set '{"key":"items","value":["a"]}' | json version)"
check "get returns the value" '["a"]' "$(store "$T" get "" --url-query key=items | raw_json value)"
check "set again raises the version" 2 "$(store "$T" set '{"key":"items","value":["a","b"]}' | json version)"
r=$(curl -s -w '\n%{http_code}' -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -d '{"key":"items","value":[],"version":1}' "$BASE/storage.php?action=set")
check "stale version answers 409" 409 "$(echo "$r" | tail -1)"
check "conflict reports the current version" 2 "$(echo "$r" | head -1 | json version)"
check "matching version is accepted" 3 "$(store "$T" set '{"key":"items","value":["c"],"version":2}' | json version)"
store "$T" set '{"key":"empty","value":{}}' >/dev/null
check "empty object stays an object" '{}' "$(store "$T" get "" --url-query key=empty | raw_json value)"
check "missing key is null with version 0" "null|0" "$(store "$T" get "" --url-query key=nope | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode($d["value"]), "|", $d["version"];')"
store "$T" append '{"key":"log","item":{"n":1}}' >/dev/null
check "append adds to a list" 2 "$(store "$T" append '{"key":"log","item":{"n":2}}' | json length)"
check "append to a non-list is 409" 409 "$(store_code "$T" append '{"key":"empty","item":1}')"
check "increment creates a counter" 5 "$(store "$T" increment '{"key":"count","by":5}' | json value)"
check "increment defaults to 1" 6 "$(store "$T" increment '{"key":"count"}' | json value)"
check "increment of a non-number is 409" 409 "$(store_code "$T" increment '{"key":"items"}')"
check "increment by text is 400" 400 "$(store_code "$T" increment '{"key":"count","by":"x"}')"
check "list shows all keys" "count,empty,items,log" "$(store "$T" list | php -r 'echo implode(",", array_column(json_decode(stream_get_contents(STDIN), true)["items"], "key"));')"
check "remove reports true" true "$(store "$T" remove '{"key":"empty"}' | json removed)"
check "remove again reports false" false "$(store "$T" remove '{"key":"empty"}' | json removed)"
check "empty key is 400" 400 "$(store_code "$T" set '{"key":"","value":1}')"
check "201-character key is 400" 400 "$(store_code "$T" set "{\"key\":\"$(printf 'k%.0s' $(seq 1 201))\",\"value\":1}")"
check "missing value is 400" 400 "$(store_code "$T" set '{"key":"a"}')"
php -r 'echo json_encode(["key" => "big", "value" => str_repeat("x", 262200)]);' > /tmp/big.json
check "value over 256 KB is 413" 413 "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $T" -H 'Content-Type: application/json' --data-binary @/tmp/big.json "$BASE/storage.php?action=set")"
check "reads need GET" 405 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $T" "$BASE/storage.php?action=list")"
check "uuid parameter cannot switch sites" '["c"]' "$(store "$T" get "" --url-query key=items --url-query "uuid=$O" | raw_json value)"
check "data stored per site" 0 "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id != $S_ID AND item_key = 'items'")"

echo "== quotas and limits (shared)"
manager update --url-query "uuid=$S" -F type=site -F storage_quota_bytes=200 >/dev/null
r=$(curl -s -w '\n%{http_code}' -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -d "{\"key\":\"pad\",\"value\":\"$(printf 'x%.0s' $(seq 1 300))\"}" "$BASE/storage.php?action=set")
check "write over the quota is 413" 413 "$(echo "$r" | tail -1)"
contains "quota message" "Not enough storage left" "$r"
check "rejected write stored nothing" 0 "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = $S_ID AND item_key = 'pad'")"
check "shrinking still works over the quota" 200 "$(store_code "$T" set '{"key":"items","value":[]}')"
manager update --url-query "uuid=$S" -F type=site -F storage_quota_bytes= >/dev/null
for i in $(seq 1 60); do sql "INSERT INTO request_events (ip_address, event_type, created_at) VALUES ('127.0.0.1', 'site_write:$S_ID', $(date +%s))"; done
check "61st write within a minute is 429" 429 "$(store_code "$T" set '{"key":"x","value":1}')"
check "reads are not limited" 200 "$(store_code "$T" list)"
sql "DELETE FROM request_events WHERE event_type LIKE 'site_write:%'"

echo "== read-only mode and mode changes"
manager update --url-query "uuid=$S" -F type=site -F storage_mode=read >/dev/null
check "old shared token can no longer write" 403 "$(store_code "$T" set '{"key":"x","value":1}')"
check "old token can still read" 200 "$(store_code "$T" list)"
TR=$(page_token "$BASE/index.php?id=$S")
contains "visitor page knows it cannot write" '"canWrite":false' "$(curl -s "$BASE/index.php?id=$S")"
check "visitor token cannot write" 403 "$(store_code "$TR" set '{"key":"x","value":1}')"
EDIT=$(manager share --url-query "uuid=$S" | json share.site.editor_url | sed 's#http://localhost:8092#http://127.0.0.1#')
TE=$(page_token "$EDIT")
contains "editor page can write" '"canWrite":true' "$(curl -s "$EDIT")"
check "editor token writes" 200 "$(store_code "$TE" set '{"key":"x","value":1}')"
check "wrong edit key gives no write access" 403 "$(store_code "$(page_token "$BASE/index.php?id=$S&edit=nope")" set '{"key":"x","value":2}')"
manager update --url-query "uuid=$S" -F type=site -F storage_mode=off >/dev/null
check "storage off refuses old tokens" 403 "$(store_code "$TE" list)"
manager update --url-query "uuid=$S" -F type=site -F storage_mode=shared >/dev/null
check "editor key unchanged by edits" "$EDIT" "$(manager share --url-query "uuid=$S" | json share.site.editor_url | sed 's#http://localhost:8092#http://127.0.0.1#')"

echo "== per-visitor storage"
r=$(create_site Personal visitor -F visitor_lifetime_days=30)
V=$(echo "$r" | json share.uuid)
V_ID=$(sql "SELECT id FROM shares WHERE uuid = '$V'")
h=$(curl -s -D - -o /dev/null -c /tmp/v1 "$BASE/index.php?id=$V" | tr -d '\r')
contains "visitor cookie is HttpOnly" "HttpOnly" "$(echo "$h" | grep -i '^Set-Cookie: psst_v_')"
contains "visitor cookie is SameSite=Lax" "SameSite=Lax" "$(echo "$h" | grep -i '^Set-Cookie: psst_v_')"
contains "visitor cookie is scoped to the PSST path" "path=/" "$(echo "$h" | grep -i '^Set-Cookie: psst_v_')"
contains "remembered visitors get an expiry" "expires=" "$(echo "$h" | grep -i '^Set-Cookie: psst_v_')"
T1=$(page_token "$BASE/index.php?id=$V" -b /tmp/v1 -c /tmp/v1)
T1B=$(page_token "$BASE/index.php?id=$V" -b /tmp/v1 -c /tmp/v1)
T2=$(page_token "$BASE/index.php?id=$V" -c /tmp/v2)
check "same browser, same visitor" "$(claim "$T1" v)" "$(claim "$T1B" v)"
lacks "other browser, other visitor" "$(claim "$T1" v)" "$(claim "$T2" v)"
contains "page scope is visitor" '"scope":"visitor"' "$(curl -s -b /tmp/v1 "$BASE/index.php?id=$V")"
store "$T1" set '{"key":"note","value":"one"}' >/dev/null
check "visitor 2 does not see visitor 1's data" null "$(store "$T2" get "" --url-query key=note | raw_json value)"
store "$T2" set '{"key":"note","value":"two"}' >/dev/null
check "visitor 1 keeps its own value" '"one"' "$(store "$T1" get "" --url-query key=note | raw_json value)"
check "two visitor records" 2 "$(sql "SELECT COUNT(*) FROM site_visitors WHERE share_id = $V_ID")"
check "shared store untouched" 0 "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = $V_ID AND visitor_id = ''")"
manager update --url-query "uuid=$V" -F type=site -F visitor_quota_bytes=100 >/dev/null
check "per-visitor quota enforced" 413 "$(store_code "$T1" set "{\"key\":\"pad\",\"value\":\"$(printf 'x%.0s' $(seq 1 150))\"}")"
manager update --url-query "uuid=$V" -F type=site -F visitor_quota_bytes= -F visitor_total_bytes=40 >/dev/null
r=$(curl -s -w '\n%{http_code}' -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' -d '{"key":"more","value":"0123456789012345678901234567890"}' "$BASE/storage.php?action=set")
check "total visitor quota enforced" 413 "$(echo "$r" | tail -1)"
contains "total quota message" "all visitors" "$r"
manager update --url-query "uuid=$V" -F type=site -F visitor_total_bytes= >/dev/null
T3=$(page_token "$BASE/index.php?id=$V" -c /tmp/v3)
sql "UPDATE site_visitors SET last_seen = $(( $(date +%s) - 31 * 86400 )) WHERE share_id = $V_ID AND visitor_id = '$(claim "$T3" v)'"
curl -s -o /dev/null -b /tmp/v1 "$BASE/index.php?id=$V"
check "inactive visitor deleted after the lifetime" 0 "$(sql "SELECT COUNT(*) FROM site_visitors WHERE visitor_id = '$(claim "$T3" v)'")"
check "active visitors kept" 1 "$(sql "SELECT COUNT(*) FROM site_visitors WHERE visitor_id = '$(claim "$T1" v)'")"
SV=$(create_site Session visitor -F visitor_lifetime_days=0 | json share.uuid)
h=$(curl -s -D - -o /dev/null "$BASE/index.php?id=$SV" | tr -d '\r' | grep -i '^Set-Cookie: psst_v_')
lacks "session mode sets a session cookie" "expires=" "$h"

echo "== owner access in the manager"
check "storage actions need a login" 401 "$(code "$BASE/manage-api.php?action=storage_list&uuid=$V")"
r=$(manager visitors --url-query "uuid=$V")
check "manager lists the visitors" 2 "$(echo "$r" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["visitors"]);')"
V1=$(claim "$T1" v)
check "manager reads a visitor's value" '"one"' "$(manager storage_get --url-query "uuid=$V" --url-query "visitor=$V1" --url-query key=note | raw_json value)"
check "manager writes into a visitor store" 2 "$(mjson storage_set "uuid=$V&visitor=$V1" '{"key":"note","value":"edited"}' | json version)"
check "visitor sees the owner's change" '"edited"' "$(store "$T1" get "" --url-query key=note | raw_json value)"
check "unknown visitor is 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/manage-api.php?action=storage_list&uuid=$V&visitor=0123456789abcdef0123456789abcdef")"
check "owner writes in read-only mode too" 200 "$(manager update --url-query "uuid=$S" -F type=site -F storage_mode=read >/dev/null; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Content-Type: application/json' -d '{"key":"owner","value":true}' "$BASE/manage-api.php?action=storage_set&uuid=$S")"
EXPORT=$(manager storage_export --url-query "uuid=$V")
check "export contains every visitor" 2 "$(echo "$EXPORT" | php -r 'echo count((array) json_decode(stream_get_contents(STDIN))->visitors);')"
contains "export contains the values" '"edited"' "$EXPORT"
I=$(create_site Import visitor | json share.uuid)
check "import into another site" 2 "$(mjson storage_import "uuid=$I" "$EXPORT" | json imported)"
check "imported visitor readable" '"edited"' "$(manager storage_get --url-query "uuid=$I" --url-query "visitor=$V1" --url-query key=note | raw_json value)"
check "import with replace" 2 "$(mjson storage_import "uuid=$I&replace=1" "$EXPORT" | json imported)"
check "bad import document is 400" 400 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Content-Type: application/json' -d '{"shared":[1,2]}' "$BASE/manage-api.php?action=storage_import&uuid=$I")"
manager update --url-query "uuid=$I" -F type=site -F visitor_quota_bytes=5 >/dev/null
before=$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = (SELECT id FROM shares WHERE uuid = '$I')")
check "import over a quota is 413" 413 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Content-Type: application/json' -d "$EXPORT" "$BASE/manage-api.php?action=storage_import&uuid=$I&replace=1")"
check "failed import changed nothing" "$before" "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = (SELECT id FROM shares WHERE uuid = '$I')")"
check "delete one visitor" true "$(mjson storage_clear "uuid=$V&visitor=$V1" '{}' | json cleared)"
check "that visitor is gone" 1 "$(sql "SELECT COUNT(*) FROM site_visitors WHERE share_id = $V_ID")"
check "delete all data" true "$(mjson storage_clear "uuid=$V" '{}' | json cleared)"
check "site storage empty" "0|0" "$(sql "SELECT (SELECT COUNT(*) FROM site_storage WHERE share_id = $V_ID) || '|' || (SELECT COUNT(*) FROM site_visitors WHERE share_id = $V_ID)")"
TXT=$(manager create -F type=text -F content=x | json share.uuid)
check "storage actions refuse other share types" 400 "$(manager_code storage_list --url-query "uuid=$TXT")"

echo "== API"
KEY=$(manager api_key_create -H 'Content-Type: application/json' -d '{"name":"PLM"}' | json token)
OTHER=$(manager api_key_create -H 'Content-Type: application/json' -d '{"name":"Other"}' | json token)
api() { local key=$1 action=$2; shift 2; curl -s -H "Authorization: Bearer $key" "$BASE/api.php?action=$action" "$@"; }
api_code() { local key=$1 action=$2; shift 2; curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $key" "$BASE/api.php?action=$action" "$@"; }
r=$(api "$KEY" create -H 'Content-Type: application/json' -d '{"type":"site","title":"Quiz","content":"<html><head></head><body>v1</body></html>","storage_mode":"visitor","visitor_lifetime_days":7}')
A=$(echo "$r" | json share.uuid)
check "API creates a site" visitor "$(echo "$r" | json share.site.storage_mode)"
check "API site lifetime" 7 "$(echo "$r" | json share.site.visitor_lifetime_days)"
contains "status lists the site type" '"site"' "$(api "$KEY" status)"
check "API storage_set on own site" 1 "$(api "$KEY" storage_set --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"key":"questions","value":[1,2,3]}' | json version)"
check "API storage_append" 4 "$(api "$KEY" storage_append --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"key":"questions","item":4}' | json length)"
check "API storage_increment" 3 "$(api "$KEY" storage_increment --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"key":"n","by":3}' | json value)"
check "API storage_get" '[1,2,3,4]' "$(api "$KEY" storage_get --url-query "uuid=$A" --url-query key=questions | raw_json value)"
check "API storage_list" 2 "$(api "$KEY" storage_list --url-query "uuid=$A" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["items"]);')"
check "API visitors" '[]' "$(api "$KEY" visitors --url-query "uuid=$A" | raw_json visitors)"
check "API storage_export" "$A" "$(api "$KEY" storage_export --url-query "uuid=$A" | json site)"
check "API storage_remove" true "$(api "$KEY" storage_remove --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"key":"n"}' | json removed)"
check "other key cannot read the storage" 404 "$(api_code "$OTHER" storage_list --url-query "uuid=$A")"
check "API cannot reach manager sites" 404 "$(api_code "$KEY" storage_list --url-query "uuid=$S")"
check "storage write via GET is 405" 405 "$(api_code "$KEY" storage_set --url-query "uuid=$A")"
OLD_FILE=$(sql "SELECT stored_filename FROM shares WHERE uuid = '$A'")
r=$(api "$KEY" update --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"content":"<html><head></head><body>v2</body></html>"}')
check "update keeps the link" "$A" "$(echo "$r" | json share.uuid)"
contains "update replaced the HTML" "v2" "$(curl -s "$BASE/index.php?id=$A")"
check "update kept the stored data" '[1,2,3,4]' "$(api "$KEY" storage_get --url-query "uuid=$A" --url-query key=questions | raw_json value)"
check "old HTML file removed" 0 "$(ls "$FILES" | grep -c "$OLD_FILE")"
check "update of the title only keeps the HTML" v2 "$(api "$KEY" update --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"title":"Quiz 2"}' >/dev/null; curl -s "$BASE/index.php?id=$A" | grep -o '<body>v2</body>' | sed 's/<[^>]*>//g')"
check "update keeps the mode" visitor "$(api "$KEY" share --url-query "uuid=$A" | json share.site.storage_mode)"
T_ID=$(api "$KEY" create -H 'Content-Type: application/json' -d '{"type":"text","content":"old"}' | json share.uuid)
check "update works for other types" new "$(api "$KEY" update --url-query "uuid=$T_ID" -H 'Content-Type: application/json' -d '{"content":"new"}' >/dev/null; curl -s "$BASE/index.php?id=$T_ID")"
check "update of another key's share is 404" 404 "$(api_code "$OTHER" update --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"title":"x"}')"
check "encrypted update refused" 400 "$(api_code "$KEY" update --url-query "uuid=$A" -H 'Content-Type: application/json' -d '{"is_encrypted":true}')"
php -r 'echo json_encode(["type" => "site", "content" => str_repeat("x", 5242881)]);' > /tmp/huge.json
check "HTML over 5 MB is 413" 413 "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' --data-binary @/tmp/huge.json "$BASE/api.php?action=create")"

echo "== lifecycle"
mjson storage_set "uuid=$S" '{"key":"keep","value":1}' >/dev/null
manager update --url-query "uuid=$S" -F type=text -F content=now-text >/dev/null
check "changing the type removes site data" 0 "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = $S_ID")"
check "and the HTML file" 0 "$(sql "SELECT COUNT(*) FROM shares WHERE id = $S_ID AND stored_filename IS NOT NULL")"
D=$(create_site Doomed shared | json share.uuid)
D_ID=$(sql "SELECT id FROM shares WHERE uuid = '$D'")
store "$(page_token "$BASE/index.php?id=$D")" set '{"key":"a","value":1}' >/dev/null
sql "UPDATE shares SET expires_at = $(( $(date +%s) - 5 )) WHERE id = $D_ID"
check "expired site answers 404" 404 "$(code "$BASE/index.php?id=$D")"
check "expired site's storage deleted" 0 "$(sql "SELECT COUNT(*) FROM site_storage WHERE share_id = $D_ID")"
api "$KEY" delete -X DELETE --url-query "uuid=$A" >/dev/null
check "deleted site's visitors and data gone" 0 "$(sql "SELECT COUNT(*) FROM site_storage s JOIN shares x ON x.id = s.share_id WHERE x.uuid = '$A'")"

echo "== documentation"
p=$(curl -s -b "$JAR" "$BASE/api-docs.php?doc=sites")
contains "site guide renders" '<h1 id="md-building-sites-for-psst">' "$p"
lacks "site guide placeholders replaced" "{{" "$p"
contains "site guide shows the real write limit" "More than 60 changes per minute" "$p"
p=$(curl -s -b "$JAR" "$BASE/api-docs.php")
contains "API docs describe site storage" '<h3 id="md-site-storage">Site storage</h3>' "$p"
contains "API docs link to the Sites section" 'href="#md-sites"' "$p"
lacks "API docs placeholders replaced" "{{" "$p"
check "site guide source not reachable" 403 "$(code "$BASE/docs/sites.md")"
echo "DONE"
