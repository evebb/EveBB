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

# Random ports so concurrent/stale runs never collide
PORT="${PORT:-$((8200 + RANDOM % 500))}"
FEED_PORT="${FEED_PORT:-$((8700 + RANDOM % 500))}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CURRENT_VERSION=$(grep -oE "define\('FORUM_VERSION', '[^']+'\)" "$ROOT/include/common.php" | grep -oE "'[0-9][^']*'" | tr -d "'")
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
assert_not_contains() {
  if grep -qF -- "$2" "$1"; then fail "$3 (unexpectedly found: $2)"; else ok "$3"; fi
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
# exclude any config.php / caches a previous test run left in the tree —
# with a config.php present the installer would silently skip
tar -C "$ROOT" --exclude=.git --exclude=tests --exclude=.github \
    --exclude=config.php --exclude='cache/cache_*.php' -cf - . | tar -C "$OLD" -xf -
rm -f "$OLD/config.php" "$OLD"/cache/cache_*.php
# Lower the version in BOTH files so the installed database records the
# old version too - this makes the post-update db_update flow run, which
# is exactly what a real upgraded board goes through
sed -i "s/define('FORUM_VERSION', '[^']*');/define('FORUM_VERSION', '0.9.0');/" "$OLD/include/common.php" "$OLD/install.php"
grep -q "0.9.0" "$OLD/include/common.php" && ok "old install prepared (0.9.0)" || fail "old install prepared"

# --- build the release package + feed --------------------------------------
PKG="$WORK/feed/evebb-$CURRENT_VERSION.zip"
mkdir -p "$WORK/feed"
(cd "$ROOT" && zip -rq "$PKG" . -x '.git/*' -x 'tests/*' -x '.github/*' -x 'config.php' -x 'cache/cache_*.php')
cat > "$WORK/feed/releases.json" <<EOF
[
  {
    "tag_name": "v$CURRENT_VERSION",
    "draft": false,
    "prerelease": true,
    "html_url": "$FEED/notes",
    "zipball_url": "$FEED/evebb-$CURRENT_VERSION.zip",
    "assets": [
      {"name": "evebb-$CURRENT_VERSION.zip", "browser_download_url": "$FEED/evebb-$CURRENT_VERSION.zip"}
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
if [ -f "$OLD/config.php" ] && grep -qF "forum.sqlite" "$OLD/config.php"; then
  ok "old forum installed"
else
  fail "old forum installed (config missing or not pointing at the test database)"
fi

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
assert_contains "$WORK/check.html" "A new release is available: eveBB $CURRENT_VERSION" "new release detected"
assert_contains "$WORK/check.html" "Update to $CURRENT_VERSION" "update button offered"

# --- run the one-click update ----------------------------------------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/check.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" -o "$WORK/update.html" "$BASE/admin_maintenance.php" \
  --data-urlencode "action=do_update" \
  --data-urlencode "csrf_token=$TOKEN"
assert_contains "$WORK/update.html" "The forum was updated to eveBB $CURRENT_VERSION" "update reports success"

# --- verify the results on disk and over HTTP ------------------------------
grep -q "define('FORUM_VERSION', '$CURRENT_VERSION')" "$OLD/include/common.php" \
  && ok "files replaced (FORUM_VERSION now $CURRENT_VERSION)" || fail "files replaced"
grep -q "config-sentinel-do-not-lose" "$OLD/config.php" \
  && ok "config.php preserved" || fail "config.php preserved"
[ ! -d "$OLD/cache/evebb_update_tmp" ] && ok "temp files cleaned up" || fail "temp files cleaned up"
[ ! -f "$OLD/cache/evebb_update.zip" ] && ok "downloaded zip cleaned up" || fail "downloaded zip cleaned up"

# the database still records 0.9.0, so the forum must now demand the
# database update wizard (regression check for the eveBB-version
# mismatch bug: 1.0.x compares lower than FluxBB's 1.2 floor)
curl -s -b "$JAR" -o /dev/null -w "%{url_effective}" -L "$BASE/index.php" | grep -q "db_update.php" \
  && ok "redirected to database update" || fail "redirected to database update"

curl -s -b "$JAR" "$BASE/db_update.php" -o "$WORK/dbup.html"
assert_not_contains "$WORK/dbup.html" "Version mismatch" "eveBB version accepted by db_update"

# run the wizard: for SQLite the confirmation credential is the db file
# path; stages advance via meta-refresh, so walk them like a browser
curl -s -b "$JAR" -e "$BASE/db_update.php" "$BASE/db_update.php" \
  --data-urlencode "form_sent=1" \
  --data-urlencode "stage=start" \
  --data-urlencode "req_db_pass=$WORK/forum.sqlite" \
  --data-urlencode "req_maintenance_message=" \
  -o "$WORK/dbup2.html"
for i in $(seq 1 30); do
  NEXT=$(grep -oE 'url=db_update\.php[^"]*' "$WORK/dbup2.html" | head -1 | sed 's/^url=//')
  [ -z "$NEXT" ] && break
  curl -s -b "$JAR" "$BASE/$NEXT" -o "$WORK/dbup2.html"
done
assert_contains "$WORK/dbup2.html" "successfully updated" "database update completes"

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
