<?php

/**
 * eveBB one-shot community setup for evebb.net
 *
 * Creates the community structure (categories, forums, per-forum permissions
 * and two extra user groups) for the official eveBB project board.
 *
 * HOW TO USE
 *   1. Upload this file to the forum root (next to index.php).
 *   2. Log in to the forum as an administrator.
 *   3. Visit setup_community.php in your browser, review the plan, click Apply.
 *   4. The script reports what it did and deletes itself.
 *
 * It is safe to run more than once: existing categories, forums and groups
 * are matched by name and reused, never duplicated.
 *
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';

// Administrators only
if ($pun_user['is_guest'] || $pun_user['g_id'] != PUN_ADMIN)
	message('You must be logged in as an administrator to run the community setup.');

// Group IDs fixed by the installer
define('SETUP_GID_MODS', 2);
define('SETUP_GID_GUESTS', 3);
define('SETUP_GID_MEMBERS', 4);


//
// The plan: categories -> forums -> per-group permission rows.
// Permissions are (read, reply, new-topic) triples; groups without a row fall
// back to their group defaults (members post everywhere, guests read-only).
// Administrators always bypass forum permissions.
//
function setup_plan($gid_support, $gid_contrib)
{
	return array(
		'eveBB' => array(
			'position' => 1,
			'rename_from' => 'General', // fresh installs call it this
			'forums' => array(
				array(
					'name' => 'Announcements & Releases',
					'rename_from' => 'Announcements',
					'desc' => 'Official release news and project announcements. Every new eveBB version is posted here.',
					'perms' => array(
						// staff start topics; everyone else reads and replies
						SETUP_GID_GUESTS	=> array(1, 0, 0),
						SETUP_GID_MEMBERS	=> array(1, 1, 0),
						SETUP_GID_MODS		=> array(1, 1, 1),
						$gid_support		=> array(1, 1, 0),
						$gid_contrib		=> array(1, 1, 0),
					),
				),
			),
		),
		'Support' => array(
			'position' => 2,
			'forums' => array(
				array(
					'name' => 'Installation & Upgrades',
					'desc' => 'Help with installing eveBB, upgrading between versions, and moving a FluxBB or PunBB board over.',
					'perms' => array(),
				),
				array(
					'name' => 'Configuration & Troubleshooting',
					'desc' => 'Admin settings, features, styling questions and fixing problems on a running board.',
					'perms' => array(),
				),
			),
		),
		'Community' => array(
			'position' => 3,
			'forums' => array(
				array(
					'name' => 'Styles & Themes',
					'desc' => 'Share recoloured and custom styles, and get help making your board look the way you want.',
					'perms' => array(),
				),
				array(
					'name' => 'Plugins & Modifications',
					'desc' => 'Plugins built on the flux_hook system, code modifications and integrations.',
					'perms' => array(),
				),
				array(
					'name' => 'Development',
					'desc' => 'Contributing to eveBB itself - the roadmap, pull requests and code discussion.',
					'perms' => array(),
				),
			),
		),
		'Staff' => array(
			'position' => 4,
			'forums' => array(
				array(
					'name' => 'Staff Room',
					'desc' => 'Private coordination for administrators, moderators and the support team.',
					'perms' => array(
						// hidden from the public; open to staff groups
						SETUP_GID_GUESTS	=> array(0, 0, 0),
						SETUP_GID_MEMBERS	=> array(0, 0, 0),
						SETUP_GID_MODS		=> array(1, 1, 1),
						$gid_support		=> array(1, 1, 1),
						$gid_contrib		=> array(0, 0, 0),
					),
				),
			),
		),
	);
}


//
// Find a group id by title, or false
//
function setup_find_group($title)
{
	global $db;

	$result = $db->query('SELECT g_id FROM '.$db->prefix.'groups WHERE g_title=\''.$db->escape($title).'\'') or error('Unable to fetch group', __FILE__, __LINE__, $db->error());
	return $db->has_rows($result) ? (int) $db->result($result) : false;
}


//
// Create the two extra groups (reusing them if they already exist).
// Returns array(gid_support, gid_contrib, log lines)
//
function setup_groups()
{
	global $db;

	$log = array();

	// Support Team: a moderator-capable group with deliberately modest powers -
	// its members can be assigned as moderators of the support forums (to move,
	// close and tidy topics) but cannot rename users, change passwords or ban.
	$gid_support = setup_find_group('Support Team');
	if ($gid_support === false)
	{
		$db->query('INSERT INTO '.$db->prefix.'groups (g_title, g_user_title, g_moderator, g_mod_edit_users, g_mod_rename_users, g_mod_change_passwords, g_mod_ban_users, g_mod_promote_users, g_read_board, g_view_users, g_post_replies, g_post_topics, g_edit_posts, g_delete_posts, g_delete_topics, g_set_title, g_search, g_search_users, g_send_email, g_post_flood, g_search_flood, g_email_flood, g_report_flood) VALUES(\'Support Team\', \'Support team\', 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 0, 10, 60, 0)') or error('Unable to add group', __FILE__, __LINE__, $db->error());
		$gid_support = (int) $db->insert_id();
		$log[] = 'Created group "Support Team" (moderator-capable; no rename/password/ban powers).';
	}
	else
		$log[] = 'Group "Support Team" already exists - reused.';

	// Contributor: identical rights to Members; a visible rank for people who
	// have contributed code, styles, plugins or sustained help.
	$gid_contrib = setup_find_group('Contributors');
	if ($gid_contrib === false)
	{
		$db->query('INSERT INTO '.$db->prefix.'groups (g_title, g_user_title, g_moderator, g_mod_edit_users, g_mod_rename_users, g_mod_change_passwords, g_mod_ban_users, g_mod_promote_users, g_read_board, g_view_users, g_post_replies, g_post_topics, g_edit_posts, g_delete_posts, g_delete_topics, g_set_title, g_search, g_search_users, g_send_email, g_post_flood, g_search_flood, g_email_flood, g_report_flood) VALUES(\'Contributors\', \'Contributor\', 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 30, 10, 60, 60)') or error('Unable to add group', __FILE__, __LINE__, $db->error());
		$gid_contrib = (int) $db->insert_id();
		$log[] = 'Created group "Contributors" (member rights, distinct rank).';
	}
	else
		$log[] = 'Group "Contributors" already exists - reused.';

	return array($gid_support, $gid_contrib, $log);
}


//
// Find a category by name, or false
//
function setup_find_cat($name)
{
	global $db;

	$result = $db->query('SELECT id FROM '.$db->prefix.'categories WHERE cat_name=\''.$db->escape($name).'\'') or error('Unable to fetch category', __FILE__, __LINE__, $db->error());
	return $db->has_rows($result) ? (int) $db->result($result) : false;
}


//
// Find a forum by name (any category), or false
//
function setup_find_forum($name)
{
	global $db;

	$result = $db->query('SELECT id FROM '.$db->prefix.'forums WHERE forum_name=\''.$db->escape($name).'\'') or error('Unable to fetch forum', __FILE__, __LINE__, $db->error());
	return $db->has_rows($result) ? (int) $db->result($result) : false;
}


//
// Apply the whole plan. Returns log lines.
//
function setup_apply()
{
	global $db;

	list($gid_support, $gid_contrib, $log) = setup_groups();
	$plan = setup_plan($gid_support, $gid_contrib);

	foreach ($plan as $cat_name => $cat)
	{
		// Category: reuse by name; adopt the fresh-install name if present
		$cat_id = setup_find_cat($cat_name);
		if ($cat_id === false && isset($cat['rename_from']))
		{
			$cat_id = setup_find_cat($cat['rename_from']);
			if ($cat_id !== false)
			{
				$db->query('UPDATE '.$db->prefix.'categories SET cat_name=\''.$db->escape($cat_name).'\' WHERE id='.$cat_id) or error('Unable to rename category', __FILE__, __LINE__, $db->error());
				$log[] = 'Renamed category "'.$cat['rename_from'].'" to "'.$cat_name.'".';
			}
		}
		if ($cat_id === false)
		{
			$db->query('INSERT INTO '.$db->prefix.'categories (cat_name, disp_position) VALUES(\''.$db->escape($cat_name).'\', '.(int) $cat['position'].')') or error('Unable to add category', __FILE__, __LINE__, $db->error());
			$cat_id = (int) $db->insert_id();
			$log[] = 'Created category "'.$cat_name.'".';
		}
		else
			$db->query('UPDATE '.$db->prefix.'categories SET disp_position='.(int) $cat['position'].' WHERE id='.$cat_id) or error('Unable to position category', __FILE__, __LINE__, $db->error());

		$position = 0;
		foreach ($cat['forums'] as $forum)
		{
			$position++;

			// Forum: reuse by name; adopt the fresh-install name if present
			$forum_id = setup_find_forum($forum['name']);
			if ($forum_id === false && isset($forum['rename_from']))
			{
				$forum_id = setup_find_forum($forum['rename_from']);
				if ($forum_id !== false)
				{
					$db->query('UPDATE '.$db->prefix.'forums SET forum_name=\''.$db->escape($forum['name']).'\' WHERE id='.$forum_id) or error('Unable to rename forum', __FILE__, __LINE__, $db->error());
					$log[] = 'Renamed forum "'.$forum['rename_from'].'" to "'.$forum['name'].'".';
				}
			}
			if ($forum_id === false)
			{
				$db->query('INSERT INTO '.$db->prefix.'forums (forum_name, forum_desc, disp_position, cat_id) VALUES(\''.$db->escape($forum['name']).'\', \''.$db->escape($forum['desc']).'\', '.$position.', '.$cat_id.')') or error('Unable to add forum', __FILE__, __LINE__, $db->error());
				$forum_id = (int) $db->insert_id();
				$log[] = 'Created forum "'.$forum['name'].'".';
			}
			else
			{
				$db->query('UPDATE '.$db->prefix.'forums SET forum_desc=\''.$db->escape($forum['desc']).'\', disp_position='.$position.', cat_id='.$cat_id.' WHERE id='.$forum_id) or error('Unable to update forum', __FILE__, __LINE__, $db->error());
				$log[] = 'Updated forum "'.$forum['name'].'" (description/position).';
			}

			// Permissions: replace this forum's rows with the plan's
			$db->query('DELETE FROM '.$db->prefix.'forum_perms WHERE forum_id='.$forum_id) or error('Unable to clear forum permissions', __FILE__, __LINE__, $db->error());
			foreach ($forum['perms'] as $gid => $p)
				$db->query('INSERT INTO '.$db->prefix.'forum_perms (group_id, forum_id, read_forum, post_replies, post_topics) VALUES('.(int) $gid.', '.$forum_id.', '.(int) $p[0].', '.(int) $p[1].', '.(int) $p[2].')') or error('Unable to set forum permissions', __FILE__, __LINE__, $db->error());
			if (!empty($forum['perms']))
				$log[] = '&nbsp;&nbsp;&rarr; permissions set for "'.$forum['name'].'".';
		}
	}

	return $log;
}


// ---------------------------------------------------------------------------

$applied = false;
$log = array();

if (isset($_POST['apply']))
{
	// The global CSRF guard in common.php has already validated csrf_token
	$log = setup_apply();

	// common.php opened a per-request transaction that footer.php would
	// normally commit; this standalone page never includes footer.php, so
	// commit explicitly - without this every change above is rolled back
	// when the script ends.
	$db->end_transaction();

	// Rebuild the quick-jump cache from the now-committed structure
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_quickjump_cache();

	$applied = true;

	// This tool has done its job - remove it from the server
	if (@unlink(__FILE__))
		$log[] = 'setup_community.php deleted itself.';
	else
		$log[] = '<strong>Could not delete setup_community.php - please delete it from the server yourself.</strong>';
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>eveBB community setup</title>
<style type="text/css">
body { font: 13px/1.6 Verdana, Arial, sans-serif; background: #f4f5f7; color: #2c2f33; margin: 0; }
.box { max-width: 760px; margin: 40px auto; background: #fff; border: 1px solid #e3e6ea; border-radius: 8px; padding: 28px 32px; }
h1 { font-family: Arial, sans-serif; color: #33373b; font-size: 22px; margin-top: 0; }
h2 { font-family: Arial, sans-serif; color: #33373b; font-size: 15px; margin: 22px 0 6px; }
ul { margin: 6px 0; padding-left: 22px; }
li { margin: 3px 0; }
.muted { color: #6b727b; font-size: 12px; }
.btn { background: #e67300; color: #fff; font-weight: bold; font-size: 15px; border: 0; border-radius: 6px; padding: 12px 26px; cursor: pointer; }
.btn:hover { background: #ff7a00; }
.ok { background: #eef9ee; border: 1px solid #cde8cd; border-radius: 6px; padding: 14px 18px; }
a { color: #e67300; }
</style>
</head>
<body>
<div class="box">
<?php if ($applied): ?>
	<h1>Community setup complete</h1>
	<div class="ok">
		<ul>
<?php foreach ($log as $line): ?>
			<li><?php echo $line ?></li>
<?php endforeach; ?>
		</ul>
	</div>
	<h2>Two manual steps to finish</h2>
	<ul>
		<li><strong>Assign forum moderators:</strong> Administration &rarr; Forums &rarr; edit a forum &rarr; add your Support Team members as moderators of the two Support forums (that lets them move, close and tidy topics there).</li>
		<li><strong>Add people to groups:</strong> Administration &rarr; Users &rarr; find the user &rarr; set their group to Support Team or Contributors.</li>
	</ul>
	<p><a href="index.php">Go to the board index</a> and check the new structure.</p>
<?php else: ?>
	<h1>eveBB community setup</h1>
	<p>This will set up the official eveBB community structure on this board. Existing items with matching names are reused, never duplicated.</p>
	<h2>Categories &amp; forums</h2>
	<ul>
		<li><strong>eveBB</strong> &mdash; Announcements &amp; Releases <span class="muted">(staff post, everyone can read and reply)</span></li>
		<li><strong>Support</strong> &mdash; Installation &amp; Upgrades &middot; Configuration &amp; Troubleshooting</li>
		<li><strong>Community</strong> &mdash; Styles &amp; Themes &middot; Plugins &amp; Modifications &middot; Development</li>
		<li><strong>Staff</strong> &mdash; Staff Room <span class="muted">(visible only to administrators, moderators and the support team)</span></li>
	</ul>
	<h2>User groups</h2>
	<ul>
		<li><strong>Support Team</strong> &mdash; moderator-capable helpers (no rename/password/ban powers)</li>
		<li><strong>Contributors</strong> &mdash; a visible rank for people who contribute code, styles or sustained help</li>
	</ul>
	<form method="post" action="setup_community.php">
		<input type="hidden" name="csrf_token" value="<?php echo pun_csrf_token() ?>" />
		<p><button class="btn" type="submit" name="apply" value="1">Apply this structure</button></p>
	</form>
	<p class="muted">After a successful run this script deletes itself from the server.</p>
<?php endif; ?>
</div>
</body>
</html>
