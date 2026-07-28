#!/usr/bin/env bash
#
# showcase 1.3.0 - focused end-to-end suite
#
# Installs a real eveBB board (sqlite) through the real installer,
# activates the plugin, seeds two sections of release topics, and
# exercises the curated front page, the featured row, View all paging,
# the rev1 -> rev2 upgrade and the nuke path.
#
set -u

# Runs from anywhere in a checkout. The plugin under test is taken from
# its committed zip unless PLUGIN_SRC points at an unpacked folder.
REPO=$(cd "$(dirname "$0")/../.." && pwd)
ZIP=${ZIP:-$REPO/website/plugins/evebb-plugin-showcase-1.3.0.zip}
PORT=${PORT:-8731}
BASE="http://127.0.0.1:$PORT"
TMP=$(mktemp -d)
ROOT=${ROOT:-$TMP/board}
ERRLOG="$TMP/php-error.log"
DB="$ROOT/board.sqlite"
JAR="$TMP/jar.txt"
GJAR="$TMP/guest.txt"

if [ -z "${PLUGIN_SRC:-}" ]; then
  [ -f "$ZIP" ] || { echo "plugin zip not found: $ZIP"; exit 1; }
  mkdir -p "$TMP/unzip"
  unzip -q "$ZIP" -d "$TMP/unzip"
  PLUGIN_SRC="$TMP/unzip/showcase"
fi

pass=0; failn=0
ok()   { pass=$((pass+1)); echo "  ok  - $1"; }
fail() { failn=$((failn+1)); echo "  FAIL- $1"; }
chk()  { if [ "$1" = "1" ]; then ok "$2"; else fail "$2"; fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
count(){ grep -o -- "$2" "$1" | wc -l | tr -d ' '; }

cleanup() { [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

sql() { php -r '
$db = new PDO("sqlite:".$argv[1]);
$db->exec($argv[2]);
' "$DB" "$1"; }

q() { php -r '
$db = new PDO("sqlite:".$argv[1]);
foreach ($db->query($argv[2]) as $r) { echo $r[0], "\n"; }
' "$DB" "$1"; }

get()  { curl -s -b "$JAR" -c "$JAR" -o "$1" -w "%{http_code}" "$2"; }
gget() { curl -s -b "$GJAR" -c "$GJAR" -o "$1" -w "%{http_code}" "$2"; }

echo "== showcase 1.3.0 e2e =="

# --- fresh board -----------------------------------------------------------
rm -rf "$ROOT"; mkdir -p "$ROOT"
cp -r "$REPO/." "$ROOT/"
rm -rf "$ROOT/.git" "$ROOT/tests"
rm -f "$ROOT/config.php" "$ROOT/cache/cache_"*.php
mkdir -p "$ROOT/plugins/showcase"
cp "$PLUGIN_SRC"/* "$ROOT/plugins/showcase/"

cd "$ROOT"
php -d opcache.enable=0 -d error_reporting=E_ALL -d display_errors=0 \
    -d log_errors=1 -d error_log="$ERRLOG" -S 127.0.0.1:"$PORT" -t "$ROOT" >/dev/null 2>&1 &
SRV=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$BASE/install.php" && break; sleep 0.3; done

code=$(curl -s -o "$TMP/install.html" -w "%{http_code}" "$BASE/install.php" \
  --data-urlencode "form_sent=1" \
  --data-urlencode "install_lang=English" \
  --data-urlencode "req_db_type=sqlite" \
  --data-urlencode "req_db_host=localhost" \
  --data-urlencode "req_db_name=$DB" \
  --data-urlencode "db_username=" \
  --data-urlencode "db_password=" \
  --data-urlencode "db_prefix=" \
  --data-urlencode "req_username=admin" \
  --data-urlencode "req_password1=adminpass123" \
  --data-urlencode "req_password2=adminpass123" \
  --data-urlencode "req_email=admin@example.com" \
  --data-urlencode "req_title=Showcase Test" \
  --data-urlencode "desc=e2e" \
  --data-urlencode "req_base_url=$BASE" \
  --data-urlencode "req_default_lang=English" \
  --data-urlencode "req_default_style=Carbon" \
  --data-urlencode "start=Start install")
chk "$([ "$code" = "200" ] && echo 1 || echo 0)" "installer responds 200"
chk "$([ -f "$ROOT/config.php" ] && echo 1 || echo 0)" "config.php written"

# --- seed forums + release topics -----------------------------------------
# Section A (Plugins) gets 9 releases, section B (Styles) gets 3.
php -r '
$db = new PDO("sqlite:".$argv[1]);
$db->exec("INSERT INTO categories (cat_name, disp_position) VALUES (\"Community\", 1)");
$cat = $db->lastInsertId();
$fids = array();
foreach (array("Plugins","Styles") as $i => $name) {
	$db->exec("INSERT INTO forums (forum_name, forum_desc, redirect_url, moderators, num_topics, num_posts, sort_by, disp_position, cat_id) VALUES (\"$name\", \"\", NULL, NULL, 0, 0, 0, ".($i+1).", $cat)");
	$fids[] = $db->lastInsertId();
}
file_put_contents($argv[2], implode(",", $fids));
$now = time();
$mk = function($db, $forum, $subject, $msg, $when) {
	$db->exec("INSERT INTO topics (poster, subject, posted, first_post_id, last_post, last_post_id, num_views, num_replies, closed, sticky, moved_to, forum_id) VALUES (\"admin\", \"".$subject."\", $when, 0, $when, 0, 0, 2, 0, 0, NULL, $forum)");
	$tid = $db->lastInsertId();
	$db->exec("INSERT INTO posts (poster, poster_id, poster_ip, message, hide_smilies, posted, topic_id) VALUES (\"admin\", 2, \"127.0.0.1\", \"".$msg."\", 0, $when, $tid)");
	$pid = $db->lastInsertId();
	$db->exec("UPDATE topics SET first_post_id=$pid, last_post_id=$pid WHERE id=$tid");
	return $tid;
};
// forum 1: 9 releases, newest first by name Alpha..India
$names = array("Alpha","Bravo","Charlie","Delta","Echo","Foxtrot","Golf","Hotel","India");
foreach ($names as $i => $n) {
	// oldest first so India ends up newest
	$mk($db, $fids[0], $n." plugin 1.0.0", "Get it: [url]https://example.com/downloads/".strtolower($n).".zip[/url] - a fine plugin.", $now - (count($names)-$i)*3600);
}
// a non-release topic that must never appear
$mk($db, $fids[0], "How do I install a plugin?", "No download link here at all.", $now - 60);
// forum 2: 3 releases
foreach (array("Forest","Ocean","Ruby") as $i => $n) {
	$mk($db, $fids[1], $n." style 1.0.0", "Download [url]https://example.com/downloads/".strtolower($n).".zip[/url] now.", $now - (3-$i)*3600);
}
' "$DB" "$TMP/fids"
FA=$(cut -d, -f1 "$TMP/fids"); FB=$(cut -d, -f2 "$TMP/fids")
chk "$([ "$(q "SELECT COUNT(*) FROM topics WHERE forum_id IN ($FA,$FB)")" = "13" ] && echo 1 || echo 0)" "13 topics seeded (12 releases + 1 question)"

# --- activate the plugin (gotcha #11: the row already exists) --------------
sql "UPDATE config SET conf_value='showcase' WHERE conf_name='o_active_plugins'"
sql "INSERT INTO config (conf_name, conf_value) SELECT 'o_showcase_forums','$FA,$FB' WHERE NOT EXISTS (SELECT 1 FROM config WHERE conf_name='o_showcase_forums')"
rm -f "$ROOT/cache/cache_"*.php

# --- admin login ----------------------------------------------------------
curl -s -c "$JAR" -o "$TMP/loginform.html" "$BASE/login.php?action=in"
LTOKEN=$(grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/loginform.html" | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o "$TMP/login.html" "$BASE/login.php?action=in" \
  --data-urlencode "form_sent=1" \
  --data-urlencode "csrf_token=$LTOKEN" \
  --data-urlencode "req_username=admin" \
  --data-urlencode "req_password=adminpass123" \
  --data-urlencode "login=Login" >/dev/null
get "$TMP/idx.html" "$BASE/index.php" >/dev/null
chk "$(has "$TMP/idx.html" 'action=out')" "admin logged in"

# --- schema ---------------------------------------------------------------
get "$TMP/front.html" "$BASE/plugins/showcase/" >/dev/null
chk "$([ "$(q "SELECT conf_value FROM config WHERE conf_name='o_showcase_db_rev'")" = "2" ] && echo 1 || echo 0)" "db_rev is 2 after first view"
chk "$([ "$(q "SELECT COUNT(*) FROM pragma_table_info('showcase_approved') WHERE name='featured'")" = "1" ] && echo 1 || echo 0)" "featured column created"

# --- curated front page ---------------------------------------------------
chk "$([ "$(count "$TMP/front.html" 'class="showcase-card"')" = "7" ] && echo 1 || echo 0)" "front page shows 4 of 9 in Plugins + all 3 in Styles"
chk "$(has "$TMP/front.html" 'View all in Plugins')" "long section offers View all"
chk "$(hasnt "$TMP/front.html" 'View all in Styles')" "short section is shown whole, no View all"
chk "$(has "$TMP/front.html" 'India plugin')" "newest release is on the front page"
chk "$(hasnt "$TMP/front.html" 'Alpha plugin')" "oldest release is not on the front page"
chk "$(hasnt "$TMP/front.html" 'How do I install')" "question topic never becomes a card"
chk "$(hasnt "$TMP/front.html" '>Featured<')" "no Featured row until something is featured"

# --- admin controls -------------------------------------------------------
chk "$(has "$TMP/front.html" '>Feature</a>')" "admin sees Feature links"
gget "$TMP/guest.html" "$BASE/plugins/showcase/" >/dev/null
chk "$(hasnt "$TMP/guest.html" 'sc_feature=')" "guest sees no Feature links"
chk "$(hasnt "$TMP/guest.html" 'sc_approve=')" "guest sees no Approve links"

TOKEN=$(grep -o 'csrf_token=[a-f0-9]*' "$TMP/front.html" | head -1 | cut -d= -f2)
chk "$([ -n "$TOKEN" ] && echo 1 || echo 0)" "csrf token available to admin"
TA=$(q "SELECT id FROM topics WHERE subject LIKE 'Alpha plugin%'")
TB=$(q "SELECT id FROM topics WHERE subject LIKE 'Ocean style%'")

# guest cannot feature even with a token
gget /dev/null "$BASE/plugins/showcase/?sc_feature=$TA&state=1&csrf_token=$TOKEN" >/dev/null
chk "$([ "$(q 'SELECT COUNT(*) FROM showcase_approved WHERE featured>0')" = "0" ] && echo 1 || echo 0)" "guest cannot feature"

# admin without a token cannot feature
get /dev/null "$BASE/plugins/showcase/?sc_feature=$TA&state=1" >/dev/null
chk "$([ "$(q 'SELECT COUNT(*) FROM showcase_approved WHERE featured>0')" = "0" ] && echo 1 || echo 0)" "featuring without a CSRF token is refused"

# --- featuring ------------------------------------------------------------
get /dev/null "$BASE/plugins/showcase/?sc_feature=$TA&state=1&csrf_token=$TOKEN" >/dev/null
get /dev/null "$BASE/plugins/showcase/?sc_feature=$TB&state=1&csrf_token=$TOKEN" >/dev/null
get "$TMP/feat.html" "$BASE/plugins/showcase/" >/dev/null
chk "$(has "$TMP/feat.html" '>Featured<')" "Featured row appears once entries are flagged"
chk "$([ "$(q 'SELECT COUNT(*) FROM showcase_approved WHERE featured>0')" = "2" ] && echo 1 || echo 0)" "two entries flagged featured"
# Alpha (topic 1) was flagged first, so it leads the row
FIRST=$(php -r '
$h = file_get_contents($argv[1]);
$start = strpos($h, ">Featured<");
$seg = substr($h, $start, 4000);
preg_match("%viewtopic\.php\?id=(\d+)%", $seg, $m);
echo $m[1];
' "$TMP/feat.html")
chk "$([ "$FIRST" = "$TA" ] && echo 1 || echo 0)" "featured row is in flag order (Alpha first)"
chk "$(has "$TMP/feat.html" 'Alpha plugin')" "a featured old release reaches the front page"

# featuring is independent of approval
chk "$([ "$(q "SELECT approved_at FROM showcase_approved WHERE topic_id=$TA")" = "0" ] && echo 1 || echo 0)" "featured entry is not automatically approved"
get /dev/null "$BASE/plugins/showcase/?sc_approve=$TA&state=1&csrf_token=$TOKEN" >/dev/null
chk "$([ "$(q "SELECT COUNT(*) FROM showcase_approved WHERE topic_id=$TA AND approved_at>0 AND featured>0")" = "1" ] && echo 1 || echo 0)" "an entry can be approved and featured at once"
get /dev/null "$BASE/plugins/showcase/?sc_approve=$TA&state=0&csrf_token=$TOKEN" >/dev/null
chk "$([ "$(q "SELECT COUNT(*) FROM showcase_approved WHERE topic_id=$TA")" = "1" ] && echo 1 || echo 0)" "removing approval keeps the row while still featured"
get /dev/null "$BASE/plugins/showcase/?sc_feature=$TA&state=0&csrf_token=$TOKEN" >/dev/null
chk "$([ "$(q "SELECT COUNT(*) FROM showcase_approved WHERE topic_id=$TA")" = "0" ] && echo 1 || echo 0)" "row is pruned once neither approved nor featured"

# --- View all + paging ----------------------------------------------------
sql "DELETE FROM config WHERE conf_name='o_showcase_max'"
sql "INSERT INTO config (conf_name, conf_value) VALUES ('o_showcase_max','4')"
rm -f "$ROOT/cache/cache_"*.php
get "$TMP/all1.html" "$BASE/plugins/showcase/?view=all&f=1" >/dev/null
chk "$([ "$(count "$TMP/all1.html" 'class="showcase-card"')" = "4" ] && echo 1 || echo 0)" "View all page 1 holds one page of entries"
chk "$(has "$TMP/all1.html" 'Older &raquo;')" "pager offers the next page"
chk "$(hasnt "$TMP/all1.html" 'Newer')" "no Newer link on page 1"
get "$TMP/all3.html" "$BASE/plugins/showcase/?view=all&f=1&p=3" >/dev/null
chk "$([ "$(count "$TMP/all3.html" 'class="showcase-card"')" = "1" ] && echo 1 || echo 0)" "last page holds the remainder"
chk "$(has "$TMP/all3.html" 'Alpha plugin')" "oldest release is reachable by paging"
chk "$(has "$TMP/all3.html" 'Newer')" "pager offers the previous page"
chk "$(hasnt "$TMP/all3.html" 'Older &raquo;')" "no Older link on the last page"
get "$TMP/allbad.html" "$BASE/plugins/showcase/?view=all&f=999" >/dev/null
chk "$(has "$TMP/allbad.html" 'Everything in Plugins')" "unknown section falls back to the first"

# --- search still reaches the whole catalogue -----------------------------
get "$TMP/search.html" "$BASE/plugins/showcase/?q=Alpha" >/dev/null
chk "$(has "$TMP/search.html" 'Alpha plugin')" "search finds an entry that is not on the front page"
chk "$(hasnt "$TMP/search.html" 'India plugin')" "search excludes non-matching entries"
get "$TMP/searchpct.html" "$BASE/plugins/showcase/?q=%25" >/dev/null
chk "$([ "$(count "$TMP/searchpct.html" 'class="showcase-card"')" = "0" ] && echo 1 || echo 0)" "a literal % matches nothing (LIKE escaping holds)"

# --- curation can be switched off -----------------------------------------
sql "DELETE FROM config WHERE conf_name='o_showcase_latest'"
sql "INSERT INTO config (conf_name, conf_value) VALUES ('o_showcase_latest','0')"
sql "UPDATE config SET conf_value='50' WHERE conf_name='o_showcase_max'"
rm -f "$ROOT/cache/cache_"*.php
get "$TMP/off.html" "$BASE/plugins/showcase/" >/dev/null
chk "$([ "$(count "$TMP/off.html" 'class="showcase-card"')" = "12" ] && echo 1 || echo 0)" "latest=0 lists the whole catalogue (featured card not repeated)"
chk "$(hasnt "$TMP/off.html" 'View all in')" "no View all links when curation is off"
sql "UPDATE config SET conf_value='4' WHERE conf_name='o_showcase_latest'"
rm -f "$ROOT/cache/cache_"*.php

# --- rev1 -> rev2 upgrade -------------------------------------------------
sql "DROP TABLE showcase_approved"
sql "CREATE TABLE showcase_approved (topic_id INT NOT NULL, approved_by INT NOT NULL DEFAULT 0, approved_at INT NOT NULL DEFAULT 0, PRIMARY KEY (topic_id))"
sql "INSERT INTO showcase_approved (topic_id, approved_by, approved_at) VALUES ($TB, 2, 1750000000)"
sql "UPDATE config SET conf_value='1' WHERE conf_name='o_showcase_db_rev'"
rm -f "$ROOT/cache/cache_"*.php
get "$TMP/upg.html" "$BASE/plugins/showcase/" >/dev/null
chk "$([ "$(q "SELECT COUNT(*) FROM pragma_table_info('showcase_approved') WHERE name='featured'")" = "1" ] && echo 1 || echo 0)" "upgrade adds the featured column in place"
chk "$([ "$(q "SELECT COUNT(*) FROM showcase_approved WHERE topic_id=$TB AND approved_at>0")" = "1" ] && echo 1 || echo 0)" "existing approval survives the upgrade"
chk "$([ "$(q "SELECT conf_value FROM config WHERE conf_name='o_showcase_db_rev'")" = "2" ] && echo 1 || echo 0)" "db_rev advanced to 2"
chk "$(has "$TMP/upg.html" 'Approved')" "the upgraded approval still renders its badge"

# --- settings page --------------------------------------------------------
get "$TMP/set.html" "$BASE/admin_plugins.php?action=settings&plugin=showcase" >/dev/null
chk "$(has "$TMP/set.html" 'name="latest"')" "settings page offers the front-page count"
chk "$(has "$TMP/set.html" 'name="featured_max"')" "settings page offers the featured count"
STOKEN=$(grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/set.html" | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null \
  -H "Referer: $BASE/admin_plugins.php" \
  "$BASE/admin_plugins.php?action=settings&plugin=showcase" \
  --data-urlencode "csrf_token=$STOKEN" \
  --data-urlencode "forums=$FA,$FB" \
  --data-urlencode "max_entries=4" \
  --data-urlencode "latest=2" \
  --data-urlencode "featured_max=3" \
  --data-urlencode "navlink=1" \
  --data-urlencode "save_showcase=Save settings"
chk "$([ "$(q "SELECT conf_value FROM config WHERE conf_name='o_showcase_latest'")" = "2" ] && echo 1 || echo 0)" "settings save writes the front-page count"
get "$TMP/front2.html" "$BASE/plugins/showcase/" >/dev/null
chk "$([ "$(count "$TMP/front2.html" 'class="showcase-card"')" = "5" ] && echo 1 || echo 0)" "front page honours latest=2 (2 + all 3 short section)"

# --- nuke -----------------------------------------------------------------
curl -s -b "$JAR" -c "$JAR" -o /dev/null \
  -H "Referer: $BASE/admin_plugins.php" \
  "$BASE/admin_plugins.php?action=settings&plugin=showcase" \
  --data-urlencode "csrf_token=$STOKEN" \
  --data-urlencode "purge_showcase=Remove all showcase data"
chk "$([ "$(q "SELECT COUNT(*) FROM sqlite_master WHERE name='showcase_approved'")" = "0" ] && echo 1 || echo 0)" "nuke drops the table"
chk "$([ "$(q "SELECT COUNT(*) FROM config WHERE conf_name IN ('o_showcase_latest','o_showcase_featured_max')")" = "0" ] && echo 1 || echo 0)" "nuke clears the new config keys"

# --- log ------------------------------------------------------------------
grep -vE "login\.php on line|functions\.php on line 1305" "$ERRLOG" 2>/dev/null > "$TMP/ourlog" || true
if [ -s "$TMP/ourlog" ]; then
  echo "--- php error log ---"; cat "$TMP/ourlog"
  fail "PHP error log clean of plugin errors"
else
  ok "PHP error log clean of plugin errors"
fi
if [ -s "$ERRLOG" ]; then
  echo "  note - core noise during login, not from this plugin:"
  sed "s/^/    /" "$ERRLOG"
fi

echo
echo "== $pass passed, $failn failed =="
[ "$failn" = "0" ] || exit 1
