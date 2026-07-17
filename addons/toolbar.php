<?php

/**
 * eveBB BBCode toolbar addon.
 *
 * Based on the EZBBC Toolbar plugin for FluxBB, copyright (C) 2008-2010 Jojaba
 * (see plugins/toolbar/CREDITS). Ported to the eveBB addon/hook system:
 * no core files are modified, settings live in the database, and the
 * admin page is plugins/AP_Toolbar.php.
 *
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * This addon doubles as the standard example of how eveBB plugins are
 * built: an addon_*.php file registering hook callbacks, an optional
 * AP_*.php admin page, and assets under plugins/<name>/.
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

class addon_toolbar extends flux_addon
{
	function register($manager)
	{
		$manager->bind('header_head_end', array($this, 'inject_head'));
		$manager->bind('parser_smilies', array($this, 'extend_smilies'));
	}

	// Emit the toolbar CSS/JS into <head> on pages with a message or
	// signature textarea
	function inject_head()
	{
		// The include runs in this method's scope, so pull in the page
		// globals that toolbar_head.php relies on
		global $pun_config, $pun_user, $required_fields;

		if (isset($pun_config['o_toolbar_enabled']) && $pun_config['o_toolbar_enabled'] == '0')
			return;

		require PUN_ROOT.'plugins/toolbar/toolbar_head.php';
	}

	// Switch the parser to the extended toolbar smiley set when selected
	function extend_smilies()
	{
		global $pun_config;

		if (!isset($pun_config['o_toolbar_smilies']) || $pun_config['o_toolbar_smilies'] != '1')
			return;

		$GLOBALS['smiley_base'] = 'plugins/toolbar/style/smilies';
		$GLOBALS['smilies'] += array(
			'O:)'			=> 'angel.png',
			'o:)'			=> 'angel.png',
			':angel:'		=> 'angel.png',
			'8.('			=> 'cry.png',
			':cry:'			=> 'cry.png',
			']:D'			=> 'devil.png',
			':devil:'		=> 'devil.png',
			'8)'			=> 'glasses.png',
			':glasses:'		=> 'glasses.png',
			'{)'			=> 'kiss.png',
			':kiss:'		=> 'kiss.png',
			'8o'			=> 'monkey.png',
			':monkey:'		=> 'monkey.png',
			':8'			=> 'ops.png',
			':ops:'			=> 'ops.png',
		);
	}
}
