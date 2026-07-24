#!/usr/bin/env bash
#
# E2E test for zero-config install defaults (roadmap 2.1 no. 10).
#
# Drops an install_defaults.php next to install.php and asserts that:
#   - the form prefills and locks the preconfigured fields
#   - the database type list collapses to the preconfigured driver
#   - tampered POST values for locked fields are ignored (defaults win)
#   - the defaults file is deleted once the installation succeeds
#   - the resulting board actually works
#   - with no defaults file present, the installer behaves as before
#
# Uses the sqlite driver so it needs no database service.
#
#   bash tests/e2e/install-defaults-test.sh
#
set -u

PORT="${PORT:-8097}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
ERRLOG="$TMP/php-errors.log"
DB_FILE="$TMP/evebb-defaults.sqlite"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() { # file needle label
  if grep -qF -- "$2" "$1"; then ok "$3"; else fail "$3 (missing: $2)"; fi
}
assert_not_contains() { # file needle label
  if grep -qF -- "$2" "$1"; then fail "$3 (unexpected: $2)"; else ok "$3"; fi
}
assert_code() { # expected actual label
  if [ "$1" = "$2" ]; then ok "$3"; else fail "$3 (HTTP $2, wanted $1)"; fi
}

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -f "$ROOT/install_defaults.php"
  rm -rf "$TMP"
}
trap cleanup EXIT

cd "$ROOT"

echo "== e2e: install defaults (sqlite) =="

# --- reset state -----------------------------------------------------------
rm -f config.php cache/cache_*.php cache/.htaccess cache/db_update.lock

# --- drop the defaults file ------------------------------------------------
SETUP_TOKEN="e2esetuptoken1234567890abcdef"
cat > install_defaults.php <<EOF
<?php

return array(
	'db_type'     => 'sqlite',
	'db_host'     => 'localhost',
	'db_name'     => '$DB_FILE',
	'db_username' => '',
	'db_password' => '',
	'db_prefix'   => 'bb_',
	'base_url'    => '$BASE',
	'setup_token' => '$SETUP_TOKEN',
);
EOF

# --- start server ----------------------------------------------------------
php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

# --- setup token gate ------------------------------------------------------
code=$(curl -s -o "$TMP/notoken.html" -w "%{http_code}" "$BASE/install.php")
assert_code 403 "$code" "installer without token refused"
assert_contains "$TMP/notoken.html" "reserved for the board" "no-token page explains itself"
code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/install.php?token=wrongtoken")
assert_code 403 "$code" "installer with wrong token refused"

# --- form shows locked, prefilled fields -----------------------------------
code=$(curl -s -o "$TMP/form.html" -w "%{http_code}" "$BASE/install.php?token=$SETUP_TOKEN")
assert_code 200 "$code" "installer form responds with token"
assert_contains "$TMP/form.html" "name=\"token\" value=\"$SETUP_TOKEN\"" "form carries the token as a hidden field"
assert_contains "$TMP/form.html" "preconfigured by your installation environment" "defaults note shown"
assert_contains "$TMP/form.html" "name=\"req_db_name\" value=\"$DB_FILE\" size=\"30\" readonly=\"readonly\"" "db name prefilled and locked"
assert_contains "$TMP/form.html" "name=\"req_db_host\" value=\"localhost\" size=\"50\" readonly=\"readonly\"" "db host prefilled and locked"
assert_contains "$TMP/form.html" "name=\"db_prefix\" value=\"bb_\" size=\"20\" maxlength=\"30\" readonly=\"readonly\"" "db prefix prefilled and locked"
assert_contains "$TMP/form.html" "name=\"req_base_url\" value=\"$BASE\" size=\"60\" maxlength=\"100\" readonly=\"readonly\"" "base url prefilled and locked"
assert_contains "$TMP/form.html" "name=\"db_password\" size=\"30\" readonly=\"readonly\"" "db password locked"
assert_contains "$TMP/form.html" "<option value=\"sqlite\" selected=\"selected\">" "preconfigured db type selected"
assert_not_contains "$TMP/form.html" "<option value=\"mysqli\"" "other db types collapsed away"

# --- a POST without the token is refused too -------------------------------
code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/install.php" \
  --data-urlencode "form_sent=1" --data-urlencode "req_db_type=sqlite")
assert_code 403 "$code" "installer POST without token refused"
[ ! -f config.php ] && ok "no config written by tokenless POST" || fail "no config written by tokenless POST"

# --- install, TAMPERING with every locked field ----------------------------
code=$(curl -s -o "$TMP/install.html" -w "%{http_code}" "$BASE/install.php" \
  --data-urlencode "token=$SETUP_TOKEN" \
  --data-urlencode "form_sent=1" \
  --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=mysqli" \
  --data-urlencode "req_db_host=evil.example.com" \
  --data-urlencode "req_db_name=/tmp/evil.sqlite" \
  --data-urlencode "db_username=evil" \
  --data-urlencode "db_password=evil" \
  --data-urlencode "db_prefix=evil_" \
  --data-urlencode "req_username=admin" \
  --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" \
  --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=Defaults Forum" \
  --data-urlencode "desc=E2E" \
  --data-urlencode "req_base_url=http://evil.example.com" \
  --data-urlencode "req_default_lang=English" \
  --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install")
assert_code 200 "$code" "installer responds"
[ -f config.php ] && ok "config.php written" || fail "config.php written"
if grep -qiE "fatal|uncaught" "$TMP/install.html"; then fail "installer page free of fatals"; else ok "installer page free of fatals"; fi

# --- defaults won over the tampered values ---------------------------------
assert_contains config.php "\$db_type = 'sqlite';" "config uses preconfigured db type"
assert_contains config.php "\$db_name = '$DB_FILE';" "config uses preconfigured db name"
assert_contains config.php "\$db_prefix = 'bb_';" "config uses preconfigured db prefix"
assert_not_contains config.php "evil" "no tampered value reached config.php"
[ -f "$DB_FILE" ] && ok "database created at preconfigured path" || fail "database created at preconfigured path"
[ ! -f /tmp/evil.sqlite ] && ok "tampered db path never touched" || fail "tampered db path never touched"

# --- defaults file removed on success --------------------------------------
[ ! -f install_defaults.php ] && ok "install_defaults.php deleted on success" || fail "install_defaults.php deleted on success"

# --- the board works, base_url came from defaults --------------------------
code=$(curl -s -o "$TMP/index.html" -w "%{http_code}" "$BASE/index.php")
assert_code 200 "$code" "board index responds"
assert_contains "$TMP/index.html" "<title>Defaults Forum" "board title from the form"
php -r '$p = new PDO("sqlite:".$argv[1]); echo $p->query("SELECT conf_value FROM bb_config WHERE conf_name = \"o_base_url\"")->fetchColumn();' "$DB_FILE" > "$TMP/baseurl.txt"
assert_contains "$TMP/baseurl.txt" "$BASE" "o_base_url is the preconfigured value, not the tampered one"

# --- second visit says already installed -----------------------------------
curl -s -o "$TMP/again.html" "$BASE/install.php"
assert_contains "$TMP/again.html" "already installed" "re-running installer refuses"

# --- no PHP errors ---------------------------------------------------------
if [ -s "$ERRLOG" ]; then fail "php error log empty"; cat "$ERRLOG"; else ok "php error log empty"; fi

# --- regression: WITHOUT a defaults file the form is untouched -------------
kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=
rm -f config.php cache/cache_*.php cache/.htaccess "$DB_FILE"
php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

curl -s -o "$TMP/plain.html" "$BASE/install.php"
assert_not_contains "$TMP/plain.html" "preconfigured by your installation environment" "no defaults note without a defaults file"
assert_not_contains "$TMP/plain.html" "readonly=\"readonly\"" "no locked fields without a defaults file"
assert_contains "$TMP/plain.html" "<option value=\"sqlite\"" "sqlite still offered"
assert_contains "$TMP/plain.html" "<option value=\"mysqli\"" "mysqli still offered"

# --- summary ---------------------------------------------------------------
echo "== install-defaults: $PASS passed, $FAIL failed =="
[ "$FAIL" = "0" ]
