<?php

/**
 * Hello eveBB - the reference manifest plugin.
 *
 * Demonstrates the whole shape of an eveBB plugin:
 *   plugin.json  - the manifest (name, slug, version, addon, admin)
 *   hello.php    - this addon: a class named plugin_<slug> extending
 *                  flux_addon, registering hook callbacks
 *   admin.php    - an optional settings page shown in the plugin manager
 *
 * Only loaded when the plugin is active (see the plugin manager under
 * Administration -> Plugins).
 *
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

if (!defined('PUN'))
	exit;

class plugin_hello extends flux_addon
{
	function register($manager)
	{
		$manager->bind('header_head_end', array($this, 'inject_marker'));
	}

	function inject_marker()
	{
		global $pun_config;

		$message = isset($pun_config['o_hello_message']) ? $pun_config['o_hello_message'] : 'Hello from the eveBB plugin system';

		echo '<meta name="evebb-hello" content="'.pun_htmlspecialchars($message).'" />'."\n";
	}
}
