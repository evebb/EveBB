#!/usr/bin/env bash
#
# End-to-end test of the bundled BBCode toolbar plugin (the standard
# eveBB plugin example): hook injection, admin settings page, style
# switching and the extended smiley set. Uses SQLite.
#
set -u

PORT="${PORT:-$((9200 + RANDOM % 500))}"

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
  rm -rf "$WORK"
}
trap cleanup EXIT

cd "$ROOT"

echo "== toolbar plugin e2e =="

# --- fresh install (SQLite) ------------------------------------------------
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
  --data-urlencode "req_title=Toolbar Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Air" \
  --data-urlencode "start=Start install"
[ -f config.php ] && ok "forum installed" || fail "forum installed"

# --- log in ----------------------------------------------------------------
TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"

# --- toolbar is a manifest plugin, active out of the box -------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr.html"
assert_contains "$WORK/mgr.html" "BBCode Toolbar" "toolbar listed in the plugin manager"

# no separate legacy plugins menu any more
assert_not_contains "$WORK/mgr.html" "admin_loader.php?plugin=AP_Toolbar" "no legacy toolbar admin link"

# injected on the post form, not on ordinary pages
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post.html"
assert_contains "$WORK/post.html" "plugins/toolbar/toolbar.js" "toolbar script on post form"
assert_contains "$WORK/post.html" "var EVEBB_TOOLBAR" "toolbar config on post form"
assert_contains "$WORK/post.html" "plugins/toolbar/style/Default/toolbar.css" "default style css linked"

curl -s -b "$JAR" "$BASE/index.php" -o "$WORK/index.html"
assert_not_contains "$WORK/index.html" "plugins/toolbar/toolbar.js" "no toolbar on index"

# --- settings page (via the plugin manager) --------------------------------
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" "$BASE/admin_plugins.php?action=settings&plugin=toolbar" -o "$WORK/settings.html"
assert_contains "$WORK/settings.html" "Dark-buttons" "settings page lists styles"

# --- change settings: Dark-buttons style + extended smilies ----------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/settings.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_plugins.php?action=settings&plugin=toolbar" -o /dev/null -L "$BASE/admin_plugins.php?action=settings&plugin=toolbar" \
  --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "toolbar_style=Dark-buttons" \
  --data-urlencode "toolbar_smilies=1" \
  --data-urlencode "save_settings=Save"
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post2.html"
assert_contains "$WORK/post2.html" "plugins/toolbar/style/Dark-buttons/toolbar.css" "style switch applied"
assert_contains "$WORK/post2.html" "plugins/toolbar/style/smilies/" "extended smiley palette active"

# --- extended smiley renders in a post -------------------------------------
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/post.php?fid=1" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/post.php?fid=1" -o /dev/null -L "$BASE/post.php?fid=1" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_subject=Smiley test" \
  --data-urlencode "req_message=Extended smiley :devil: here and classic :) too" \
  --data-urlencode "submit=Submit"
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$WORK/topic.html"
assert_contains "$WORK/topic.html" "plugins/toolbar/style/smilies/devil.png" "extended smiley rendered"
assert_contains "$WORK/topic.html" "plugins/toolbar/style/smilies/smile.png" "classic smiley uses extended set"

# --- deactivate the toolbar via the manager --------------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_plugins.php" -o "$WORK/mgr2.html"
CSRF=$(grep -oE 'action=deactivate&amp;plugin=toolbar&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr2.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_plugins.php" -o /dev/null -L "$BASE/admin_plugins.php?action=deactivate&plugin=toolbar&csrf_token=$CSRF"
curl -s -b "$JAR" "$BASE/post.php?fid=1" -o "$WORK/post3.html"
assert_not_contains "$WORK/post3.html" "plugins/toolbar/toolbar.js" "deactivated toolbar stops injecting"

# --- help page -------------------------------------------------------------
code=$(curl -s -b "$JAR" -o "$WORK/help.html" -w "%{http_code}" "$BASE/plugins/toolbar/lang/English/help.php")
[ "$code" = "200" ] && ok "help page responds" || fail "help page responds (HTTP $code)"
assert_contains "$WORK/help.html" "eveBB toolbar help" "help page branded"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -10
else
  ok "php error log empty"
fi

rm -f "$ROOT/config.php" "$ROOT"/cache/cache_*.php

echo "== toolbar e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
