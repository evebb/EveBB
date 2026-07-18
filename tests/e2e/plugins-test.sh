#!/usr/bin/env bash
#
# End-to-end test of the WordPress-style plugin manager: upload a
# plugin zip, see it inactive, activate it (its hook fires), open its
# settings, deactivate it (hook stops), confirm it survives a core
# update, then delete it. Uses SQLite.
#
set -u

PORT="${PORT:-$((9600 + RANDOM % 400))}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
ERRLOG="$WORK/php-errors.log"
JAR="$WORK/cookies.txt"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() {
  if grep -qF -- "$2" "$1"; then ok "$3"; else fail "$3 (missing: $2)"; fi
}
assert_not_contains() {
  if grep -qF -- "$2" "$1"; then fail "$3 (unexpectedly found: $2)"; else ok "$3"; fi
}

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -rf "$WORK" "$ROOT/plugins/testplug"
}
trap cleanup EXIT

cd "$ROOT"

echo "== plugin manager e2e =="

# --- build a throwaway test plugin zip -------------------------------------
mkdir -p "$WORK/testplug"
cat > "$WORK/testplug/plugin.json" <<'EOF'
{
  "name": "Test Plugin",
  "slug": "testplug",
  "version": "0.1",
  "author": "e2e",
  "description": "Uploaded by the plugin manager test.",
  "addon": "testplug.php"
}
EOF
cat > "$WORK/testplug/testplug.php" <<'EOF'
<?php
if (!defined('PUN')) exit;
class plugin_testplug extends flux_addon
{
	function register($manager) { $manager->bind('header_head_end', array($this, 'mark')); }
	function mark() { echo '<meta name="testplug-active" content="yes" />'."\n"; }
}
EOF
(cd "$WORK" && zip -rq "$WORK/testplug.zip" testplug)
ok "test plugin packaged"

# --- fresh install ---------------------------------------------------------
rm -f config.php cache/cache_*.php
php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

curl -s -o /dev/null "$BASE/install.php" \
  --data-urlencode "form_sent=1" --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=sqlite" --data-urlencode "req_db_host=localhost" \
  --data-urlencode "req_db_name=$WORK/forum.sqlite" \
  --data-urlencode "db_username=" --data-urlencode "db_password=" \
  --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=Plugin Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install"
[ -f config.php ] && ok "forum installed" || fail "forum installed"

TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"

# --- Plugins menu item + manager page --------------------------------------
curl -s -b "$JAR" "$BASE/admin_index.php" -o "$WORK/adminidx.html"
assert_contains "$WORK/adminidx.html" "admin_plugins.php" "Plugins menu item present"

curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr.html"
assert_contains "$WORK/mgr.html" "Installed plugins" "manager page renders"
assert_contains "$WORK/mgr.html" "Hello eveBB" "bundled example plugin listed"

# --- upload the test plugin ------------------------------------------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/mgr.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=upload" \
  -F "csrf_token=$TOKEN" -F "upload_plugin=1" -F "plugin_file=@$WORK/testplug.zip;type=application/zip"
[ -f "$ROOT/plugins/testplug/plugin.json" ] && ok "plugin uploaded to disk" || fail "plugin uploaded to disk"

curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr2.html"
assert_contains "$WORK/mgr2.html" "Test Plugin" "uploaded plugin listed"

# inactive by default: hook must not fire yet
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post.html"
assert_not_contains "$WORK/post.html" "testplug-active" "inactive plugin hook does not fire"

# --- activate --------------------------------------------------------------
CSRF=$(grep -oE 'csrf_token=[a-f0-9]{20,}' "$WORK/mgr2.html" | head -1 | cut -d= -f2)
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=activate&plugin=testplug&csrf_token=$CSRF"
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post2.html"
assert_contains "$WORK/post2.html" "testplug-active" "active plugin hook fires"
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr3.html"
assert_contains "$WORK/mgr3.html" ">Active<" "manager shows plugin active"

# --- bundled hello: activate and open its settings -------------------------
CSRF=$(grep -oE 'action=activate&amp;plugin=hello&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr3.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=activate&plugin=hello&csrf_token=$CSRF"
curl -s -b "$JAR" "$BASE/index.php" -o "$WORK/idx.html"
assert_contains "$WORK/idx.html" 'name="evebb-hello"' "hello plugin injects marker when active"
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" "$BASE/admin_plugins.php?action=settings&plugin=hello" -o "$WORK/settings.html"
assert_contains "$WORK/settings.html" "Hello eveBB settings" "plugin settings page renders"

# --- deactivate testplug ---------------------------------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr4.html"
CSRF=$(grep -oE 'action=deactivate&amp;plugin=testplug&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr4.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=deactivate&plugin=testplug&csrf_token=$CSRF"
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post3.html"
assert_not_contains "$WORK/post3.html" "testplug-active" "deactivated plugin hook stops"

# --- survives a core update: the updater copies release files over the tree
# but must never touch an uploaded plugin folder that is not in the package
mkdir -p "$WORK/fakepkg"
tar -C "$ROOT" --exclude=.git --exclude=tests --exclude=.github \
    --exclude=config.php --exclude='cache/cache_*.php' --exclude='plugins/testplug' -cf - . | tar -C "$WORK/fakepkg" -xf -
php -r '
require "'"$ROOT"'/include/update.php";
' 2>/dev/null
# emulate the updater copy step (copy_tree preserves paths, never deletes)
php -r '
define("PUN", 1); define("PUN_ROOT", "'"$ROOT"'/");
require PUN_ROOT."include/update.php";
$log = array();
evebb_update_copy_tree("'"$WORK"'/fakepkg", rtrim(PUN_ROOT, "/"), "", $log);
echo "copied\n";
'
[ -f "$ROOT/plugins/testplug/plugin.json" ] && ok "uploaded plugin survives core update" || fail "uploaded plugin survives core update"

# --- delete ----------------------------------------------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr5.html"
CSRF=$(grep -oE 'action=delete&amp;plugin=testplug&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr5.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=delete&plugin=testplug&csrf_token=$CSRF"
[ ! -d "$ROOT/plugins/testplug" ] && ok "plugin deleted from disk" || fail "plugin deleted from disk"

# --- rejects a zip-slip archive --------------------------------------------
mkdir -p "$WORK/evil"
cat > "$WORK/evil/plugin.json" <<'EOF'
{"name":"Evil","slug":"evil","version":"1.0"}
EOF
(cd "$WORK" && zip -rq "$WORK/evil.zip" evil && printf 'pwned' > "$WORK/x" && zip -q "$WORK/evil.zip" --junk-paths "$WORK/x" && printf '@ x\n@=../../evil-escape.txt\n' | zipnote -w "$WORK/evil.zip" 2>/dev/null || true)
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" "$BASE/admin_plugins.php" -o "$WORK/mgr6.html"
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/mgr6.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o "$WORK/evilresp.html" -L "$BASE/admin_plugins.php?action=upload" \
  -F "csrf_token=$TOKEN" -F "upload_plugin=1" -F "plugin_file=@$WORK/evil.zip;type=application/zip"
[ ! -f "$ROOT/../evil-escape.txt" ] && ok "zip-slip archive did not escape" || { fail "zip-slip archive did not escape"; rm -f "$ROOT/../evil-escape.txt"; }
rm -rf "$ROOT/plugins/evil"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -12
else
  ok "php error log empty"
fi

rm -f "$ROOT/config.php" "$ROOT"/cache/cache_*.php

echo "== plugin manager e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
