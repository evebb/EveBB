<?php

/**
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * WordPress-style plugin manager: install (upload a zip), activate,
 * deactivate, delete and configure manifest plugins from the browser,
 * so admins without FTP access can manage plugins.
 */

// Tell header.php to use the admin template
define('PUN_ADMIN_CONSOLE', 1);

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/common_admin.php';

if ($pun_user['g_id'] != PUN_ADMIN)
	message($lang_common['No permission'], false, '403 Forbidden');

require PUN_ROOT.'include/plugins.php';
require PUN_ROOT.'lang/'.$admin_language.'/admin_plugins.php';

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$slug = isset($_REQUEST['plugin']) ? pun_trim($_REQUEST['plugin']) : '';

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------

if ($action == 'upload' && isset($_POST['upload_plugin']))
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_POST['csrf_token'] ?? null);

	if (!isset($_FILES['plugin_file']) || $_FILES['plugin_file']['error'] == UPLOAD_ERR_NO_FILE)
		message($lang_admin_plugins['No file selected']);

	if ($_FILES['plugin_file']['error'] != UPLOAD_ERR_OK || !is_uploaded_file($_FILES['plugin_file']['tmp_name']))
		message($lang_admin_plugins['Upload failed']);

	$log = array();
	$installed = evebb_install_plugin_zip($_FILES['plugin_file']['tmp_name'], $log);

	if ($installed === false)
		message($lang_admin_plugins['Install failed'].'<br /><br />'.implode('<br />', array_map('pun_htmlspecialchars', $log)));

	redirect('admin_plugins.php', sprintf($lang_admin_plugins['Installed redirect'], pun_htmlspecialchars($installed)));
}
else if ($action == 'activate')
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_GET['csrf_token'] ?? null);

	if (!evebb_activate_plugin($slug))
		message($lang_admin_plugins['Unknown plugin']);

	redirect('admin_plugins.php', $lang_admin_plugins['Activated redirect']);
}
else if ($action == 'deactivate')
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_GET['csrf_token'] ?? null);

	evebb_deactivate_plugin($slug);

	redirect('admin_plugins.php', $lang_admin_plugins['Deactivated redirect']);
}
else if ($action == 'delete')
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_GET['csrf_token'] ?? null);

	if (evebb_read_manifest($slug) === null)
		message($lang_admin_plugins['Unknown plugin']);

	evebb_delete_plugin($slug);

	redirect('admin_plugins.php', $lang_admin_plugins['Deleted redirect']);
}

// ---------------------------------------------------------------------
// Settings sub-page for one plugin
// ---------------------------------------------------------------------

if ($action == 'settings')
{
	$plugin_manifest = evebb_read_manifest($slug);
	if ($plugin_manifest === null || empty($plugin_manifest['admin']))
		message($lang_admin_plugins['Unknown plugin']);

	$plugin_slug = $slug;
	$plugin_admin_file = PUN_ROOT.'plugins/'.$slug.'/'.$plugin_manifest['admin'];
	if (!file_exists($plugin_admin_file))
		message($lang_admin_plugins['Unknown plugin']);

	$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_admin_common['Admin'], $lang_admin_plugins['Plugins'], pun_htmlspecialchars($plugin_manifest['name']));
	define('PUN_ACTIVE_PAGE', 'admin');
	require PUN_ROOT.'header.php';

	// Highlight this plugin's own settings item in the admin menu
	generate_admin_menu('plugin_'.$slug);

?>
	<div class="blockform">
		<h2><span><?php printf($lang_admin_plugins['Plugin settings head'], pun_htmlspecialchars($plugin_manifest['name'])) ?></span></h2>
		<div class="box">
			<form method="post" action="admin_plugins.php?action=settings&amp;plugin=<?php echo pun_htmlspecialchars($plugin_slug) ?>">
<?php

	// The plugin's own settings markup (may handle its own POST above the
	// header — plugin admin.php files run before any output where needed)
	require $plugin_admin_file;

?>
			</form>
			<p class="topspace"><a href="admin_plugins.php">&#171; <?php echo $lang_admin_plugins['Back to plugins'] ?></a></p>
		</div>
	</div>
	<div class="clearer"></div>
</div>
<?php

	require PUN_ROOT.'footer.php';
	exit;
}

// ---------------------------------------------------------------------
// Main listing
// ---------------------------------------------------------------------

$installed = evebb_installed_plugins();

$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_admin_common['Admin'], $lang_admin_plugins['Plugins']);
define('PUN_ACTIVE_PAGE', 'admin');
require PUN_ROOT.'header.php';

generate_admin_menu('plugins');

?>
	<style type="text/css">
/* Scoped so the plugin list looks right on any theme */
#pluginmanage table.pluginlist { width: 100%; border-collapse: collapse; }
#pluginmanage table.pluginlist th, #pluginmanage table.pluginlist td { padding: 0.6em 0.8em; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
#pluginmanage table.pluginlist th { font-weight: bold; }
#pluginmanage table.pluginlist td.tc2, #pluginmanage table.pluginlist th.tc2, #pluginmanage table.pluginlist td.tc3, #pluginmanage table.pluginlist th.tc3 { width: 12%; white-space: nowrap; }
#pluginmanage table.pluginlist td.tcr, #pluginmanage table.pluginlist th.tcr { width: 22%; white-space: nowrap; }
#pluginmanage table.pluginlist .plugindesc { display: block; margin-top: 0.3em; font-size: 0.9em; }
	</style>
	<div id="pluginmanage">
	<div class="blockform">
		<h2><span><?php echo $lang_admin_plugins['Upload head'] ?></span></h2>
		<div class="box">
			<form method="post" enctype="multipart/form-data" action="admin_plugins.php?action=upload">
				<div class="inform">
					<input type="hidden" name="csrf_token" value="<?php echo pun_csrf_token() ?>" />
					<fieldset>
						<legend><?php echo $lang_admin_plugins['Upload legend'] ?></legend>
						<div class="infldset">
							<p><?php echo $lang_admin_plugins['Upload info'] ?></p>
							<p><strong><?php echo $lang_admin_plugins['Upload warning'] ?></strong></p>
							<p><input type="file" name="plugin_file" accept=".zip" /></p>
							<p class="topspace"><input type="submit" name="upload_plugin" value="<?php echo $lang_admin_plugins['Upload button'] ?>" /></p>
						</div>
					</fieldset>
				</div>
			</form>
		</div>
	</div>

	<div class="blockform">
		<h2><span><?php echo $lang_admin_plugins['Installed plugins head'] ?></span></h2>
		<div class="box">
			<div class="inform">
				<fieldset>
					<legend><?php echo $lang_admin_plugins['Installed plugins head'] ?></legend>
					<div class="infldset">
<?php

if (empty($installed))
	echo "\t\t\t\t\t\t".'<p>'.$lang_admin_plugins['No plugins'].'</p>'."\n";
else
{

?>
						<table class="pluginlist">
							<thead>
								<tr>
									<th class="tcl" scope="col"><?php echo $lang_admin_plugins['Col name'] ?></th>
									<th class="tc2" scope="col"><?php echo $lang_admin_plugins['Col version'] ?></th>
									<th class="tc3" scope="col"><?php echo $lang_admin_plugins['Col status'] ?></th>
									<th class="tcr" scope="col"><?php echo $lang_admin_plugins['Col actions'] ?></th>
								</tr>
							</thead>
							<tbody>
<?php

	$csrf = pun_csrf_token();
	foreach ($installed as $cur_slug => $manifest)
	{
		$active = evebb_plugin_is_active($cur_slug);
		$status = $active ? '<strong style="color: #107a3d;">'.$lang_admin_plugins['Active'].'</strong>' : '<span style="color: #888;">'.$lang_admin_plugins['Inactive'].'</span>';

		$actions = array();
		if ($active)
		{
			if (!empty($manifest['admin']))
				$actions[] = '<a href="admin_plugins.php?action=settings&amp;plugin='.urlencode($cur_slug).'">'.$lang_admin_plugins['Settings'].'</a>';
			$actions[] = '<a href="admin_plugins.php?action=deactivate&amp;plugin='.urlencode($cur_slug).'&amp;csrf_token='.$csrf.'">'.$lang_admin_plugins['Deactivate'].'</a>';
		}
		else
		{
			$actions[] = '<a href="admin_plugins.php?action=activate&amp;plugin='.urlencode($cur_slug).'&amp;csrf_token='.$csrf.'">'.$lang_admin_plugins['Activate'].'</a>';
			$actions[] = '<a href="admin_plugins.php?action=delete&amp;plugin='.urlencode($cur_slug).'&amp;csrf_token='.$csrf.'" onclick="return confirm(\''.$lang_admin_plugins['Delete confirm'].'\')">'.$lang_admin_plugins['Delete'].'</a>';
		}

		$name_cell = '<strong>'.pun_htmlspecialchars($manifest['name']).'</strong>';
		if ($manifest['author'] !== '')
			$name_cell .= ' <span class="byuser">'.sprintf($lang_admin_plugins['By author'], pun_htmlspecialchars($manifest['author'])).'</span>';
		if ($manifest['description'] !== '')
			$name_cell .= '<span class="plugindesc">'.pun_htmlspecialchars($manifest['description']).'</span>';

		echo "\t\t\t\t\t\t\t\t".'<tr>'."\n";
		echo "\t\t\t\t\t\t\t\t\t".'<td class="tcl">'.$name_cell.'</td>'."\n";
		echo "\t\t\t\t\t\t\t\t\t".'<td class="tc2">'.pun_htmlspecialchars($manifest['version']).'</td>'."\n";
		echo "\t\t\t\t\t\t\t\t\t".'<td class="tc3">'.$status.'</td>'."\n";
		echo "\t\t\t\t\t\t\t\t\t".'<td class="tcr">'.implode(' | ', $actions).'</td>'."\n";
		echo "\t\t\t\t\t\t\t\t".'</tr>'."\n";
	}

?>
							</tbody>
						</table>
<?php

}

?>
					</div>
				</fieldset>
			</div>
		</div>
	</div>
	</div>
	<div class="clearer"></div>
</div>
<?php

require PUN_ROOT.'footer.php';
