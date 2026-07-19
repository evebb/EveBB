<?php

/**
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Style manager: install (upload a zip), set the default and delete
 * styles from the browser, so admins without FTP access can manage
 * styles. Styles dropped into style/ over FTP still appear here.
 */

// Tell header.php to use the admin template
define('PUN_ADMIN_CONSOLE', 1);

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/common_admin.php';

if ($pun_user['g_id'] != PUN_ADMIN)
	message($lang_common['No permission'], false, '403 Forbidden');

require PUN_ROOT.'include/styles.php';
require PUN_ROOT.'lang/'.$admin_language.'/admin_styles.php';

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$slug = isset($_REQUEST['style']) ? pun_trim($_REQUEST['style']) : '';

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------

if ($action == 'upload' && isset($_POST['upload_style']))
{
	confirm_referrer('admin_styles.php');
	check_csrf($_POST['csrf_token'] ?? null);

	if (!isset($_FILES['style_file']) || $_FILES['style_file']['error'] == UPLOAD_ERR_NO_FILE)
		message($lang_admin_styles['No file selected']);

	if ($_FILES['style_file']['error'] != UPLOAD_ERR_OK || !is_uploaded_file($_FILES['style_file']['tmp_name']))
		message($lang_admin_styles['Upload failed']);

	$log = array();
	$installed = evebb_install_style_zip($_FILES['style_file']['tmp_name'], $log);

	if ($installed === false)
		message($lang_admin_styles['Install failed'].'<br /><br />'.implode('<br />', array_map('pun_htmlspecialchars', $log)));

	redirect('admin_styles.php', sprintf($lang_admin_styles['Installed redirect'], pun_htmlspecialchars($installed)));
}
else if ($action == 'default')
{
	confirm_referrer('admin_styles.php');
	check_csrf($_GET['csrf_token'] ?? null);

	if (!evebb_set_default_style($slug))
		message($lang_admin_styles['Unknown style']);

	redirect('admin_styles.php', $lang_admin_styles['Default set redirect']);
}
else if ($action == 'delete')
{
	confirm_referrer('admin_styles.php');
	check_csrf($_GET['csrf_token'] ?? null);

	$log = array();
	if (!evebb_delete_style($slug, $log))
		message($lang_admin_styles['Delete failed'].'<br /><br />'.implode('<br />', array_map('pun_htmlspecialchars', $log)));

	redirect('admin_styles.php', $lang_admin_styles['Deleted redirect']);
}

// ---------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------

$installed = evebb_installed_styles();

$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_admin_common['Admin'], $lang_admin_styles['Styles']);
define('PUN_ACTIVE_PAGE', 'admin');
require PUN_ROOT.'header.php';

generate_admin_menu('styles');

?>
	<style type="text/css">
/* Scoped so the style list looks right on any theme */
#stylemanage table.stylelist { width: 100%; border-collapse: collapse; }
#stylemanage table.stylelist th, #stylemanage table.stylelist td { padding: 0.6em 0.8em; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
#stylemanage table.stylelist th { font-weight: bold; }
#stylemanage table.stylelist td.tc2, #stylemanage table.stylelist th.tc2 { width: 12%; white-space: nowrap; }
#stylemanage table.stylelist td.tc3, #stylemanage table.stylelist th.tc3 { width: 14%; white-space: nowrap; }
#stylemanage table.stylelist td.tcr, #stylemanage table.stylelist th.tcr { width: 22%; white-space: nowrap; }
	</style>
	<div id="stylemanage">
	<div class="blockform">
		<h2><span><?php echo $lang_admin_styles['Upload head'] ?></span></h2>
		<div class="box">
			<form method="post" enctype="multipart/form-data" action="admin_styles.php?action=upload">
				<div class="inform">
					<fieldset>
						<legend><?php echo $lang_admin_styles['Upload legend'] ?></legend>
						<div class="infldset">
							<p><?php echo $lang_admin_styles['Upload info'] ?></p>
							<p><input type="file" name="style_file" accept=".zip" /></p>
							<p class="topspace"><input type="submit" name="upload_style" value="<?php echo $lang_admin_styles['Upload button'] ?>" /></p>
						</div>
					</fieldset>
				</div>
			</form>
		</div>
	</div>

	<div class="blockform">
		<h2><span><?php echo $lang_admin_styles['Installed styles head'] ?></span></h2>
		<div class="box">
			<div class="inform">
				<fieldset>
					<legend><?php echo $lang_admin_styles['Installed styles head'] ?></legend>
					<div class="infldset">
						<table class="stylelist">
							<thead>
								<tr>
									<th class="tcl" scope="col"><?php echo $lang_admin_styles['Col name'] ?></th>
									<th class="tc2" scope="col"><?php echo $lang_admin_styles['Col version'] ?></th>
									<th class="tc3" scope="col"><?php echo $lang_admin_styles['Col status'] ?></th>
									<th class="tcr" scope="col"><?php echo $lang_admin_styles['Col actions'] ?></th>
								</tr>
							</thead>
							<tbody>
<?php

$csrf = pun_csrf_token();
foreach ($installed as $cur_slug => $style)
{
	$status = $style['is_default'] ? '<strong style="color: #107a3d;">'.$lang_admin_styles['Default'].'</strong>' : '&#160;';

	$actions = array();
	if (!$style['is_default'])
	{
		$actions[] = '<a href="admin_styles.php?action=default&amp;style='.urlencode($cur_slug).'&amp;csrf_token='.$csrf.'">'.$lang_admin_styles['Set default'].'</a>';
		$actions[] = '<a href="admin_styles.php?action=delete&amp;style='.urlencode($cur_slug).'&amp;csrf_token='.$csrf.'" onclick="return confirm(\''.$lang_admin_styles['Delete confirm'].'\')">'.$lang_admin_styles['Delete'].'</a>';
	}

	$name_cell = '<strong>'.pun_htmlspecialchars($style['name']).'</strong>';
	if ($style['author'] !== '')
		$name_cell .= ' <span class="byuser">'.sprintf($lang_admin_styles['By author'], pun_htmlspecialchars($style['author'])).'</span>';

	echo "\t\t\t\t\t\t\t\t".'<tr>'."\n";
	echo "\t\t\t\t\t\t\t\t\t".'<td class="tcl">'.$name_cell.'</td>'."\n";
	echo "\t\t\t\t\t\t\t\t\t".'<td class="tc2">'.($style['version'] !== '' ? pun_htmlspecialchars($style['version']) : '&#160;').'</td>'."\n";
	echo "\t\t\t\t\t\t\t\t\t".'<td class="tc3">'.$status.'</td>'."\n";
	echo "\t\t\t\t\t\t\t\t\t".'<td class="tcr">'.(empty($actions) ? '&#160;' : implode(' | ', $actions)).'</td>'."\n";
	echo "\t\t\t\t\t\t\t\t".'</tr>'."\n";
}

?>
							</tbody>
						</table>
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
