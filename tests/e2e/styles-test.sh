#!/usr/bin/env bash
#
# End-to-end test of the style manager: upload a style zip, set it as
# the default (and see it rendered), confirm an FTP-dropped style also
# appears, guard against deleting the default, then delete a
# non-default style. Uses SQLite.
#
set -u

PORT="${PORT:-$((9400 + RANDOM % 400))}"

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
  [ -f "${WORK:-}/install.php.e2e-orig" ] && cp "$WORK/install.php.e2e-orig" "$ROOT/install.php" 2>/dev/null
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -rf "$WORK" "$ROOT/style/Teststyle.css" "$ROOT/style/Teststyle" "$ROOT/style/Ftpstyle.css" "$ROOT/style/Ftpstyle"
}
trap cleanup EXIT

# The 2.0.4 installer removes itself after a successful install, which
# is right for production and fatal for a suite that serves the repo
# root: the next install (this script re-run, or the next driver or
# suite in CI) 404s. Snapshot it and restore after installing.
cp "$ROOT/install.php" "$WORK/install.php.e2e-orig"
restore_installer() { [ -f "$ROOT/install.php" ] || cp "$WORK/install.php.e2e-orig" "$ROOT/install.php"; }

cd "$ROOT"

echo "== style manager e2e =="

# --- build a throwaway style package ---------------------------------------
mkdir -p "$WORK/Teststyle/img"
cat > "$WORK/Teststyle/style.json" <<'EOF'
{ "name": "Test Style", "slug": "Teststyle", "version": "1.0", "author": "e2e" }
EOF
# A minimal but recognisable stylesheet (marker lets us prove it is served)
cat > "$WORK/Teststyle/Teststyle.css" <<'EOF'
/* evebb-teststyle-marker */
.pun { background: #fafafa; }
EOF
printf 'x' > "$WORK/Teststyle/img/placeholder.png"
(cd "$WORK" && zip -rq "$WORK/teststyle.zip" Teststyle)
ok "test style packaged"

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
  --data-urlencode "req_title=Style Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install"
restore_installer
[ -f config.php ] && ok "forum installed" || fail "forum installed"

TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"

# --- Styles menu item + manager page ---------------------------------------
curl -s -b "$JAR" "$BASE/admin_index.php" -o "$WORK/adminidx.html"
assert_contains "$WORK/adminidx.html" "admin_styles.php" "Styles menu item present"

curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_styles.php" -o "$WORK/mgr.html"
assert_contains "$WORK/mgr.html" "Installed styles" "manager page renders"
assert_contains "$WORK/mgr.html" ">Carbon<" "bundled Carbon style listed"
assert_contains "$WORK/mgr.html" "Default" "a default style is marked"

# --- FTP drop-in appears ---------------------------------------------------
cp "$ROOT/style/Carbon.css" "$ROOT/style/Ftpstyle.css"
mkdir -p "$ROOT/style/Ftpstyle" && cp -r "$ROOT/style/Carbon/." "$ROOT/style/Ftpstyle/" 2>/dev/null || true
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_styles.php" -o "$WORK/mgrftp.html"
assert_contains "$WORK/mgrftp.html" "Ftpstyle" "FTP-dropped style appears in manager"

# --- upload the style package ----------------------------------------------
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/mgr.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_styles.php" -o /dev/null -L "$BASE/admin_styles.php?action=upload" \
  -F "csrf_token=$TOKEN" -F "upload_style=1" -F "style_file=@$WORK/teststyle.zip;type=application/zip"
[ -f "$ROOT/style/Teststyle.css" ] && ok "style css installed to style/" || fail "style css installed to style/"
[ -d "$ROOT/style/Teststyle" ] && ok "style asset folder installed" || fail "style asset folder installed"
[ -f "$ROOT/style/Teststyle/img/placeholder.png" ] && ok "style assets placed correctly" || fail "style assets placed correctly"

curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_styles.php" -o "$WORK/mgr2.html"
assert_contains "$WORK/mgr2.html" "Test Style" "uploaded style listed with manifest name"

# --- set the uploaded style as default -------------------------------------
CSRF=$(grep -oE 'action=default&amp;style=Teststyle&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr2.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_styles.php" -o /dev/null -L "$BASE/admin_styles.php?action=default&style=Teststyle&csrf_token=$CSRF"

# a brand-new guest sees the new default stylesheet served
curl -s "$BASE/style/Teststyle.css" -o "$WORK/served.css"
assert_contains "$WORK/served.css" "evebb-teststyle-marker" "new default stylesheet is served"
curl -s "$BASE/index.php" -o "$WORK/idx.html"
assert_contains "$WORK/idx.html" 'href="style/Teststyle.css?v=' "pages link the new default style (with cache-bust)"

# --- cannot delete the default ---------------------------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_styles.php" -o "$WORK/mgr3.html"
# Teststyle is now default -> it must offer no delete link
assert_not_contains "$WORK/mgr3.html" "action=delete&amp;style=Teststyle" "default style cannot be deleted"

# --- delete a non-default style (Ftpstyle) ---------------------------------
CSRF=$(grep -oE 'action=delete&amp;style=Ftpstyle&amp;csrf_token=[a-f0-9]{20,}' "$WORK/mgr3.html" | head -1 | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -e "$BASE/admin_styles.php" -o /dev/null -L "$BASE/admin_styles.php?action=delete&style=Ftpstyle&csrf_token=$CSRF"
[ ! -f "$ROOT/style/Ftpstyle.css" ] && ok "non-default style deleted from disk" || fail "non-default style deleted from disk"

# --- rejects a zip-slip archive --------------------------------------------
mkdir -p "$WORK/evil"
cat > "$WORK/evil/style.json" <<'EOF'
{"name":"Evil","slug":"evil","version":"1.0"}
EOF
printf 'x' > "$WORK/evil/evil.css"
(cd "$WORK" && zip -rq "$WORK/evil.zip" evil)
printf 'pwned' > "$WORK/escape"
( cd "$WORK" && zip -q "$WORK/evil.zip" escape && printf '@ escape\n@=../../style-escape.txt\n' | zipnote -w "$WORK/evil.zip" 2>/dev/null ) || true
curl -s -b "$JAR" -e "$BASE/admin_styles.php" "$BASE/admin_styles.php" -o "$WORK/mgr4.html"
TOKEN=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/mgr4.html" | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -e "$BASE/admin_styles.php" -o /dev/null -L "$BASE/admin_styles.php?action=upload" \
  -F "csrf_token=$TOKEN" -F "upload_style=1" -F "style_file=@$WORK/evil.zip;type=application/zip"
[ ! -f "$ROOT/../style-escape.txt" ] && ok "zip-slip archive did not escape" || { fail "zip-slip archive did not escape"; rm -f "$ROOT/../style-escape.txt"; }
rm -f "$ROOT/style/evil.css"; rm -rf "$ROOT/style/evil"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -12
else
  ok "php error log empty"
fi

rm -f "$ROOT/config.php" "$ROOT"/cache/cache_*.php

echo "== style manager e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
