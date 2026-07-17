#!/usr/bin/env bash
#
# End-to-end test of the one-click self-updater.
#
# Builds a fake "old" installation (version 0.9.0) from the current
# tree, serves the current tree as a release package from a local
# releases feed, then exercises Admin -> Maintenance -> check/update
# over HTTP and verifies the files were replaced and the forum still
# works. Uses SQLite, so no database server is needed.
#
set -u

PORT="${PORT:-8113}"
FEED_PORT="${FEED_PORT:-8114}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
FEED="http://127.0.0.1:$FEED_PORT"
WORK="$(mktemp -d)"
OLD="$WORK/forum"
ERRLOG="$WORK/php-errors.log"
JAR="$WORK/cookies.txt"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() {
  if grep -qF -- "$2" "$1"; then ok "$3"; else fail "$3 (missing: $2)"; fi
}

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  [ -n "${FEED_PID:-}" ] && kill "$FEED_PID" 2>/dev/null
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "== self-updater e2e =="

# --- build the "old" installation (current code, version lowered) ----------
mkdir -p "$OLD"
tar -C "$ROOT" --exclude=.git --exclude=tests --exclude=.github -cf - . | tar -C "$OLD" -xf -
sed -i "s/define('FORUM_VERSION', '[^']*');/define('FORUM_VERSION', '0.9.0');/" "$OLD/include/common.php"
grep -q "0.9.0" "$OLD/include/common.php" && ok "old install prepared (0.9.0)" || fail "old install prepared"

# --- build the release package + feed --------------------------------------
PKG="$WORK/feed/evebb-1.0.0-alpha.zip"
mkdir -p "$WORK/feed"
(cd "$ROOT" && zip -rq "$PKG" . -x '.git/*' -x 'tests/*' -x '.github/*' -x 'config.php' -x 'cache/cache_*.php')
cat > "$WORK/feed/releases.json" <<EOF
[
  {
    "tag_name": "v1.0.0-alpha",
    "draft": false,
    "prerelease": true,
    "html_url": "$FEED/notes",
    "zipball_url": "$FEED/evebb-1.0.0-alpha.zip",
    "assets": [
      {"name": "evebb-1.0.0-alpha.zip", "browser_download_url": "$FEED/evebb-1.0.0-alpha.zip"}
    ]
  }
]
EOF
ok "release package + feed built"

# --- start servers ---------------------------------------------------------
(cd "$WORK/feed" && php -S 127.0.0.1:"$FEED_PORT" >/dev/null 2>&1) & FEED_PID=$!
(cd "$OLD" && php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1) & SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

# --- install the old forum (SQLite) ----------------------------------------
curl -s -o /dev/null "$BASE/install.php" \
  --data-urlencode "form_sent=1" --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=sqlite" --data-urlencode "req_db_host=localhost" \
  --data-urlencode "req_db_name=$WORK/forum.sqlite" \
  --data-urlencode "db_username=" --data-urlencode "db_password=" \
  --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=Update Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Air" \
  --data-urlencode "start=Start install"
[ -f "$OLD/config.php" ] && ok "old forum installed" || fail "old forum installed"

# point the updater at the local feed, and mark config so we can prove
# it survives the update
echo "define('FORUM_UPDATE_API', '$FEED/releases.json');" >> "$OLD/config.php"
echo "// config-sentinel-do-not-lose" >> "$OLD/config.php"

# --- log in as admin -------------------------------------------------------
TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"

# --- maintenance page shows the updates section ----------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_maintenance.php" -o "$WORK/maint.html"
assert_contains "$WORK/maint.html" "You are running eveBB <strong>0.9.0</strong>" "current version shown"
assert_contains "$WORK/maint.html" "Check for updates" "check button present"

# --- check for updates -----------------------------------------------------
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" "$BASE/admin_maintenance.php?action=check_update" -o "$WORK/check.html"
assert_contains "$WORK/check.html" "A new release is available: eveBB 1.0.0-alpha" "new release detected"
assert_contains "$WORK/check.html" "Update to 1.0.0-alpha" "update button offered"

# --- run the one-click update ----------------------------------------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/check.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" -o "$WORK/update.html" "$BASE/admin_maintenance.php" \
  --data-urlencode "action=do_update" \
  --data-urlencode "csrf_token=$TOKEN"
assert_contains "$WORK/update.html" "The forum was updated to eveBB 1.0.0-alpha" "update reports success"

# --- verify the results on disk and over HTTP ------------------------------
grep -q "define('FORUM_VERSION', '1.0.0-alpha')" "$OLD/include/common.php" \
  && ok "files replaced (FORUM_VERSION now 1.0.0-alpha)" || fail "files replaced"
grep -q "config-sentinel-do-not-lose" "$OLD/config.php" \
  && ok "config.php preserved" || fail "config.php preserved"
[ ! -d "$OLD/cache/evebb_update_tmp" ] && ok "temp files cleaned up" || fail "temp files cleaned up"
[ ! -f "$OLD/cache/evebb_update.zip" ] && ok "downloaded zip cleaned up" || fail "downloaded zip cleaned up"

curl -s -b "$JAR" -L "$BASE/index.php" -o "$WORK/after.html" -w "%{http_code} %{url_effective}\n" > "$WORK/after.meta"
if grep -qF "Update Test" "$WORK/after.html"; then
  ok "forum works after update"
else
  fail "forum works after update (missing: Update Test)"
  echo "    | $(cat "$WORK/after.meta")"
  sed -n '1,12p' "$WORK/after.html" | sed 's/^/    | /'
fi
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" "$BASE/admin_maintenance.php?action=check_update" -o "$WORK/check2.html"
assert_contains "$WORK/check2.html" "You are running the latest release" "now reports up to date"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -10
else
  ok "php error log empty"
fi

echo "== self-updater e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
