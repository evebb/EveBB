#!/usr/bin/env bash
#
# End-to-end test of the board-logo (image title) feature: with no logo
# the text board title is shown; an uploaded logo replaces it and can be
# sized (including a full-width banner); the size accepts bare pixels or a
# CSS unit; a non-image upload is rejected; and removing the logo reverts
# to the text title. Uses SQLite, so no database server is needed.
#
set -u

PORT="${PORT:-$((9800 + RANDOM % 400))}"

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
  rm -rf "$WORK" "$ROOT"/img/board_logo.*
}
trap cleanup EXIT

cd "$ROOT"

echo "== board logo e2e =="

# --- a real (tiny) PNG and a decoy text file -------------------------------
base64 -d > "$WORK/logo.png" <<'EOF'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
EOF
printf 'this is not an image' > "$WORK/notimage.png"
[ -s "$WORK/logo.png" ] && ok "test logo image prepared" || fail "test logo image prepared"

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
  --data-urlencode "req_title=Logo Test" --data-urlencode "desc=x" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" --data-urlencode "req_default_style=Air" \
  --data-urlencode "start=Start install"
[ -f config.php ] && ok "forum installed" || fail "forum installed"

TOKEN=$(curl -s -c "$JAR" "$BASE/login.php" | grep -oE 'name="csrf_token" value="[a-f0-9]+"' | grep -oE '[a-f0-9]{20,}')
curl -s -b "$JAR" -c "$JAR" -e "$BASE/login.php" -o /dev/null -L "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "req_username=admin" --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" --data-urlencode "redirect_url=$BASE/index.php"

# --- helper: submit the full Options form (multipart) ----------------------
# The options save handler reads every form field directly, so we send a
# complete, valid set. Extra -F arguments (logo file, sizes, remove flag)
# are appended by each caller.
save_options() {
  curl -s -b "$JAR" -e "$BASE/admin_options.php" -o "$WORK/save.html" -L "$BASE/admin_options.php" \
    -F "form_sent=1" \
    -F "form[board_title]=Logo Test" \
    -F "form[board_desc]=A test board" \
    -F "form[base_url]=$BASE" \
    -F "form[default_timezone]=0" \
    -F "form[default_dst]=0" \
    -F "form[default_lang]=English" \
    -F "form[default_style]=Air" \
    -F "form[time_format]=H:i:s" \
    -F "form[date_format]=Y-m-d" \
    -F "form[timeout_visit]=1800" \
    -F "form[timeout_online]=300" \
    -F "form[redirect_delay]=1" \
    -F "form[show_version]=0" \
    -F "form[show_user_info]=1" \
    -F "form[show_post_count]=1" \
    -F "form[smilies]=1" \
    -F "form[smilies_sig]=1" \
    -F "form[make_links]=1" \
    -F "form[topic_review]=15" \
    -F "form[disp_topics_default]=30" \
    -F "form[disp_posts_default]=25" \
    -F "form[indent_num_spaces]=4" \
    -F "form[quote_depth]=3" \
    -F "form[quickpost]=1" \
    -F "form[users_online]=1" \
    -F "form[censoring]=0" \
    -F "form[signatures]=1" \
    -F "form[show_dot]=0" \
    -F "form[topic_views]=1" \
    -F "form[quickjump]=1" \
    -F "form[gzip]=0" \
    -F "form[search_all_forums]=1" \
    -F "form[additional_navlinks]=" \
    -F "form[feed_type]=2" \
    -F "form[feed_ttl]=0" \
    -F "form[report_method]=0" \
    -F "form[mailing_list]=admin@example.com" \
    -F "form[avatars]=1" \
    -F "form[avatars_dir]=img/avatars" \
    -F "form[avatars_width]=60" \
    -F "form[avatars_height]=60" \
    -F "form[avatars_size]=10240" \
    -F "form[admin_email]=admin@example.com" \
    -F "form[webmaster_email]=admin@example.com" \
    -F "form[forum_subscriptions]=1" \
    -F "form[topic_subscriptions]=1" \
    -F "form[smtp_host]=" \
    -F "form[smtp_user]=" \
    -F "form[smtp_ssl]=0" \
    -F "form[regs_allow]=1" \
    -F "form[regs_verify]=0" \
    -F "form[regs_report]=0" \
    -F "form[rules]=0" \
    -F "form[rules_message]=" \
    -F "form[default_email_setting]=1" \
    -F "form[announcement]=0" \
    -F "form[announcement_message]=" \
    -F "form[maintenance]=0" \
    -F "form[maintenance_message]=" \
    -F "form[logo_align]=${LOGO_ALIGN:-left}" \
    "$@"
}

# --- baseline: no logo -> text board title ---------------------------------
curl -s "$BASE/index.php" -o "$WORK/idx0.html"
assert_contains "$WORK/idx0.html" ">Logo Test</a></h1>" "text board title shown when no logo"
assert_not_contains "$WORK/idx0.html" 'id="brdlogo"' "no logo element when no logo set"

# --- the Options page exposes the logo controls ----------------------------
curl -s -b "$JAR" -e "$BASE/admin_index.php" "$BASE/admin_options.php" -o "$WORK/opts.html"
assert_contains "$WORK/opts.html" 'name="logo_file"' "logo upload field present"
assert_contains "$WORK/opts.html" 'name="form[logo_width]"' "logo width field present"
assert_contains "$WORK/opts.html" 'name="form[logo_align]"' "logo position field present"
assert_contains "$WORK/opts.html" 'enctype="multipart/form-data"' "options form accepts uploads"

# --- upload a logo (default left position) ---------------------------------
save_options -F "form[logo_width]=250" -F "form[logo_height]=80" \
  -F "logo_file=@$WORK/logo.png;type=image/png"
[ -f "$ROOT/img/board_logo.png" ] && ok "logo image stored to img/" || fail "logo image stored to img/"

curl -s "$BASE/index.php" -o "$WORK/idx1.html"
assert_contains "$WORK/idx1.html" 'id="brdlogo"' "logo element rendered after upload"
assert_contains "$WORK/idx1.html" 'class="brdlogo-left"' "default left position class emitted"
assert_contains "$WORK/idx1.html" 'img/board_logo.png' "logo image referenced in page"
assert_contains "$WORK/idx1.html" 'board_logo.png?v=' "logo URL carries a cache-busting token"
assert_contains "$WORK/idx1.html" 'width: 250px' "bare-number width treated as pixels"
assert_contains "$WORK/idx1.html" 'height: 80px' "bare-number height treated as pixels"
assert_contains "$WORK/idx1.html" 'alt="Logo Test"' "board title used as logo alt text"
assert_not_contains "$WORK/idx1.html" ">Logo Test</a></h1>" "text title replaced by logo"

# --- re-uploading a new image busts the cache (new token) ------------------
TOKEN1=$(grep -oE 'board_logo\.png\?v=[0-9]+' "$WORK/idx1.html" | head -1 | grep -oE '[0-9]+')
sleep 1
save_options -F "form[logo_width]=250" -F "form[logo_height]=80" \
  -F "logo_file=@$WORK/logo.png;type=image/png"
curl -s "$BASE/index.php" -o "$WORK/idxre.html"
TOKEN2=$(grep -oE 'board_logo\.png\?v=[0-9]+' "$WORK/idxre.html" | head -1 | grep -oE '[0-9]+')
[ -n "$TOKEN1" ] && [ -n "$TOKEN2" ] && [ "$TOKEN1" != "$TOKEN2" ] \
  && ok "re-upload changes the logo URL (cache busted)" \
  || fail "re-upload changes the logo URL (was '$TOKEN1' now '$TOKEN2')"

# --- position the logo centre (no re-upload) -------------------------------
LOGO_ALIGN=center save_options -F "form[logo_width]=250" -F "form[logo_height]=80"
curl -s "$BASE/index.php" -o "$WORK/idxc.html"
assert_contains "$WORK/idxc.html" 'class="brdlogo-center"' "centre position class emitted"
assert_contains "$WORK/idxc.html" 'img/board_logo.png' "logo preserved when only position changes"

# --- full-width banner fills the width (inline), ignores the manual size ----
LOGO_ALIGN=full save_options -F "form[logo_width]=250" -F "form[logo_height]=80"
curl -s "$BASE/index.php" -o "$WORK/idxf.html"
assert_contains "$WORK/idxf.html" 'class="brdlogo-full"' "full-width position class emitted"
assert_contains "$WORK/idxf.html" 'width: 100%' "full-width banner filled inline (works on any style)"
assert_contains "$WORK/idxf.html" 'height: auto' "full-width banner keeps aspect ratio (no distortion)"
assert_not_contains "$WORK/idxf.html" 'width: 250px' "manual size ignored for full-width banner"

# --- full-width fixed-height band crops to fill (object-fit: cover) ---------
LOGO_ALIGN=cover save_options -F "form[logo_width]=250" -F "form[logo_height]=180"
curl -s "$BASE/index.php" -o "$WORK/idxcov.html"
assert_contains "$WORK/idxcov.html" 'class="brdlogo-cover"' "cover position class emitted"
assert_contains "$WORK/idxcov.html" 'object-fit: cover' "cover fills the band without distortion"
assert_contains "$WORK/idxcov.html" 'height: 180px' "height box sets the band height"
assert_contains "$WORK/idxcov.html" 'width: 100%' "cover band fills full width"

# cover with no height falls back to the default band height
LOGO_ALIGN=cover save_options -F "form[logo_width]=" -F "form[logo_height]="
curl -s "$BASE/index.php" -o "$WORK/idxcov2.html"
assert_contains "$WORK/idxcov2.html" 'height: 200px' "cover uses default band height when blank"

# the bundled default style ships the positioning hooks
curl -s "$BASE/style/Air.css" -o "$WORK/air.css"
assert_contains "$WORK/air.css" "#brdlogo.brdlogo-full" "stylesheet carries full-width banner rule"
assert_contains "$WORK/air.css" "#brdlogo.brdlogo-cover" "stylesheet carries fixed-height cover rule"
assert_contains "$WORK/air.css" "#brdlogo.brdlogo-center" "stylesheet carries centre rule"

# --- back to left so the removal test below reads cleanly ------------------
save_options -F "form[logo_width]=250" -F "form[logo_height]=80"
curl -s "$BASE/index.php" -o "$WORK/idx2.html"
assert_contains "$WORK/idx2.html" 'img/board_logo.png' "logo preserved across position changes"

# --- a non-image upload is rejected ----------------------------------------
save_options -F "form[logo_width]=250" -F "form[logo_height]=80" \
  -F "logo_file=@$WORK/notimage.png;type=image/png"
assert_contains "$WORK/save.html" "not a supported image" "non-image upload rejected"
# the previously stored (valid) logo is untouched
grep -c "PNG" "$ROOT/img/board_logo.png" >/dev/null 2>&1 || true
curl -s "$BASE/index.php" -o "$WORK/idx3.html"
assert_contains "$WORK/idx3.html" 'img/board_logo.png' "existing logo survives a rejected upload"

# --- remove the logo -> revert to text -------------------------------------
save_options -F "form[logo_width]=" -F "form[logo_height]=" -F "remove_logo=1"
[ ! -f "$ROOT/img/board_logo.png" ] && ok "logo file removed on request" || fail "logo file removed on request"
curl -s "$BASE/index.php" -o "$WORK/idx4.html"
assert_contains "$WORK/idx4.html" ">Logo Test</a></h1>" "text title restored after removal"
assert_not_contains "$WORK/idx4.html" 'id="brdlogo"' "no logo element after removal"

if [ -s "$ERRLOG" ]; then
  fail "php error log empty"; sed 's/^/    | /' "$ERRLOG" | head -20
else
  ok "php error log empty"
fi

rm -f "$ROOT/config.php" "$ROOT"/cache/cache_*.php "$ROOT"/img/board_logo.*

echo "== board logo e2e: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
