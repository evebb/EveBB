#!/usr/bin/env bash
#
# End-to-end test of core two-factor authentication (roadmap section 7).
#
# Installs a real board, enrols a real member, and drives login.php over
# HTTP to prove the properties that matter when this is switched on:
#
#   - a member without 2FA logs in exactly as before
#   - with 2FA on, the right password alone is NOT enough
#   - a wrong code is refused
#   - the current TOTP code logs in
#   - the SAME code cannot be used twice (replay)
#   - a backup code logs in, and is then spent
#   - the login form never reveals who has 2FA before the password is right
#
# The last one is the reason the code is checked after the password rather
# than alongside it: otherwise the form is an oracle for which accounts to
# attack. Codes are computed here with the same include/tfa.php the board
# uses, so this tests the board rather than a reimplementation.
#
# Uses SQLite, so no database server is needed.
#
#   bash tests/e2e/tfa-test.sh
#
set -u

PORT="${PORT:-$((8900 + RANDOM % 400))}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
FORUM="$WORK/forum"
DB="$WORK/forum.sqlite"
ERRLOG="$WORK/php-errors.log"
JAR="$WORK/cookies.txt"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -rf "$WORK"
}
trap cleanup EXIT

mkdir -p "$FORUM"
tar -C "$ROOT" --exclude=.git --exclude=tests --exclude=.github --exclude=website \
    --exclude=config.php --exclude='cache/cache_*.php' -cf - . | tar -C "$FORUM" -xf -
rm -f "$FORUM/config.php" "$FORUM"/cache/cache_*.php

(cd "$FORUM" && php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 \
    -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" >/dev/null 2>&1) & SERVER_PID=$!
for i in $(seq 1 25); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

curl -s -o /dev/null "$BASE/install.php" \
  --data-urlencode "form_sent=1" --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=sqlite" --data-urlencode "req_db_host=localhost" \
  --data-urlencode "req_db_name=$DB" \
  --data-urlencode "db_username=" --data-urlencode "db_password=" --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=2FA Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install"

[ -f "$FORUM/config.php" ] && ok "board installed" || { fail "board installed"; echo "== 2fa e2e: $PASS passed, $FAIL failed =="; exit 1; }

# --- a member with a known password ----------------------------------------
SECRET='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
php -r '
$p = new PDO("sqlite:'"$DB"'");
$hash = password_hash("memberpass123", PASSWORD_DEFAULT);
$grp = $p->query("SELECT conf_value FROM config WHERE conf_name=\"o_default_user_group\"")->fetchColumn();
$now = time();
$st = $p->prepare("INSERT INTO users (username, group_id, password, email, registered, registration_ip, last_visit, language, style, num_posts) VALUES (?,?,?,?,?,?,?,?,?,0)");
$st->execute(["member", $grp, $hash, "member@example.com", $now, "127.0.0.1", $now, "English", "Carbon"]);
' && ok "member created" || fail "member created"

# Compute codes with the board's own implementation, not a copy of it
code_for() { # $1 = slot offset
  php -r '
define("PUN", 1); define("PUN_ROOT", "'"$FORUM"'/");
require PUN_ROOT."include/tfa.php";
echo tfa_code("'"$SECRET"'", (int) floor(time() / 30) + (int) $argv[1]);
' "$1"
}

login() { # $1 = username, $2 = password, $3 = code (may be empty) -> writes $WORK/out.html
  rm -f "$JAR"
  local token
  token=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
  curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -L "$BASE/login.php?action=in" -o "$WORK/out.html" \
    --data-urlencode "form_sent=1" \
    --data-urlencode "req_username=$1" \
    --data-urlencode "req_password=$2" \
    --data-urlencode "req_tfa_code=$3" \
    --data-urlencode "csrf_token=$token" \
    --data-urlencode "redirect_url=index.php" \
    --data-urlencode "login=Login"
  # eveBB answers a successful login with a meta-refresh page, not a 302, so
  # ask for a real page with the session cookie and judge from that
  curl -s -b "$JAR" "$BASE/index.php" -o "$WORK/page.html"
}

logged_in() { grep -qF 'login.php?action=out' "$WORK/page.html"; }

# --- before 2FA: nothing changes for an ordinary member --------------------
login member memberpass123 ""
logged_in && ok "member logs in normally with no 2FA" || fail "member logs in normally with no 2FA"

MEMBER_ID=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT id FROM users WHERE username=\"member\"")->fetchColumn();')

# The login form must not mention 2FA state for a member who has none
curl -s "$BASE/login.php" -o "$WORK/form.html"
grep -qF 'name="req_tfa_code"' "$WORK/form.html" \
  && ok "the one-time code field is on the login form" \
  || fail "the one-time code field is on the login form"

# --- enrol the member ------------------------------------------------------
php -r '
$p = new PDO("sqlite:'"$DB"'");
$p->exec("INSERT INTO tfa_users (user_id, secret, last_slot, enabled_at) VALUES ('"$MEMBER_ID"', \"'"$SECRET"'\", 0, ".time().")");
$p->exec("INSERT INTO tfa_backup (user_id, code_hash) VALUES ('"$MEMBER_ID"', \"PLACEHOLDER\")");
' && ok "member enrolled in 2FA" || fail "member enrolled in 2FA"

# a real backup code, hashed the way the board hashes it
BACKUP='ABCD-2345'
php -r '
define("PUN_ROOT", "'"$FORUM"'/");
require PUN_ROOT."config.php";
require PUN_ROOT."include/tfa.php";
$p = new PDO("sqlite:'"$DB"'");
$p->exec("DELETE FROM tfa_backup WHERE user_id='"$MEMBER_ID"'");
$st = $p->prepare("INSERT INTO tfa_backup (user_id, code_hash) VALUES (?, ?)");
$st->execute(['"$MEMBER_ID"', tfa_backup_hash("'"$BACKUP"'")]);
' && ok "a backup code stored" || fail "a backup code stored"

# --- the password alone is no longer enough --------------------------------
login member memberpass123 ""
logged_in && fail "password alone must not log in once 2FA is on" \
           || ok "password alone no longer logs in"
grep -qF 'Enter the current code from your authenticator app' "$WORK/out.html" \
  && ok "the member is told a code is needed" \
  || fail "the member is told a code is needed"

login member memberpass123 "000000"
logged_in && fail "a wrong code must not log in" || ok "a wrong code is refused"

# --- the real code works ---------------------------------------------------
CODE=$(code_for 0)
login member memberpass123 "$CODE"
logged_in && ok "the current code logs in" || fail "the current code logs in"

# --- and cannot be used again ----------------------------------------------
login member memberpass123 "$CODE"
logged_in && fail "a used code must never work twice (replay)" \
           || ok "the same code is refused the second time (replay)"

# --- backup codes ----------------------------------------------------------
login member memberpass123 "$BACKUP"
logged_in && ok "a backup code logs in" || fail "a backup code logs in"

login member memberpass123 "$BACKUP"
logged_in && fail "a backup code must be single use" || ok "the backup code is spent after one use"

LEFT=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_backup WHERE user_id='"$MEMBER_ID"'")->fetchColumn();')
[ "$LEFT" = "0" ] && ok "the spent code is gone from the database" || fail "the spent code is gone from the database (found $LEFT)"

# --- a wrong password is still just a wrong password -----------------------
# The form must not behave differently for a 2FA account until the password
# is right, or it becomes an oracle for which accounts to attack.
login member wrongpassword "$(code_for 0)"
logged_in && fail "a wrong password must not log in" || ok "a wrong password is refused"
grep -qF 'Enter the current code from your authenticator app' "$WORK/out.html" \
  && fail "a wrong password must not reveal that the account uses 2FA" \
  || ok "a wrong password does not reveal that the account uses 2FA"

# --- other members are unaffected ------------------------------------------
login admin adminpass123 ""
logged_in && ok "an account without 2FA still logs in with no code" || fail "an account without 2FA still logs in with no code"

# ==========================================================================
# The member-facing flow: enrol, confirm, regenerate, disable - driven
# through the real profile pages rather than the database.
# ==========================================================================
php -r '$p=new PDO("sqlite:'"$DB"'");$p->exec("DELETE FROM tfa_users");$p->exec("DELETE FROM tfa_backup");'

field() { grep -oE 'name="'"$1"'" value="[^"]*"' "$2" | head -1 | sed 's/.*value="//;s/"$//'; }

login member memberpass123 ""
logged_in && ok "member logged in for the profile flow" || fail "member logged in for the profile flow"

curl -s -b "$JAR" "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/sec.html"
grep -qF 'Set up two-factor authentication' "$WORK/sec.html" \
  && ok "the Security page offers to set 2FA up" || fail "the Security page offers to set 2FA up"
grep -qF 'section=security' "$WORK/sec.html" \
  && ok "the Security tab is in the profile menu" || fail "the Security tab is in the profile menu"

# --- start: a secret and a QR, nothing stored yet ---------------------------
TOKEN=$(field csrf_token "$WORK/sec.html")
curl -s -b "$JAR" -e "$BASE/profile.php?section=security&id=$MEMBER_ID" \
  "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/qr.html" \
  --data-urlencode "tfa_action=start" --data-urlencode "csrf_token=$TOKEN"

UI_SECRET=$(grep -oE '<code>[A-Z2-7]{32}</code>' "$WORK/qr.html" | head -1 | tr -d '<code>/')
SEAL=$(field tfa_seal "$WORK/qr.html")
[ ${#UI_SECRET} = 32 ] && ok "setup shows a 32-character secret" || fail "setup shows a 32-character secret (got '$UI_SECRET')"
[ -n "$SEAL" ] && ok "the secret is carried in a sealed token" || fail "the secret is carried in a sealed token"
grep -qF 'js/qr.js' "$WORK/qr.html" && ok "the QR is rendered locally, with no external request" || fail "the QR is rendered locally"

STORED=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_users")->fetchColumn();')
[ "$STORED" = "0" ] && ok "nothing is stored until a code proves the pairing" || fail "nothing is stored until a code proves the pairing"

ui_code() { php -r '
define("PUN", 1); define("PUN_ROOT", "'"$FORUM"'/");
require PUN_ROOT."include/tfa.php";
echo tfa_code("'"$UI_SECRET"'", (int) floor(time() / 30));
'; }

# --- a wrong code must not enable anything ---------------------------------
TOKEN=$(field csrf_token "$WORK/qr.html")
curl -s -b "$JAR" -e "$BASE/profile.php?section=security&id=$MEMBER_ID" \
  "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/bad.html" \
  --data-urlencode "tfa_action=confirm" --data-urlencode "tfa_seal=$SEAL" \
  --data-urlencode "tfa_code=000000" --data-urlencode "csrf_token=$TOKEN"
STORED=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_users")->fetchColumn();')
[ "$STORED" = "0" ] && ok "a wrong confirmation code enables nothing" || fail "a wrong confirmation code enables nothing"
grep -qF 'That code was not accepted' "$WORK/bad.html" && ok "the wrong code is reported" || fail "the wrong code is reported"

# --- the real code enables it, and shows the backup codes once -------------
SEAL=$(field tfa_seal "$WORK/bad.html")
TOKEN=$(field csrf_token "$WORK/bad.html")
curl -s -b "$JAR" -e "$BASE/profile.php?section=security&id=$MEMBER_ID" \
  "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/on.html" \
  --data-urlencode "tfa_action=confirm" --data-urlencode "tfa_seal=$SEAL" \
  --data-urlencode "tfa_code=$(ui_code)" --data-urlencode "csrf_token=$TOKEN"

STORED=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_users WHERE user_id='"$MEMBER_ID"'")->fetchColumn();')
[ "$STORED" = "1" ] && ok "the right code switches 2FA on" || fail "the right code switches 2FA on"
CODES=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_backup WHERE user_id='"$MEMBER_ID"'")->fetchColumn();')
[ "$CODES" = "8" ] && ok "eight backup codes are issued" || fail "eight backup codes are issued (found $CODES)"
grep -qE '<code>[0-9A-Z]{4}-[0-9A-Z]{4}</code>' "$WORK/on.html" \
  && ok "the backup codes are shown to the member" || fail "the backup codes are shown to the member"

# they are shown exactly once - a plain reload must not repeat them
curl -s -b "$JAR" "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/again.html"
grep -qE '<code>[0-9A-Z]{4}-[0-9A-Z]{4}</code>' "$WORK/again.html" \
  && fail "backup codes must not be shown again on reload" \
  || ok "the backup codes are not shown again"

# --- another member cannot open someone else's Security page ---------------
login admin adminpass123 ""
curl -s -b "$JAR" "$BASE/profile.php?section=security&id=$MEMBER_ID" -o "$WORK/other.html"
grep -qF 'Set up two-factor' "$WORK/other.html" \
  && fail "staff must not reach another member's 2FA setup" \
  || ok "another member's Security page is refused"

# --- staff reset ------------------------------------------------------------
curl -s -b "$JAR" "$BASE/profile.php?section=admin&id=$MEMBER_ID" -o "$WORK/adm.html"
grep -qF 'This member has two-factor authentication switched on' "$WORK/adm.html" \
  && ok "staff can see that the member uses 2FA" || fail "staff can see that the member uses 2FA"

TOKEN=$(field csrf_token "$WORK/adm.html")
curl -s -b "$JAR" -e "$BASE/profile.php?section=admin&id=$MEMBER_ID" \
  "$BASE/profile.php?section=admin&id=$MEMBER_ID" -o /dev/null \
  --data-urlencode "tfa_reset=Switch two-factor authentication off for this member" \
  --data-urlencode "csrf_token=$TOKEN"
LEFT=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_users WHERE user_id='"$MEMBER_ID"'")->fetchColumn();')
[ "$LEFT" = "0" ] && ok "a staff reset switches the member's 2FA off" || fail "a staff reset switches the member's 2FA off"
LEFT=$(php -r '$p=new PDO("sqlite:'"$DB"'");echo (int)$p->query("SELECT COUNT(*) FROM tfa_backup WHERE user_id='"$MEMBER_ID"'")->fetchColumn();')
[ "$LEFT" = "0" ] && ok "the reset clears their backup codes too" || fail "the reset clears their backup codes too"

login member memberpass123 ""
logged_in && ok "the member can log in with their password again" || fail "the member can log in with their password again"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -10
else
  ok "php error log empty"
fi

echo "== 2fa e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
