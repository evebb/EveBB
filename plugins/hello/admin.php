<?php

/**
 * Hello eveBB - settings page.
 *
 * Included by admin_plugins.php inside the admin chrome when the user
 * opens this plugin's settings. The following are available: $db,
 * $pun_config, $lang_* and the CSRF helpers. $plugin_slug holds this
 * plugin's slug and $plugin_manifest its manifest.
 *
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

if (!defined('PUN'))
	exit;

// Handle a save
if (isset($_POST['save_hello']))
{
	confirm_referrer('admin_plugins.php');
	check_csrf($_POST['csrf_token'] ?? null);

	$message = pun_trim($_POST['hello_message'] ?? '');

	if (isset($pun_config['o_hello_message']))
		$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$db->escape($message).'\' WHERE conf_name=\'o_hello_message\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	else
		$db->query('INSERT INTO '.$db->prefix.'config (conf_name, conf_value) VALUES(\'o_hello_message\', \''.$db->escape($message).'\')') or error('Unable to insert into board config', __FILE__, __LINE__, $db->error());

	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_config_cache();

	redirect('admin_plugins.php?action=settings&plugin='.$plugin_slug, 'Settings saved. Redirecting …');
}

$hello_message = isset($pun_config['o_hello_message']) ? $pun_config['o_hello_message'] : 'Hello from the eveBB plugin system';

?>
	<div class="inform">
		<input type="hidden" name="csrf_token" value="<?php echo pun_csrf_token() ?>" />
		<fieldset>
			<legend>Hello eveBB settings</legend>
			<div class="infldset">
				<p>This plugin adds a <code>&lt;meta name="evebb-hello"&gt;</code> tag to every page, using the message below. It exists to demonstrate the plugin format — view a page's source to see it.</p>
				<label>Marker message
					<br /><input type="text" name="hello_message" size="50" maxlength="255" value="<?php echo pun_htmlspecialchars($hello_message) ?>" />
				</label>
				<p class="topspace"><input type="submit" name="save_hello" value="Save settings" /></p>
			</div>
		</fieldset>
	</div>
<?php
