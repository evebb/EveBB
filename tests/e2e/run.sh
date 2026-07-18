#!/usr/bin/env bash
#
# End-to-end characterization suite for eveBB.
#
# Installs a fresh forum over HTTP against the configured database and
# exercises the main user flows, asserting on rendered output and an
# empty PHP error log. Run it against different DB_TYPEs to prove a
# database-layer change is behavior-preserving:
#
#   DB_TYPE=mysqli DB_NAME=evebb_e2e ./tests/e2e/run.sh
#   DB_TYPE=mysql  DB_NAME=evebb_e2e ./tests/e2e/run.sh     # PDO MySQL
#   DB_TYPE=sqlite DB_NAME=/tmp/evebb-e2e.sqlite ./tests/e2e/run.sh
#
set -u

DB_TYPE="${DB_TYPE:-mysqli}"
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-evebb_e2e}"
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
  --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install")
assert_code 200 "$code" "installer responds"
[ -f config.php ] && ok "config.php written" || fail "config.php written"
if grep -qiE "fatal|uncaught" "$TMP/install.html"; then fail "installer page free of fatals"; else ok "installer page free of fatals"; fi

# --- guest browsing --------------------------------------------------------
code=$(curl -s -o "$TMP/index.html" -w "%{http_code}" "$BASE/index.php")
assert_code 200 "$code" "guest index"
assert_contains "$TMP/index.html" "<title>Test Forum" "index title"
assert_contains "$TMP/index.html" "Powered by" "index footer"
assert_contains "$TMP/index.html" "eveBB</a>" "footer credits eveBB"
grep -qF "based on" "$TMP/index.html" && fail "footer no longer says 'based on FluxBB'" || ok "footer no longer says 'based on FluxBB'"

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
// This test exercises the basic register+auto-login path, so disable the
// new defaults (email verification and required profile fields), which are
// covered by register-test.sh
$pdo->exec("UPDATE config SET conf_value = ".$pdo->quote("0")." WHERE conf_name IN (".$pdo->quote("o_regs_verify").", ".$pdo->quote("o_regs_require_profile").", ".$pdo->quote("o_rules").")");
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" 2>/dev/null || true
rm -f cache/cache_config.php
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

# --- footer copyright line (admin-supplied) --------------------------------
php -r '
$type = $argv[1]; $host = $argv[2]; $name = $argv[3]; $user = $argv[4]; $pass = $argv[5];
$dsn = $type === "sqlite" ? "sqlite:$name" : ((strpos($type, "pgsql") === 0 ? "pgsql" : "mysql").":host=$host;dbname=$name");
$pdo = new PDO($dsn, $type === "sqlite" ? null : $user, $type === "sqlite" ? null : $pass);
$pdo->exec("UPDATE config SET conf_value = ".$pdo->quote("(c) 2026 E2E Copyright")." WHERE conf_name = ".$pdo->quote("o_copyright_message"));
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" 2>/dev/null || true
rm -f cache/cache_config.php
curl -s "$BASE/index.php" -o "$TMP/footer.html"
assert_contains "$TMP/footer.html" '(c) 2026 E2E Copyright' "admin copyright line shows in the footer"

# --- avatars: bigger defaults + last-poster avatar on the index ------------
AV_W=$(php -r '
$type=$argv[1];$host=$argv[2];$name=$argv[3];$user=$argv[4];$pass=$argv[5];
$dsn=$type==="sqlite"?"sqlite:$name":((strpos($type,"pgsql")===0?"pgsql":"mysql").":host=$host;dbname=$name");
$pdo=new PDO($dsn,$type==="sqlite"?null:$user,$type==="sqlite"?null:$pass);
echo $pdo->query("SELECT conf_value FROM config WHERE conf_name=".$pdo->quote("o_avatars_width"))->fetchColumn();
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" 2>/dev/null)
[ "$AV_W" = "90" ] && ok "avatar default size is 90" || fail "avatar default size is 90 (got $AV_W)"
# a fresh install ships the generic default avatar, shown for members with
# no upload of their own (admin here has none)
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/postav.html"
assert_contains "$TMP/postav.html" 'default_avatar.png' "default avatar shown for a member without an upload"
# a member's own upload overrides the default
printf '\x89PNG\r\n\x1a\n' > "$TMP/av.png"; php -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="));' "$ROOT/img/avatars/2.png"
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/postav2.html"
assert_contains "$TMP/postav2.html" 'avatars/2.png' "member's own avatar overrides the default"
# ... and the last-poster avatar appears on the index
curl -s -b "$JAR" "$BASE/index.php" -o "$TMP/idxav.html"
assert_contains "$TMP/idxav.html" 'lastpostavatar' "last-poster avatar shown on the index"
# ... and on the topic list (viewforum) too
curl -s -b "$JAR" "$BASE/viewforum.php?id=1" -o "$TMP/vfav.html"
assert_contains "$TMP/vfav.html" 'lastpostavatar' "last-poster avatar shown on the topic list"
assert_contains "$TMP/vfav.html" 'avatars/2.png' "topic list last-post avatar uses the poster's upload"
rm -f "$ROOT/img/avatars/2.png"

# --- forum-index new-post indicator (themeable chat-bubble mask) -----------
[ -f "$ROOT/img/icon-newpost.svg" ] && ok "new-post indicator SVG shipped" || fail "new-post indicator SVG shipped"
php -r 'exit(@(new DOMDocument())->load($argv[1])?0:1);' "$ROOT/img/icon-newpost.svg" && ok "new-post indicator SVG is well-formed" || fail "new-post indicator SVG is well-formed"
assert_contains "$ROOT/style/Carbon.css" '--newpost-color-new' "new-post indicator colour is themeable"
assert_contains "$ROOT/style/Carbon.css" '--newpost-color-read' "read-state indicator colour is themeable"
assert_contains "$ROOT/style/Carbon.css" 'mask: var(--newpost-icon' "new-post indicator is drawn with a CSS mask"
# the forum row still carries the status classes the CSS targets
curl -s -b "$JAR" "$BASE/index.php" -o "$TMP/idxicon.html"
assert_contains "$TMP/idxicon.html" 'class="icon' "forum index emits the status-icon element"
# the topic list uses the same indicator + is scoped for it in the stylesheet
curl -s -b "$JAR" "$BASE/viewforum.php?id=1" -o "$TMP/vficon.html"
assert_contains "$TMP/vficon.html" 'class="icon' "topic list emits the status-icon element"
assert_contains "$ROOT/style/Carbon.css" '#punviewforum .icon' "topic-list indicator is styled"

# oversized uploads are resized to fit rather than rejected (admin is id 2)
rm -f "$ROOT"/img/avatars/2.*
php -r '$im=imagecreatetruecolor(460,460);imagefill($im,0,0,imagecolorallocate($im,120,90,60));imagepng($im,$argv[1]);' "$TMP/big_avatar.png"
curl -s -b "$JAR" -e "$BASE/profile.php?action=upload_avatar&id=2" \
  -F "form_sent=1" -F "req_file=@$TMP/big_avatar.png;type=image/png" \
  "$BASE/profile.php?action=upload_avatar2&id=2" -o "$TMP/upl.html"
STORED=$(ls "$ROOT"/img/avatars/2.* 2>/dev/null | head -1)
if [ -n "$STORED" ]; then
  set -- $(php -r 'list($w,$h)=getimagesize($argv[1]);echo "$w $h";' "$STORED")
  if [ "${1:-0}" -gt 0 ] && [ "${1:-0}" -le 90 ] && [ "${2:-0}" -le 90 ]; then
    ok "oversized avatar resized to fit 90x90 (got ${1}x${2})"
  else
    fail "oversized avatar resized to fit 90x90 (got ${1:-?}x${2:-?})"
  fi
else
  fail "oversized avatar resized to fit 90x90 (nothing stored)"
fi

# an image beyond the 1024x1024 cap is rejected and nothing is stored
rm -f "$ROOT"/img/avatars/2.*
php -r '$im=imagecreatetruecolor(1200,1200);imagefill($im,0,0,imagecolorallocate($im,10,20,30));imagepng($im,$argv[1]);' "$TMP/huge_avatar.png"
curl -s -b "$JAR" -e "$BASE/profile.php?action=upload_avatar&id=2" \
  -F "form_sent=1" -F "req_file=@$TMP/huge_avatar.png;type=image/png" \
  "$BASE/profile.php?action=upload_avatar2&id=2" -o "$TMP/upl2.html"
if [ -z "$(ls "$ROOT"/img/avatars/2.* 2>/dev/null)" ]; then
  ok "avatar beyond the 1024px cap is rejected"
else
  fail "avatar beyond the 1024px cap is rejected (a file was stored)"
fi
rm -f "$ROOT"/img/avatars/2.*

# --- post email link honours the poster's privacy setting ------------------
set_email_setting() { # value
  php -r '
$type=$argv[1];$host=$argv[2];$name=$argv[3];$user=$argv[4];$pass=$argv[5];$val=(int)$argv[6];
$dsn=$type==="sqlite"?"sqlite:$name":((strpos($type,"pgsql")===0?"pgsql":"mysql").":host=$host;dbname=$name");
$pdo=new PDO($dsn,$type==="sqlite"?null:$user,$type==="sqlite"?null:$pass);
$pdo->exec("UPDATE users SET email_setting=$val WHERE id=2");
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" "$1" 2>/dev/null
}

# 1 = hidden but form email allowed: the board form, never a mailto
set_email_setting 1
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/em1.html"
assert_contains "$TMP/em1.html" 'misc.php?email=2' "hidden email uses the board form link"
if grep -qF 'mailto:admin@example.com' "$TMP/em1.html"; then fail "hidden email not exposed via mailto (admin view)"; else ok "hidden email not exposed via mailto (admin view)"; fi

# 0 = display address: mailto is expected (the user opted in)
set_email_setting 0
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/em0.html"
assert_contains "$TMP/em0.html" 'mailto:admin@example.com' "public email still uses mailto when opted in"

# 2 = fully hidden: admin/mod may still reach them through the form, no mailto
set_email_setting 2
curl -s -b "$JAR" "$BASE/viewtopic.php?id=2" -o "$TMP/em2.html"
assert_contains "$TMP/em2.html" 'misc.php?email=2' "admin can still form-email a fully hidden user"
if grep -qF 'mailto:admin@example.com' "$TMP/em2.html"; then fail "fully hidden email not exposed via mailto to admin"; else ok "fully hidden email not exposed via mailto to admin"; fi
set_email_setting 1

# --- visual (WYSIWYG) editor -----------------------------------------------
# vendored SCEditor assets ship with the board
for f in js/sceditor/sceditor.min.js js/sceditor/formats/bbcode.js js/sceditor/plugins/alternative-lists.js js/sceditor/themes/default.min.css; do
  [ -f "$ROOT/$f" ] && ok "shipped $f" || fail "shipped $f"
done
# enabled by default: the reply page loads the editor over the message box
curl -s -b "$JAR" "$BASE/post.php?tid=2" -o "$TMP/wyz_on.html"
assert_contains "$TMP/wyz_on.html" 'js/sceditor/sceditor.min.js' "reply page loads the visual editor when enabled"
assert_contains "$TMP/wyz_on.html" 'evebb_sceditor_opts' "editor is initialised over the message box"
assert_contains "$TMP/wyz_on.html" '"format":"bbcode"' "editor runs in BBCode mode"
assert_contains "$TMP/wyz_on.html" 'source' "editor toolbar exposes a raw-BBCode source toggle"
# assets are referenced root-relative and resolved on the page's own scheme/host
# (so they are not blocked as mixed content when o_base_url's scheme is wrong)
assert_contains "$TMP/wyz_on.html" 'src="js/sceditor/sceditor.min.js"' "editor assets use root-relative paths"
assert_contains "$TMP/wyz_on.html" 'document.baseURI' "editor resolves asset URLs on the page's own scheme/host"
if grep -qF "$BASE/js/sceditor" "$TMP/wyz_on.html"; then fail "editor does not hardcode the base URL for assets"; else ok "editor does not hardcode the base URL for assets"; fi
# admin toggle off -> the plain textarea is used, no editor assets
set_wysiwyg() { # value
  php -r '
$type=$argv[1];$host=$argv[2];$name=$argv[3];$user=$argv[4];$pass=$argv[5];$val=(int)$argv[6];
$dsn=$type==="sqlite"?"sqlite:$name":((strpos($type,"pgsql")===0?"pgsql":"mysql").":host=$host;dbname=$name");
$pdo=new PDO($dsn,$type==="sqlite"?null:$user,$type==="sqlite"?null:$pass);
$pdo->exec("UPDATE config SET conf_value=$val WHERE conf_name=".$pdo->quote("o_wysiwyg"));
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" "$1" 2>/dev/null
  rm -f "$ROOT"/cache/cache_config.php
}
# with the editor on, the BBCode/tag/smilies hint line is redundant and hidden
for u in "post.php?tid=2" "viewtopic.php?id=2" "edit.php?id=2&action=edit" "profile.php?section=personality&id=2"; do
  curl -s -b "$JAR" "$BASE/$u" -o "$TMP/bbl.html"
  if grep -qF 'class="bblinks"' "$TMP/bbl.html"; then fail "BBCode hints hidden with editor on ($u)"; else ok "BBCode hints hidden with editor on ($u)"; fi
done
set_wysiwyg 0
curl -s -b "$JAR" "$BASE/post.php?tid=2" -o "$TMP/wyz_off.html"
if grep -qF 'js/sceditor/sceditor.min.js' "$TMP/wyz_off.html"; then fail "visual editor is skipped when disabled"; else ok "visual editor is skipped when disabled"; fi
assert_contains "$TMP/wyz_off.html" 'name="req_message"' "plain message box still present when editor disabled"
# ... and the hint line returns for the plain textarea
assert_contains "$TMP/wyz_off.html" 'class="bblinks"' "BBCode hints shown again when editor disabled"
set_wysiwyg 1

# --- smiley set: modern (Noto) images, rendered at 20px --------------------
SMW=$(php -r '$s=@getimagesize($argv[1]);echo (int)($s[0]??0);' "$ROOT/img/smilies/smile.png")
[ "${SMW:-0}" -ge 32 ] && ok "modern smiley images shipped (${SMW}px source)" || fail "modern smiley images shipped (got ${SMW}px)"
# a smiley in a post renders as the image at the new 20px size
php -r '
$type=$argv[1];$host=$argv[2];$name=$argv[3];$user=$argv[4];$pass=$argv[5];
$dsn=$type==="sqlite"?"sqlite:$name":((strpos($type,"pgsql")===0?"pgsql":"mysql").":host=$host;dbname=$name");
$pdo=new PDO($dsn,$type==="sqlite"?null:$user,$type==="sqlite"?null:$pass);
$pdo->exec("UPDATE posts SET message=".$pdo->quote("hi :)")." WHERE id=1");
' "$DB_TYPE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" 2>/dev/null
curl -s -b "$JAR" "$BASE/viewtopic.php?id=1" -o "$TMP/smpost.html"
assert_contains "$TMP/smpost.html" 'img/smilies/smile.png" width="20" height="20"' "smiley renders as a 20px image"

# --- mobile-friendly layout ------------------------------------------------
# forum pages carry the responsive viewport tag; the admin console does not
assert_contains "$TMP/smpost.html" 'name="viewport" content="width=device-width' "forum pages carry the viewport meta tag"
curl -s -b "$JAR" "$BASE/admin_options.php" -o "$TMP/adminvp.html"
if grep -qF 'name="viewport"' "$TMP/adminvp.html"; then fail "admin console keeps the desktop layout (no viewport tag)"; else ok "admin console keeps the desktop layout (no viewport tag)"; fi
# the default style ships the responsive layer
assert_contains "$ROOT/style/Carbon.css" '@media (max-width: 720px)' "Carbon carries the responsive layer"
# the editor's content stylesheet sizes emoticons down to the text size
assert_contains "$ROOT/js/sceditor/themes/content/evebb.css" 'img[data-sceditor-emoticon]{width:20px' "editor content CSS sizes emoticons to the text"
curl -s -b "$JAR" "$BASE/post.php?tid=2" -o "$TMP/wyzcss.html"
assert_contains "$TMP/wyzcss.html" 'themes/content/evebb.css' "editor loads the eveBB content stylesheet"

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
