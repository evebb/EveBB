<?php

/**
 * eveBB BBCode toolbar - admin settings page.
 *
 * Based on the EZBBC Toolbar plugin for FluxBB, copyright (C) 2008-2010 Jojaba
 * (see plugins/toolbar/CREDITS).
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Settings are stored in the config table (no files are written), and
 * the toolbar itself is wired in through hooks by addons/addon_toolbar.php.
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

// Tell admin_loader.php that this is indeed a plugin and that it is loaded
define('PUN_PLUGIN_LOADED', 1);

define('TOOLBAR_VERSION', '2.0');

// Language file load (English first so translations may omit keys)
require PUN_ROOT.'plugins/toolbar/lang/English/toolbar.php';
$toolbar_lang_folder = 'English';
if ($admin_language != 'English' && file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$admin_language.'/toolbar.php'))
{
	$toolbar_lang_english = $lang_toolbar;
	$toolbar_lang_folder = $admin_language;
	require PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/toolbar.php';
	$lang_toolbar = array_merge($toolbar_lang_english, $lang_toolbar);
}

//
// Discover the available button styles
//
$toolbar_styles = array();
foreach (scandir(PUN_ROOT.'plugins/toolbar/style') as $entry)
{
	if ($entry[0] != '.' && file_exists(PUN_ROOT.'plugins/toolbar/style/'.$entry.'/toolbar.css'))
		$toolbar_styles[] = $entry;
}
natcasesort($toolbar_styles);

//
// Read current settings (with defaults)
//
$toolbar_enabled = !isset($pun_config['o_toolbar_enabled']) || $pun_config['o_toolbar_enabled'] == '1';
$toolbar_style = isset($pun_config['o_toolbar_style']) ? $pun_config['o_toolbar_style'] : 'Default';
$toolbar_smilies = isset($pun_config['o_toolbar_smilies']) ? $pun_config['o_toolbar_smilies'] : '0';

//
// Insert or update a config value, mirroring admin_options.php behaviour
//
function toolbar_save_config($name, $value)
{
	global $db, $pun_config;

	if (isset($pun_config[$name]))
		$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$db->escape($value).'\' WHERE conf_name=\''.$db->escape($name).'\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	else
		$db->query('INSERT INTO '.$db->prefix.'config (conf_name, conf_value) VALUES(\''.$db->escape($name).'\', \''.$db->escape($value).'\')') or error('Unable to insert into board config', __FILE__, __LINE__, $db->error());
}

//
// Handle a settings submission
//
if (isset($_POST['save_settings']))
{
	confirm_referrer('admin_loader.php');

	check_csrf($_POST['csrf_token'] ?? null);

	$new_enabled = isset($_POST['toolbar_enabled']) ? '1' : '0';
	$new_style = isset($_POST['toolbar_style']) ? pun_trim($_POST['toolbar_style']) : 'Default';
	$new_smilies = (isset($_POST['toolbar_smilies']) && $_POST['toolbar_smilies'] == '1') ? '1' : '0';

	// Only accept a style that actually exists
	if (!in_array($new_style, $toolbar_styles))
		$new_style = 'Default';

	toolbar_save_config('o_toolbar_enabled', $new_enabled);
	toolbar_save_config('o_toolbar_style', $new_style);
	toolbar_save_config('o_toolbar_smilies', $new_smilies);

	// Regenerate the config cache so the new settings take effect
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_config_cache();

	redirect('admin_loader.php?plugin='.$plugin, $lang_toolbar['Settings saved']);
}

// Display the admin navigation menu
generate_admin_menu($plugin);

?>
	<div class="plugin blockform">
		<h2><span><?php echo $lang_toolbar['Plugin title'] ?> (v<?php echo TOOLBAR_VERSION ?>)</span></h2>
		<div class="box">
			<div class="inbox">
				<p><?php echo $lang_toolbar['Explanation'] ?></p>
				<p><a href="plugins/toolbar/lang/<?php echo file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/help.php') ? $toolbar_lang_folder : 'English' ?>/help.php" target="_blank" rel="noopener"><?php echo $lang_toolbar['Toolbar help'] ?></a></p>
			</div>
		</div>

		<h2 class="block2"><span><?php echo $lang_toolbar['Form title'] ?></span></h2>
		<div class="box">
			<form method="post" action="admin_loader.php?plugin=<?php echo pun_htmlspecialchars($plugin) ?>">
				<div class="inform">
					<input type="hidden" name="csrf_token" value="<?php echo pun_csrf_token() ?>" />
					<fieldset>
						<legend><?php echo $lang_toolbar['Legend status'] ?></legend>
						<div class="infldset">
							<p><label><input type="checkbox" name="toolbar_enabled" value="1"<?php echo $toolbar_enabled ? ' checked="checked"' : '' ?> /> <?php echo $lang_toolbar['Enable'] ?></label></p>
						</div>
					</fieldset>
				</div>
				<div class="inform">
					<fieldset>
						<legend><?php echo $lang_toolbar['Legend style'] ?></legend>
						<div class="infldset">
							<dl>
<?php

foreach ($toolbar_styles as $cur_style)
{
	$checked = ($cur_style == $toolbar_style) ? ' checked="checked"' : '';
	$preview = file_exists(PUN_ROOT.'plugins/toolbar/style/'.$cur_style.'/images/preview.png') ? '<img src="plugins/toolbar/style/'.pun_htmlspecialchars($cur_style).'/images/preview.png" alt="'.$lang_toolbar['Toolbar preview'].'" />' : $lang_toolbar['No preview'];

	echo "\t\t\t\t\t\t\t\t".'<dt><label><input type="radio" name="toolbar_style" value="'.pun_htmlspecialchars($cur_style).'"'.$checked.' /> <strong>'.pun_htmlspecialchars($cur_style).'</strong></label></dt>'."\n";
	echo "\t\t\t\t\t\t\t\t".'<dd>'.$preview.'</dd>'."\n";
}

?>
							</dl>
						</div>
					</fieldset>
				</div>
				<div class="inform">
					<fieldset>
						<legend><?php echo $lang_toolbar['Smilies'] ?></legend>
						<div class="infldset">
							<dl>
								<dt><label><input type="radio" name="toolbar_smilies" value="0"<?php echo $toolbar_smilies != '1' ? ' checked="checked"' : '' ?> /> <strong><?php echo $lang_toolbar['Default smilies'] ?></strong></label></dt>
								<dd>
<?php

foreach (array('smile', 'neutral', 'sad', 'big_smile', 'yikes', 'wink', 'hmm', 'tongue', 'lol', 'mad', 'roll', 'cool') as $cur_icon)
	echo '<img src="img/smilies/'.$cur_icon.'.png" alt="'.$cur_icon.'" /> ';

?>
								</dd>
								<dt><label><input type="radio" name="toolbar_smilies" value="1"<?php echo $toolbar_smilies == '1' ? ' checked="checked"' : '' ?> /> <strong><?php echo $lang_toolbar['Toolbar smilies'] ?></strong></label></dt>
								<dd>
<?php

foreach (scandir(PUN_ROOT.'plugins/toolbar/style/smilies') as $cur_icon)
{
	if (substr($cur_icon, -4) == '.png')
		echo '<img src="plugins/toolbar/style/smilies/'.pun_htmlspecialchars($cur_icon).'" alt="'.pun_htmlspecialchars($cur_icon).'" /> ';
}

?>
								</dd>
							</dl>
							<div class="fsetsubmit"><input type="submit" name="save_settings" value="<?php echo $lang_toolbar['Save settings'] ?>" /></div>
						</div>
					</fieldset>
				</div>
			</form>
		</div>
	</div>
<?php

// Note that the script just ends here. The footer will be included by admin_loader.php
