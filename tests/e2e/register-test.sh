#!/usr/bin/env bash
#
# End-to-end test of the registration changes:
#  - fresh install defaults: verify ON, report ON, rules ON (with the
#    shipped generic ruleset), require-profile ON
#  - the rules must be agreed before the form is shown
#  - real name, date of birth and country are required (and a country
#    dropdown is offered); a <13 date of birth is rejected
#  - a valid registration stores real name / country / birthday
# Uses SQLite.
#
set -u

PORT="${PORT:-$((9200 + RANDOM % 400))}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
DB="$WORK/f.sqlite"
ERRLOG="$WORK/php-errors.log"
JAR="$WORK/cookies.txt"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_contains() { if grep -qF -- "$2" "$1"; then ok "$3"; else fail "$3 (missing: $2)"; fi; }
assert_not_contains() { if grep -qF -- "$2" "$1"; then fail "$3 (unexpectedly found: $2)"; else ok "$3"; fi; }

cleanup() {
  [ -f "${WORK:-}/install.php.e2e-orig" ] && cp "$WORK/install.php.e2e-orig" "$ROOT/install.php" 2>/dev/null [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT

# The 2.0.4 installer removes itself after a successful install, which
# is right for production and fatal for a suite that serves the repo
# root: the next install (this script re-run, or the next driver or
# suite in CI) 404s. Snapshot it and restore after installing.
cp "$ROOT/install.php" "$WORK/install.php.e2e-orig"
restore_installer() { [ -f "$ROOT/install.php" ] || cp "$WORK/install.php.e2e-orig" "$ROOT/install.php"; }

cd "$ROOT"
echo "== registration e2e =="

rm -f config.php cache/cache_*.php
php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 -d error_log="$ERRLOG" \
    -S 127.0.0.1:"$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

curl -s -o /dev/null "$BASE/install.php" \
  --data-urlencode "form_sent=1" --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=sqlite" --data-urlencode "req_db_host=localhost" \
  --data-urlencode "req_db_name=$DB" --data-urlencode "db_username=" --data-urlencode "db_password=" --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password1=adminpass123" --data-urlencode "req_password2=adminpass123" \
  --data-urlencode "req_email=admin@example.com" --data-urlencode "req_title=Reg Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install"
restore_installer
[ -f config.php ] && ok "forum installed" || fail "forum installed"

# The installer records the admin as "registered" from 127.0.0.1 just now,
# which would trip the same-IP anti-flood guard for every test registration
# below (in the real world the admin and new members have different IPs).
php -r '$p=new PDO("sqlite:'"$DB"'");$p->exec("UPDATE users SET registered = registered - 7200");'

sqlq() { php -r '$p=new PDO("sqlite:'"$DB"'");$s=$p->query($argv[1]);var_export($s?$s->fetchAll(PDO::FETCH_ASSOC):null);' "$1"; }
cfg() { php -r '$p=new PDO("sqlite:'"$DB"'");$s=$p->query("SELECT conf_value FROM config WHERE conf_name=".$p->quote($argv[1]));$r=$s->fetch();echo $r?$r[0]:"(none)";' "$1"; }
# solve a sealed captcha token using the site's own cookie_seed (the test is
# the legitimate site operator, so this is not a bypass - it uses the real key)
captcha_answer() { php -r 'define("PUN_ROOT","'"$ROOT"'/");require PUN_ROOT."config.php";if(!defined("PUN"))define("PUN",1);require PUN_ROOT."include/captcha.php";$a=evebb_captcha_decode($argv[1]);echo $a===false?"":$a;' "$1"; }
tok_from() { grep -oE 'name="captcha_token" value="[^"]+"' "$1" | sed -E 's/.*value="([^"]+)".*/\1/' | head -1; }

# --- install defaults ------------------------------------------------------
[ "$(cfg o_regs_captcha)" = "1" ] && ok "registration CAPTCHA defaults ON" || fail "registration CAPTCHA defaults ON (got $(cfg o_regs_captcha))"
[ "$(cfg o_regs_verify)" = "1" ] && ok "verify registrations defaults ON" || fail "verify registrations defaults ON (got $(cfg o_regs_verify))"
[ "$(cfg o_regs_report)" = "1" ] && ok "report new registrations defaults ON" || fail "report defaults ON (got $(cfg o_regs_report))"
[ "$(cfg o_rules)" = "1" ] && ok "forum rules default ON" || fail "forum rules default ON (got $(cfg o_rules))"
[ "$(cfg o_regs_require_profile)" = "1" ] && ok "require-profile defaults ON" || fail "require-profile defaults ON (got $(cfg o_regs_require_profile))"
cfg o_rules_message | grep -qF "Treat everyone with respect" && ok "shipped generic ruleset present" || fail "shipped generic ruleset present"

# --- rules must be agreed first --------------------------------------------
curl -s "$BASE/register.php" -o "$WORK/reg0.html"
assert_contains "$WORK/reg0.html" "Treat everyone with respect" "rules shown before the form"
assert_contains "$WORK/reg0.html" 'name="agree"' "agree button present"
assert_not_contains "$WORK/reg0.html" 'name="req_country"' "form not shown until rules agreed"

# --- after agreeing, the mandatory profile fields appear -------------------
curl -s "$BASE/register.php?agree=1" -o "$WORK/reg1.html"
REG_CSRF=$(grep -oE 'name="csrf_token" value="[a-f0-9]+"' "$WORK/reg1.html" | grep -oE '[a-f0-9]{20,}' | head -1)
assert_contains "$WORK/reg1.html" 'name="req_realname"' "real name field present"
assert_contains "$WORK/reg1.html" 'name="req_birthday"' "date of birth field present"
assert_contains "$WORK/reg1.html" 'name="req_country"' "country dropdown present"
assert_contains "$WORK/reg1.html" '>United Kingdom<' "country dropdown is populated"

# --- CAPTCHA is shown, its image endpoint works, and it is enforced ---------
assert_contains "$WORK/reg1.html" 'name="req_captcha"' "captcha input present on the form"
assert_contains "$WORK/reg1.html" 'src="captcha.php?t=' "captcha image present on the form"
assert_contains "$WORK/reg1.html" 'name="captcha_token"' "sealed captcha token present on the form"
CAP_TOK=$(tok_from "$WORK/reg1.html")
CAP_ANS=$(captcha_answer "$CAP_TOK")
[ -n "$CAP_ANS" ] && ok "captcha token decodes with the site key (answer: $CAP_ANS)" || fail "captcha token decodes with the site key"
# the image endpoint returns a real PNG for a valid token
curl -s "$BASE/captcha.php?t=$CAP_TOK" -o "$WORK/captcha.png"
head -c4 "$WORK/captcha.png" | grep -q "PNG" && ok "captcha.php returns a PNG image" || fail "captcha.php returns a PNG image"
# a bad token is refused by the image endpoint
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/captcha.php?t=not-a-real-token")
[ "$CODE" = "400" ] && ok "captcha.php rejects a bad token (400)" || fail "captcha.php rejects a bad token (got $CODE)"

# a registration with a WRONG captcha response is rejected and stores nothing
curl -s -e "$BASE/register.php?agree=1" "$BASE/register.php?action=register" -o "$WORK/regcap.html" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$REG_CSRF" --data-urlencode "req_user=eve" \
  --data-urlencode "req_password1=supersecret1" --data-urlencode "req_password2=supersecret1" \
  --data-urlencode "req_email1=eve@example.com" --data-urlencode "req_email2=eve@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "email_setting=1" \
  --data-urlencode "req_realname=Eve Adams" --data-urlencode "req_country=United Kingdom" \
  --data-urlencode "req_birthday=1990-05-15" \
  --data-urlencode "captcha_token=$CAP_TOK" --data-urlencode "req_captcha=000wrong000"
assert_contains "$WORK/regcap.html" "confirmation code" "wrong captcha is rejected"
EVE=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM users WHERE username=\"eve\"")->fetchColumn();')
[ "$EVE" = "0" ] && ok "wrong-captcha registration stored nothing" || fail "wrong-captcha registration stored nothing (found $EVE)"

# --- missing profile fields are rejected -----------------------------------
curl -s -e "$BASE/register.php?agree=1" "$BASE/register.php?action=register" -o "$WORK/reg2.html" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$REG_CSRF" --data-urlencode "req_user=alice" \
  --data-urlencode "req_email1=alice@example.com" --data-urlencode "req_email2=alice@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "email_setting=1" \
  --data-urlencode "req_realname=" --data-urlencode "req_country=" --data-urlencode "req_birthday=" \
  --data-urlencode "captcha_token=$CAP_TOK" --data-urlencode "req_captcha=$CAP_ANS"
assert_contains "$WORK/reg2.html" "You must enter your real name" "blank real name rejected"
assert_contains "$WORK/reg2.html" "You must select your country" "blank country rejected"
assert_contains "$WORK/reg2.html" "You must enter your date of birth" "blank date of birth rejected"

# --- an under-13 date of birth is rejected ---------------------------------
curl -s -e "$BASE/register.php?agree=1" "$BASE/register.php?action=register" -o "$WORK/reg3.html" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$REG_CSRF" --data-urlencode "req_user=bob" \
  --data-urlencode "req_email1=bob@example.com" --data-urlencode "req_email2=bob@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "email_setting=1" \
  --data-urlencode "req_realname=Bob Jones" --data-urlencode "req_country=United Kingdom" \
  --data-urlencode "req_birthday=2020-01-01" \
  --data-urlencode "captcha_token=$CAP_TOK" --data-urlencode "req_captcha=$CAP_ANS"
assert_contains "$WORK/reg3.html" "at least 13 years old" "under-13 date of birth rejected"

# an invalid country (not in the list) is rejected
curl -s -e "$BASE/register.php?agree=1" "$BASE/register.php?action=register" -o "$WORK/reg3b.html" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$REG_CSRF" --data-urlencode "req_user=carol" \
  --data-urlencode "req_email1=carol@example.com" --data-urlencode "req_email2=carol@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "email_setting=1" \
  --data-urlencode "req_realname=Carol" --data-urlencode "req_country=Atlantis" \
  --data-urlencode "req_birthday=1990-01-01" \
  --data-urlencode "captcha_token=$CAP_TOK" --data-urlencode "req_captcha=$CAP_ANS"
assert_contains "$WORK/reg3b.html" "You must select your country" "off-list country rejected"

# --- a valid registration stores the details -------------------------------
# switch verify off and silence the report mailer so this path needs no SMTP
php -r '$p=new PDO("sqlite:'"$DB"'");$p->exec("UPDATE config SET conf_value=0 WHERE conf_name=\"o_regs_verify\"");$p->exec("UPDATE config SET conf_value=\"\" WHERE conf_name=\"o_mailing_list\"");'
rm -f cache/cache_config.php
curl -s -e "$BASE/register.php?agree=1" -L "$BASE/register.php?action=register" -o "$WORK/reg4.html" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$REG_CSRF" --data-urlencode "req_user=daphne" \
  --data-urlencode "req_password1=supersecret1" --data-urlencode "req_password2=supersecret1" \
  --data-urlencode "req_email1=daphne@example.com" --data-urlencode "req_email2=daphne@example.com" \
  --data-urlencode "timezone=0" --data-urlencode "email_setting=1" \
  --data-urlencode "req_realname=Daphne Blake" --data-urlencode "req_country=United Kingdom" \
  --data-urlencode "req_birthday=1990-05-15" \
  --data-urlencode "captcha_token=$CAP_TOK" --data-urlencode "req_captcha=$CAP_ANS"

ROW=$(php -r '$p=new PDO("sqlite:'"$DB"'");$s=$p->query("SELECT realname,country,birthday FROM users WHERE username=\"daphne\"");$r=$s->fetch(PDO::FETCH_ASSOC);echo $r?($r["realname"]."|".$r["country"]."|".$r["birthday"]):"(no row)";')
echo "    stored: $ROW"
[ "$ROW" = "Daphne Blake|United Kingdom|1990-05-15" ] && ok "valid registration stored realname/country/birthday" || fail "stored details ($ROW)"

# --- the registered details populate the profile Personal page -------------
DAPHNE_ID=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo $p->query("SELECT id FROM users WHERE username=\"daphne\"")->fetchColumn();')
TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}' | head -1)
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"
curl -s -b "$JAR" "$BASE/profile.php?section=personal&id=$DAPHNE_ID" -o "$WORK/prof.html"
assert_contains "$WORK/prof.html" 'name="form[realname]" value="Daphne Blake"' "real name populated in profile"
assert_contains "$WORK/prof.html" 'name="form[birthday]" value="1990-05-15"' "date of birth populated in profile"
assert_contains "$WORK/prof.html" '<option value="United Kingdom" selected="selected">' "country populated in profile"

# --- updates never auto-modify an existing board's rules -------------------
# a board with rules deliberately OFF (placeholder message) must stay OFF
# after a version bump - we cannot tell "deliberately off" from "never set",
# so we never auto-enable
php -r '$p=new PDO("sqlite:'"$DB"'");$p->exec("UPDATE config SET conf_value=\"0\" WHERE conf_name=\"o_rules\"");$p->exec("UPDATE config SET conf_value=\"Enter your rules here\" WHERE conf_name=\"o_rules_message\"");$p->exec("UPDATE config SET conf_value=\"0.9.0\" WHERE conf_name=\"o_cur_version\"");'
rm -f cache/cache_config.php
curl -s -o /dev/null "$BASE/index.php"
[ "$(cfg o_rules)" = "0" ] && ok "disabled rules stay disabled after update" || fail "disabled rules stay disabled after update"
cfg o_rules_message | grep -qxF "Enter your rules here" && ok "unset rules message left untouched" || fail "unset rules message left untouched"

# a board with custom rules must be left exactly as-is
php -r '$p=new PDO("sqlite:'"$DB"'");$p->exec("UPDATE config SET conf_value=\"1\" WHERE conf_name=\"o_rules\"");$p->exec("UPDATE config SET conf_value=\"<p>MY CUSTOM RULES</p>\" WHERE conf_name=\"o_rules_message\"");$p->exec("UPDATE config SET conf_value=\"0.9.0\" WHERE conf_name=\"o_cur_version\"");'
rm -f cache/cache_config.php
curl -s -o /dev/null "$BASE/index.php"
cfg o_rules_message | grep -qF "MY CUSTOM RULES" && ok "custom rules left untouched on update" || fail "custom rules left untouched on update"

if [ -s "$ERRLOG" ]; then fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -15; else ok "php error log empty"; fi

rm -f config.php cache/cache_*.php
echo "== registration e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
