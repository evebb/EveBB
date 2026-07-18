<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// Tell header.php to use the admin template
define('PUN_ADMIN_CONSOLE', 1);

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/common_admin.php';


if (!$pun_user['is_admmod'])
	message($lang_common['No permission'], false, '403 Forbidden');

// Load the admin_index.php language file
require PUN_ROOT.'lang/'.$admin_language.'/admin_index.php';

$action = isset($_GET['action']) ? $_GET['action'] : null;

// Remove install.php
if ($action == 'remove_install_file')
{
	$deleted = @unlink(PUN_ROOT.'install.php');

	if ($deleted)
		redirect('admin_index.php', $lang_admin_index['Deleted install.php redirect']);
	else
		message($lang_admin_index['Delete install.php failed']);
}

$install_file_exists = is_file(PUN_ROOT.'install.php');

// Is a newer eveBB release available? (cached; see include/update.php)
require PUN_ROOT.'include/update.php';
$update_info = evebb_update_available();

$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_admin_common['Admin'], $lang_admin_common['Index']);
define('PUN_ACTIVE_PAGE', 'admin');
require PUN_ROOT.'header.php';

generate_admin_menu('index');

?>
	<div class="block">
		<h2><span><?php echo $lang_admin_index['Forum admin head'] ?></span></h2>
		<div id="adintro" class="box">
			<div class="inbox">
				<p><?php echo $lang_admin_index['Welcome to admin'] ?></p>
				<ul>
					<li><span><?php echo $lang_admin_index['Welcome 1'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 2'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 3'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 4'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 5'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 6'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 7'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 8'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 9'] ?></span></li>
				</ul>
			</div>
		</div>

<?php if ($install_file_exists || $update_info) : ?>
		<h2 class="block2"><span><?php echo $lang_admin_index['Alerts head'] ?></span></h2>
		<div id="adalerts" class="box">
<?php if ($update_info) : ?>
			<p><?php printf($lang_admin_index['Update available'], pun_htmlspecialchars($update_info['version']), '<a href="admin_maintenance.php?action=check_update">'.$lang_admin_index['Update available link'].'</a>') ?></p>
<?php endif; ?>
<?php if ($install_file_exists) : ?>
			<p><?php printf($lang_admin_index['Install file exists'], '<a href="admin_index.php?action=remove_install_file">'.$lang_admin_index['Delete install file'].'</a>') ?></p>
<?php endif; ?>
		</div>
<?php endif; ?>

		<h2 class="block2"><span><?php echo $lang_admin_index['About head'] ?></span></h2>
		<div id="adstats" class="box">
			<div class="inbox">
				<dl>
					<dt><?php echo $lang_admin_index['eveBB version label'] ?></dt>
					<dd>
						<?php printf($lang_admin_index['eveBB version data']."\n", $pun_config['o_cur_version'], '<a href="admin_maintenance.php?action=check_update">'.$lang_admin_index['Check for upgrade'].'</a>') ?>
					</dd>
					<dt><?php echo $lang_admin_index['Server statistics label'] ?></dt>
					<dd>
						<a href="admin_statistics.php"><?php echo $lang_admin_index['View server statistics'] ?></a>
					</dd>
					<dt><?php echo $lang_admin_index['Support label'] ?></dt>
					<dd>
						<a href="https://github.com/evebb/evebb/issues"><?php echo $lang_admin_index['Forum label'] ?></a> - <a href="https://github.com/evebb/evebb/discussions"><?php echo $lang_admin_index['IRC label'] ?></a>
					</dd>
				</dl>
			</div>
		</div>
	</div>
	<div class="clearer"></div>
</div>
<?php

require PUN_ROOT.'footer.php';
