<?php

/**
 * eveBB BBCode toolbar - settings page.
 *
 * Included by admin_plugins.php (Administration -> Plugins -> Settings)
 * inside the admin chrome and a <form> posting back to
 * admin_plugins.php?action=settings&plugin=toolbar. Activation is
 * handled by the plugin manager, so this page only configures the
 * button style and smiley set.
 *
 * Based on the EZBBC Toolbar plugin for FluxBB, copyright (C) 2008-2010
 * Jojaba (see CREDITS).
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

if (!defined('PUN'))
	exit;

// Language (English first so translations may omit keys)
require PUN_ROOT.'plugins/toolbar/lang/English/toolbar.php';
$toolbar_lang_folder = 'English';
if ($admin_language != 'English' && file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$admin_language.'/toolbar.php'))
{
	$toolbar_lang_english = $lang_toolbar;
	$toolbar_lang_folder = $admin_language;
	require PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/toolbar.php';
	$lang_toolbar = array_merge($toolbar_lang_english, $lang_toolbar);
}

// Discover the available button styles
$toolbar_styles = array();
foreach (scandir(PUN_ROOT.'plugins/toolbar/style') as $entry)
{
	if ($entry[0] != '.' && file_exists(PUN_ROOT.'plugins/toolbar/style/'.$entry.'/toolbar.css'))
		$toolbar_styles[] = $entry;
}
natcasesort($toolbar_styles);

// Insert or update a config value, mirroring admin_options.php behaviour
function toolbar_save_config($name, $value)
{
	global $db, $pun_config;

	if (isset($pun_config[$name]))
		$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$db->escape($value).'\' WHERE conf_name=\''.$db->escape($name).'\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	else
		$db->query('INSERT INTO '.$db->prefix.'config (conf_name, conf_value) VALUES(\''.$db->escape($name).'\', \''.$db->escape($value).'\')') or error('Unable to insert into board config', __FILE__, __LINE__, $db->error());
}

// Handle a settings submission
if (isset($_POST['save_settings']))
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_POST['csrf_token'] ?? null);

	$new_style = isset($_POST['toolbar_style']) ? pun_trim($_POST['toolbar_style']) : 'Default';
	$new_smilies = (isset($_POST['toolbar_smilies']) && $_POST['toolbar_smilies'] == '1') ? '1' : '0';

	if (!in_array($new_style, $toolbar_styles))
		$new_style = 'Default';

	toolbar_save_config('o_toolbar_style', $new_style);
	toolbar_save_config('o_toolbar_smilies', $new_smilies);

	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_config_cache();

	redirect('admin_plugins.php?action=settings&plugin='.$plugin_slug, $lang_toolbar['Settings saved']);
}

$toolbar_style = isset($pun_config['o_toolbar_style']) ? $pun_config['o_toolbar_style'] : 'Default';
$toolbar_smilies = isset($pun_config['o_toolbar_smilies']) ? $pun_config['o_toolbar_smilies'] : '0';
$help_folder = file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/help.php') ? $toolbar_lang_folder : 'English';

?>
	<div class="inform">
		<input type="hidden" name="csrf_token" value="<?php echo pun_csrf_token() ?>" />
		<fieldset>
			<legend><?php echo $lang_toolbar['Form title'] ?></legend>
			<div class="infldset">
				<p><?php echo $lang_toolbar['Explanation'] ?></p>
				<p><a href="plugins/toolbar/lang/<?php echo $help_folder ?>/help.php" target="_blank" rel="noopener"><?php echo $lang_toolbar['Toolbar help'] ?></a></p>
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

	echo "\t\t\t\t\t".'<dt><label><input type="radio" name="toolbar_style" value="'.pun_htmlspecialchars($cur_style).'"'.$checked.' /> <strong>'.pun_htmlspecialchars($cur_style).'</strong></label></dt>'."\n";
	echo "\t\t\t\t\t".'<dd>'.$preview.'</dd>'."\n";
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
<?php
