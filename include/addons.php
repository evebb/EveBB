<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;


/**
 * Class flux_addon_manager
 *
 * This class is responsible for loading the addons and storing their hook listeners.
 */
class flux_addon_manager
{
	var $hooks = array();

	var $loaded = false;

	function load()
	{
		$this->loaded = true;

		// Legacy addons: every file in addons/ is loaded unconditionally
		// (kept for backwards compatibility with pre-manifest plugins)
		$d = dir(PUN_ROOT.'addons');
		if ($d)
		{
			while (($addon_file = $d->read()) !== false)
			{
				if (!is_dir(PUN_ROOT.'addons/'.$addon_file) && preg_match('%(\w+)\.php$%', $addon_file))
				{
					$addon_name = 'addon_'.substr($addon_file, 0, -4);

					include PUN_ROOT.'addons/'.$addon_file;
					$addon = new $addon_name;

					$addon->register($this);
				}
			}
			$d->close();
		}

		// Manifest plugins: load only those marked active in the registry
		$this->load_active_plugins();
	}

	function load_active_plugins()
	{
		// The plugin library and config may not be available in every
		// context that pulls in addons.php; fail closed and quietly
		if (!function_exists('evebb_active_plugins'))
		{
			if (file_exists(PUN_ROOT.'include/plugins.php'))
				require_once PUN_ROOT.'include/plugins.php';
			else
				return;
		}

		foreach (evebb_active_plugins() as $slug)
		{
			$manifest = evebb_read_manifest($slug);
			if ($manifest === null || empty($manifest['addon']))
				continue;

			$addon_file = PUN_ROOT.'plugins/'.$slug.'/'.$manifest['addon'];
			if (!file_exists($addon_file))
				continue;

			include $addon_file;

			$class = 'plugin_'.$slug;
			if (class_exists($class))
			{
				$addon = new $class;
				$addon->register($this);
			}
		}
	}

	function bind($hook, $callback)
	{
		if (!isset($this->hooks[$hook]))
			$this->hooks[$hook] = array();

		if (is_callable($callback))
			$this->hooks[$hook][] = $callback;
	}

	function hook($name)
	{
		if (!$this->loaded)
			$this->load();

		$callbacks = isset($this->hooks[$name]) ? $this->hooks[$name] : array();

		// Execute every registered callback for this hook
		foreach ($callbacks as $callback)
		{
			list($addon, $method) = $callback;
			$addon->$method();
		}
	}
}


/**
 * Class flux_addon
 *
 * This class can be extended to provide addon functionality.
 * Subclasses should implement the register method which will be called so that they have a chance to register possible
 * listeners for all hooks.
 */
class flux_addon
{
	function register($manager)
	{ }
}
