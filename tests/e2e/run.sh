#!/usr/bin/env bash
#
# End-to-end characterization suite for FluxBB.
#
# Installs a fresh forum over HTTP against the configured database and
# exercises the main user flows, asserting on rendered output and an
# empty PHP error log. Run it against different DB_TYPEs to prove a
# database-layer change is behavior-preserving:
#
#   DB_TYPE=mysqli DB_NAME=fluxbb_e2e ./tests/e2e/run.sh
#   DB_TYPE=mysql  DB_NAME=fluxbb_e2e ./tests/e2e/run.sh     # PDO MySQL
#   DB_TYPE=sqlite DB_NAME=/tmp/fluxbb-e2e.sqlite ./tests/e2e/run.sh
#
set -u

DB_TYPE="${DB_TYPE:-mysqli}"
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-fluxbb_e2e}"
DB_USER="${DB_USER:-flux}"
DB_PASS="${DB_PASS:-fluxpass}"
PORT="${PORT:-8093}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
ERRLOG="$TMP/php-errors.log"
JAR="$TMP/cookies.txt"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() { # file needle label
  if grep -qF -- "$2" "$1"; then ok "$3"; else fail "$3 (missing: $2)"; fi
}
assert_code() { # expected actual label
  if [ "$1" = "$2" ]; then ok "$3"; else fail "$3 (HTTP $2, wanted $1)"; fi
}

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

cd "$ROOT"

echo "== e2e: DB_TYPE=$DB_TYPE =="

# --- reset state -----------------------------------------------------------
rm -f config.php cache/cache_*.php cache/.htaccess
php -r '
$type = $argv[1]; $host = $argv[2]; $name = $argv[3]; $user = $argv[4]; $pass = $argv[5];
if ($type === "sqlite") { @unlink($name); exit(0); }
if (strpos($type, "pgsql") === 0) {
	$pdo = new PDO("pgsql:host=$host;dbname=$name", $user, $pass);
	$pdo->exec("DROP SCHEMA public CASCADE"); $pdo->exec("CREATE SCHEMA public");
	exit(0);
}
$pdo = new PDO("mysql:host=$host;dbname=$name", $user, $pass);
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t)
	$pdo->exec("DROP TABLE `$t`");
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" || { echo "DB reset failed"; exit 1; }

# --- start server ----------------------------------------------------------
php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

# --- install ---------------------------------------------------------------
code=$(curl -s -o "$TMP/install.html" -w "%{http_code}" "$BASE/install.php" \
  --data-urlencode "form_sent=1" \
  --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=$DB_TYPE" \
  --data-urlencode "req_db_host=$DB_HOST" \
  --data-urlencode "req_db_name=$DB_NAME" \
  --data-urlencode "db_username=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" \
  --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" \
  --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" \
  --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=Test Forum" \
  --data-urlencode "desc=E2E" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" \
  --data-urlencode "req_default_style=Air" \
  --data-urlencode "start=Start install")
assert_code 200 "$code" "installer responds"
[ -f config.php ] && ok "config.php written" || fail "config.php written"
if grep -qiE "fatal|uncaught" "$TMP/install.html"; then fail "installer page free of fatals"; else ok "installer page free of fatals"; fi

# --- guest browsing --------------------------------------------------------
code=$(curl -s -o "$TMP/index.html" -w "%{http_code}" "$BASE/index.php")
assert_code 200 "$code" "guest index"
assert_contains "$TMP/index.html" "<title>Test Forum" "index title"
assert_contains "$TMP/index.html" "Powered by" "index footer"

# --- admin login (with real CSRF token) ------------------------------------
TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"
curl -s -b "$JAR" "$BASE/index.php" -o "$TMP/in.html"
assert_contains "$TMP/in.html" "Logged in as <strong>admin" "admin login"

# --- post a topic with BBCode + UTF-8 --------------------------------------
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/post.php?fid=1" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/post.php?fid=1" -o /dev/null -L "$BASE/post.php?fid=1" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_subject=E2E topic" \
  --data-urlencode "req_message=Hello [b]bold[/b] héllo wörld 日本語" \
  --data-urlencode "submit=Submit"
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/topic.html"
assert_contains "$TMP/topic.html" "<strong>bold</strong>" "BBCode rendered"
assert_contains "$TMP/topic.html" "héllo wörld 日本語" "UTF-8 round-trip"

# --- reply -----------------------------------------------------------------
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/post.php?tid=2" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/post.php?tid=2" -o /dev/null -L "$BASE/post.php?tid=2" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_message=E2E reply body" --data-urlencode "submit=Submit"
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/topic2.html"
assert_contains "$TMP/topic2.html" "E2E reply body" "reply visible"

# --- search ----------------------------------------------------------------
curl -s -b "$JAR" -L -e "$BASE/search.php" "$BASE/search.php?action=search&keywords=E2E" -o "$TMP/search.html"
assert_contains "$TMP/search.html" "E2E" "search finds posts"

# --- registration (dodge the IP flood guard by backdating admin) -----------
php -r '
$type = $argv[1]; $host = $argv[2]; $name = $argv[3]; $user = $argv[4]; $pass = $argv[5];
$dsn = $type === "sqlite" ? "sqlite:$name" : ((strpos($type, "pgsql") === 0 ? "pgsql" : "mysql").":host=$host;dbname=$name");
$pdo = new PDO($dsn, $type === "sqlite" ? null : $user, $type === "sqlite" ? null : $pass);
// Single-quoted literals: double quotes are identifiers in PostgreSQL
$pdo->exec("UPDATE users SET registered = registered - 7200, registration_ip = ".$pdo->quote("10.9.9.9")." WHERE username = ".$pdo->quote("admin"));
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" 2>/dev/null || true
TOKEN=$(curl -s -c "$TMP/reg.txt" "$BASE/register.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$TMP/reg.txt" -c "$TMP/reg.txt" -e "$BASE/register.php" -o /dev/null -L "$BASE/register.php?action=register" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_user=e2euser" \
  --data-urlencode "req_password1=e2epass123" --data-urlencode "req_password2=e2epass123" \
  --data-urlencode "req_email1=e2e@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "language=English" \
  --data-urlencode "register=Register"
curl -s -b "$TMP/reg.txt" "$BASE/index.php" -o "$TMP/reg.html"
assert_contains "$TMP/reg.html" "Logged in as <strong>e2euser" "registration + auto-login"

# --- admin + misc pages ----------------------------------------------------
for p in userlist.php help.php "extern.php?action=feed&type=atom" admin_index.php admin_options.php admin_users.php admin_maintenance.php; do
  code=$(curl -s -b "$JAR" -e "$BASE/index.php" -o "$TMP/page.html" -w "%{http_code}" "$BASE/$p")
  assert_code 200 "$code" "page $p"
  if grep -qiE "fatal error|uncaught" "$TMP/page.html"; then fail "page $p free of fatals"; else ok "page $p free of fatals"; fi
done

# --- error log must be clean ----------------------------------------------
if [ -s "$ERRLOG" ]; then
  fail "php error log empty"
  sed 's/^/    | /' "$ERRLOG" | head -20
else
  ok "php error log empty"
fi

echo "== e2e($DB_TYPE): $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
