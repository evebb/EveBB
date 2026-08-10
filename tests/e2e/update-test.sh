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
  [ -f "${WORK:-}/install.php.e2e-orig" ] && cp "$WORK/install.php.e2e-orig" "$ROOT/install.php" 2>/dev/null
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  [ -n "${FEED_PID:-}" ] && kill "$FEED_PID" 2>/dev/null
  rm -rf "$WORK"
}
trap cleanup EXIT

# The 2.0.4 installer removes itself after a successful install, which
# is right for production and fatal for a suite that serves the repo
# root: the next install (this script re-run, or the next driver or
# suite in CI) 404s. Snapshot it and restore after installing.
cp "$ROOT/install.php" "$WORK/install.php.e2e-orig"
restore_installer() { [ -f "$ROOT/install.php" ] || cp "$WORK/install.php.e2e-orig" "$ROOT/install.php"; }

echo "== self-updater e2e =="

# --- build the "old" installation (current code, version lowered) ----------
mkdir -p "$OLD"
# exclude any config.php / caches a previous test run left in the tree —
# with a config.php present the installer would silently skip
tar -C "$ROOT" --exclude=.git --exclude=tests --exclude=.github --exclude=website \
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
(cd "$ROOT" && zip -rq "$PKG" . -x '.git/*' -x 'tests/*' -x '.github/*' -x 'website/*' -x 'config.php' -x 'cache/cache_*.php')
# publish a SHA-256 checksum alongside the package, exactly as release.yml does
(cd "$WORK/feed" && sha256sum "evebb-$CURRENT_VERSION.zip" > "evebb-$CURRENT_VERSION.zip.sha256")
GOOD_SHA=$(cut -d' ' -f1 "$WORK/feed/evebb-$CURRENT_VERSION.zip.sha256")
cat > "$WORK/feed/releases.json" <<EOF
[
  {
    "tag_name": "v$CURRENT_VERSION",
    "draft": false,
    "prerelease": true,
    "html_url": "$FEED/notes",
    "zipball_url": "$FEED/evebb-$CURRENT_VERSION.zip",
    "assets": [
      {"name": "evebb-$CURRENT_VERSION.zip", "browser_download_url": "$FEED/evebb-$CURRENT_VERSION.zip"},
      {"name": "evebb-$CURRENT_VERSION.zip.sha256", "browser_download_url": "$FEED/evebb-$CURRENT_VERSION.zip.sha256"}
    ]
  }
]
EOF
ok "release package + checksum + feed built"

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
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install"
restore_installer

# --- simulate a board that ran the official tfa PLUGIN ---------------------
# The fresh installer already creates the core 2FA tables (asserted here for
# free). A real upgrading board predates them and instead has the plugin's
# tables, with a member enrolled. Drop core's and recreate the plugin's with
# data, so the guided update has to absorb them rather than create them.
TFA_FRESH=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type=\"table\" AND name IN (\"tfa_users\",\"tfa_backup\")")->fetchColumn();')
[ "$TFA_FRESH" = "2" ] && ok "fresh install creates the 2FA tables" || fail "fresh install creates the 2FA tables (found $TFA_FRESH)"

php -r '
$p = new PDO("sqlite:'"$WORK"'/forum.sqlite");
$p->exec("DROP TABLE tfa_users");
$p->exec("DROP TABLE tfa_backup");
$p->exec("CREATE TABLE tfa_users (user_id INTEGER NOT NULL DEFAULT 0, secret VARCHAR(32) NOT NULL DEFAULT \"\", last_slot INTEGER NOT NULL DEFAULT 0, enabled_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id))");
$p->exec("CREATE TABLE tfa_backup (user_id INTEGER NOT NULL DEFAULT 0, code_hash VARCHAR(64) NOT NULL DEFAULT \"\", PRIMARY KEY (user_id, code_hash))");
// Enrol a MEMBER (id 42), never the admin: user 2 is the administrator this
// suite logs in as, and enrolling them would - correctly - stop a
// password-only login and fail every later assertion.
$p->exec("INSERT INTO users (id, username, group_id, password, email, registered, registration_ip, last_visit, language, style, num_posts) VALUES (42, \"enrolled\", 4, \"x\", \"enrolled@example.com\", 1750000000, \"127.0.0.1\", 1750000000, \"English\", \"Carbon\", 0)");
$p->exec("INSERT INTO tfa_users (user_id, secret, last_slot, enabled_at) VALUES (42, \"GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ\", 987654, 1750000000)");
$p->exec("INSERT INTO tfa_backup (user_id, code_hash) VALUES (42, \"aaaa1111\"), (42, \"bbbb2222\")");
$p->exec("INSERT INTO config (conf_name, conf_value) VALUES (\"o_tfa_db_rev\", \"1\")");
' && ok "plugin-era 2FA tables seeded with an enrolled member" || fail "plugin-era 2FA tables seeded"

if [ -f "$OLD/config.php" ] && grep -qF "forum.sqlite" "$OLD/config.php"; then
  ok "old forum installed"
else
  fail "old forum installed (config missing or not pointing at the test database)"
fi

# seed the retired BBCode toolbar plugin's leftovers, to prove the upgrade
# tidies them away (config rows + its slug in the active-plugins list)
php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");
$p->exec("DELETE FROM config WHERE conf_name IN (\"o_toolbar_style\",\"o_toolbar_smilies\")");
$p->exec("INSERT INTO config (conf_name,conf_value) VALUES (\"o_toolbar_style\",\"Default\")");
$p->exec("INSERT INTO config (conf_name,conf_value) VALUES (\"o_toolbar_smilies\",\"1\")");
$n=$p->query("SELECT COUNT(*) FROM config WHERE conf_name=\"o_active_plugins\"")->fetchColumn();
if($n) $p->exec("UPDATE config SET conf_value=\"toolbar\" WHERE conf_name=\"o_active_plugins\"");
else $p->exec("INSERT INTO config (conf_name,conf_value) VALUES (\"o_active_plugins\",\"toolbar\")");'
rm -f "$OLD"/cache/cache_config.php

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

# --- admin index: upgrade link points at the one-click updater -------------
curl -s -b "$JAR" "$BASE/admin_index.php" -o "$WORK/adminidx.html"
assert_contains "$WORK/adminidx.html" 'admin_maintenance.php?action=check_update' "version upgrade link goes to the updater"
assert_not_contains "$WORK/adminidx.html" 'action=check_upgrade' "old GitHub-message upgrade link removed"
# a newer release is offered by the feed, so the admin index shows the alert
assert_contains "$WORK/adminidx.html" "A new version of eveBB" "admin index shows update-available alert"

# --- server statistics: the phpinfo link is gone ---------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_statistics.php" -o "$WORK/stats.html"
assert_contains "$WORK/stats.html" "PHP:" "server statistics shows the PHP version"
assert_contains "$WORK/stats.html" "Accelerator:" "server statistics shows the accelerator line"
assert_not_contains "$WORK/stats.html" "action=phpinfo" "phpinfo link removed from server statistics"

# --- maintenance page shows the updates section ----------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_maintenance.php" -o "$WORK/maint.html"
assert_contains "$WORK/maint.html" "You are running eveBB <strong>0.9.0</strong>" "current version shown"
assert_contains "$WORK/maint.html" "Check for updates" "check button present"

# --- check for updates -----------------------------------------------------
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" "$BASE/admin_maintenance.php?action=check_update" -o "$WORK/check.html"
assert_contains "$WORK/check.html" "A new release is available: eveBB $CURRENT_VERSION" "new release detected"
assert_contains "$WORK/check.html" "Update to $CURRENT_VERSION" "update button offered"

# clicking the admin-index link arrives with admin_index.php as the referer;
# the read-only check must not trip the referrer guard
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_maintenance.php?action=check_update" -o "$WORK/checkidx.html"
assert_not_contains "$WORK/checkidx.html" "Bad HTTP_REFERER" "check-update link from admin index is not blocked"
assert_contains "$WORK/checkidx.html" "A new release is available: eveBB $CURRENT_VERSION" "check-update works from the admin index link"

# --- checksum tamper: a package whose bytes do not match the published
#     SHA-256 must be rejected BEFORE it is extracted and trusted -----------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/check.html" | grep -oE '[a-f0-9]{20,}' | head -1)
# serve a wrong (but well-formed) checksum for the same package
echo "0000000000000000000000000000000000000000000000000000000000000000  evebb-$CURRENT_VERSION.zip" > "$WORK/feed/evebb-$CURRENT_VERSION.zip.sha256"
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" -o "$WORK/tamper.html" "$BASE/admin_maintenance.php" \
  --data-urlencode "action=do_update" \
  --data-urlencode "csrf_token=$TOKEN"
assert_contains "$WORK/tamper.html" "Checksum verification FAILED" "tampered package rejected on checksum mismatch"
grep -q "define('FORUM_VERSION', '0.9.0')" "$OLD/include/common.php" \
  && ok "installation left untouched after a rejected update" || fail "installation left untouched after a rejected update"
[ ! -f "$OLD/cache/evebb_update.zip" ] && ok "rejected download cleaned up" || fail "rejected download cleaned up"
# restore the correct checksum for the genuine update below
echo "$GOOD_SHA  evebb-$CURRENT_VERSION.zip" > "$WORK/feed/evebb-$CURRENT_VERSION.zip.sha256"

# --- run the one-click update ----------------------------------------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/check.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" -o "$WORK/update.html" "$BASE/admin_maintenance.php" \
  --data-urlencode "action=do_update" \
  --data-urlencode "csrf_token=$TOKEN"
assert_contains "$WORK/update.html" "The forum was updated to eveBB $CURRENT_VERSION" "update reports success (checksum verified)"

# --- verify the results on disk and over HTTP ------------------------------
grep -q "define('FORUM_VERSION', '$CURRENT_VERSION')" "$OLD/include/common.php" \
  && ok "files replaced (FORUM_VERSION now $CURRENT_VERSION)" || fail "files replaced"
grep -q "config-sentinel-do-not-lose" "$OLD/config.php" \
  && ok "config.php preserved" || fail "config.php preserved"
# a self-update must remove install.php (the fresh install left one behind)
[ ! -f "$OLD/install.php" ] && ok "install.php removed by self-update" || fail "install.php removed by self-update"
[ ! -d "$OLD/cache/evebb_update_tmp" ] && ok "temp files cleaned up" || fail "temp files cleaned up"
[ ! -f "$OLD/cache/evebb_update.zip" ] && ok "downloaded zip cleaned up" || fail "downloaded zip cleaned up"

# scenario A: a version-only release (no schema changes, the common
# case for one-click updates) must finish silently - no wizard, no
# database password prompt, the forum just works
curl -s -b "$JAR" -o /dev/null -w "%{url_effective}" -L "$BASE/index.php" | grep -q "db_update.php" \
  && fail "version-only update finishes silently" || ok "version-only update finishes silently"

curl -s -b "$JAR" -L "$BASE/index.php" -o "$WORK/after.html" -w "%{http_code} %{url_effective}\n" > "$WORK/after.meta"
if grep -qF "Update Test" "$WORK/after.html"; then
  ok "forum works immediately after update"
else
  fail "forum works immediately after update (missing: Update Test)"
  echo "    | $(cat "$WORK/after.meta")"
  sed -n '1,12p' "$WORK/after.html" | sed 's/^/    | /'
fi

# the silent upgrade (common.php) tidies the retired toolbar plugin's leftovers
TB=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT COUNT(*) FROM config WHERE conf_name IN (\"o_toolbar_style\",\"o_toolbar_smilies\")")->fetchColumn();')
[ "$TB" = "0" ] && ok "legacy toolbar config rows removed on silent upgrade" || fail "legacy toolbar config rows removed on silent upgrade (found $TB)"
AP=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (string)$p->query("SELECT conf_value FROM config WHERE conf_name=\"o_active_plugins\"")->fetchColumn();')
echo ",$AP," | grep -q ",toolbar," && fail "toolbar slug removed from active plugins (still: $AP)" || ok "toolbar slug removed from active plugins"

# re-seed one leftover to prove the guided database update tidies it too
php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");$p->exec("INSERT INTO config (conf_name,conf_value) VALUES (\"o_toolbar_style\",\"Default\")");'
rm -f "$OLD"/cache/cache_config.php

# scenario B: a release that DOES change the database still routes
# through the guided update. Simulate one by lowering the parser
# revision (its migration - re-preparsing posts - is safe to re-run).
php -r '$p = new PDO("sqlite:'"$WORK"'/forum.sqlite"); $p->exec("UPDATE config SET conf_value = \"1\" WHERE conf_name = \"o_parser_revision\"");'
rm -f "$OLD"/cache/cache_config.php
curl -s -b "$JAR" -o /dev/null -w "%{url_effective}" -L "$BASE/index.php" | grep -q "db_update.php" \
  && ok "schema change routes to database update" || fail "schema change routes to database update"

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

# the login rate-limit table is present after upgrading
LAT=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type=\"table\" AND name=\"login_attempts\"")->fetchColumn();')
[ "$LAT" = "1" ] && ok "login_attempts table present after upgrade" || fail "login_attempts table present after upgrade"

# the guided database update (db_update.php 'start') also tidies the leftover
TB2=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT COUNT(*) FROM config WHERE conf_name=\"o_toolbar_style\"")->fetchColumn();')
[ "$TB2" = "0" ] && ok "legacy toolbar config removed by the guided update too" || fail "legacy toolbar config removed by the guided update too (found $TB2)"

# --- the 2FA absorption (roadmap section 7) --------------------------------
# The point of keeping the plugin's table names and shapes: the update must
# leave an enrolled member enrolled. If this ever fails, everyone who used
# the plugin is locked out of their own board after upgrading.
TFA_SECRET=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");$v=$p->query("SELECT secret FROM tfa_users WHERE user_id=42")->fetchColumn();echo $v===false?"MISSING":$v;')
[ "$TFA_SECRET" = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ" ] \
  && ok "the enrolled member's secret survived the upgrade" \
  || fail "the enrolled member's secret survived the upgrade (got $TFA_SECRET)"

TFA_SLOT=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT last_slot FROM tfa_users WHERE user_id=42")->fetchColumn();')
[ "$TFA_SLOT" = "987654" ] \
  && ok "the replay counter survived (a used code stays used)" \
  || fail "the replay counter survived (got $TFA_SLOT)"

TFA_CODES=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT COUNT(*) FROM tfa_backup WHERE user_id=42")->fetchColumn();')
[ "$TFA_CODES" = "2" ] \
  && ok "the member's backup codes survived" \
  || fail "the member's backup codes survived (found $TFA_CODES)"

DBREV=$(php -r '$p=new PDO("sqlite:'"$WORK"'/forum.sqlite");echo (int)$p->query("SELECT conf_value FROM config WHERE conf_name=\"o_database_revision\"")->fetchColumn();')
[ "$DBREV" -ge 29 ] \
  && ok "database revision recorded as 29 or later" \
  || fail "database revision recorded as 29 or later (got $DBREV)"

curl -s -b "$JAR" -L "$BASE/index.php" -o "$WORK/after2.html"
assert_contains "$WORK/after2.html" "Update Test" "forum works after guided update"
curl -s -b "$JAR" -e "$BASE/admin_maintenance.php" "$BASE/admin_maintenance.php?action=check_update" -o "$WORK/check2.html"
assert_contains "$WORK/check2.html" "You are running the latest release" "now reports up to date"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -10
else
  ok "php error log empty"
fi

echo "== self-updater e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
