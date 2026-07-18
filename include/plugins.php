<?php

/**
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Plugin management library for the WordPress-style plugin manager
 * (admin_plugins.php). A plugin is a folder under plugins/ containing a
 * plugin.json manifest:
 *
 *   {
 *     "name": "My Plugin",          (required, human readable)
 *     "slug": "myplugin",           (required, [a-z0-9_-], matches folder)
 *     "version": "1.0",             (required)
 *     "author": "...",              (optional)
 *     "description": "...",         (optional)
 *     "addon": "myplugin.php",      (optional PHP file defining class
 *                                    plugin_<slug> extends flux_addon)
 *     "admin": "admin.php"          (optional settings page, included
 *                                    inside the admin manager chrome)
 *   }
 *
 * Which plugins are active is stored in the o_active_plugins config
 * value (a comma-separated list of slugs), so activation state survives
 * core updates and is cached with the rest of the board config.
 */

if (!defined('PUN'))
	exit;


//
// Is a slug well-formed? (also guards against path traversal)
//
function evebb_plugin_slug_is_valid($slug)
{
	return is_string($slug) && $slug !== '' && preg_match('%^[a-z0-9][a-z0-9_\-]{0,63}$%', $slug) === 1;
}


//
// Validate a decoded manifest array. Returns true, or a human-readable
// error string.
//
function evebb_manifest_check($manifest, $expected_slug = null)
{
	if (!is_array($manifest))
		return 'The manifest (plugin.json) is not valid JSON.';

	foreach (array('name', 'slug', 'version') as $field)
	{
		if (empty($manifest[$field]) || !is_string($manifest[$field]))
			return 'The manifest is missing the required "'.$field.'" field.';
	}

	if (!evebb_plugin_slug_is_valid($manifest['slug']))
		return 'The manifest "slug" must be lowercase letters, numbers, hyphens or underscores.';

	if ($expected_slug !== null && $manifest['slug'] !== $expected_slug)
		return 'The manifest "slug" ('.$manifest['slug'].') does not match the plugin folder ('.$expected_slug.').';

	// Optional file references must stay inside the plugin folder
	foreach (array('addon', 'admin') as $field)
	{
		if (isset($manifest[$field]))
		{
			if (!is_string($manifest[$field]) || strpos($manifest[$field], '..') !== false || strpos($manifest[$field], '/') === 0 || substr($manifest[$field], -4) !== '.php')
				return 'The manifest "'.$field.'" must be a .php file inside the plugin folder.';
		}
	}

	return true;
}


//
// Read and validate a plugin's manifest. Returns the manifest array (with
// slug guaranteed) or null.
//
function evebb_read_manifest($slug)
{
	if (!evebb_plugin_slug_is_valid($slug))
		return null;

	$file = PUN_ROOT.'plugins/'.$slug.'/plugin.json';
	if (!file_exists($file))
		return null;

	$manifest = json_decode(file_get_contents($file), true);
	if (evebb_manifest_check($manifest, $slug) !== true)
		return null;

	// Defaults for optional fields
	$manifest += array('author' => '', 'description' => '', 'addon' => null, 'admin' => null);

	return $manifest;
}


//
// Discover all installed manifest plugins. Returns slug => manifest.
//
function evebb_installed_plugins()
{
	$plugins = array();

	if (!is_dir(PUN_ROOT.'plugins'))
		return $plugins;

	foreach (scandir(PUN_ROOT.'plugins') as $entry)
	{
		if ($entry[0] === '.' || !is_dir(PUN_ROOT.'plugins/'.$entry))
			continue;

		$manifest = evebb_read_manifest($entry);
		if ($manifest !== null)
			$plugins[$entry] = $manifest;
	}

	uasort($plugins, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

	return $plugins;
}


//
// The list of active plugin slugs (from the o_active_plugins config value)
//
function evebb_active_plugins()
{
	global $pun_config;

	if (empty($pun_config['o_active_plugins']))
		return array();

	return array_values(array_filter(array_map('trim', explode(',', $pun_config['o_active_plugins']))));
}


function evebb_plugin_is_active($slug)
{
	return in_array($slug, evebb_active_plugins(), true);
}


//
// Persist the active-plugin list and refresh the config cache
//
function evebb_write_active_plugins($slugs)
{
	global $db, $pun_config;

	$value = implode(',', array_unique($slugs));

	if (isset($pun_config['o_active_plugins']))
		$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$db->escape($value).'\' WHERE conf_name=\'o_active_plugins\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	else
		$db->query('INSERT INTO '.$db->prefix.'config (conf_name, conf_value) VALUES(\'o_active_plugins\', \''.$db->escape($value).'\')') or error('Unable to insert into board config', __FILE__, __LINE__, $db->error());

	$pun_config['o_active_plugins'] = $value;

	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_config_cache();
}


//
// Activate a plugin (must be installed with a valid manifest)
//
function evebb_activate_plugin($slug)
{
	if (evebb_read_manifest($slug) === null)
		return false;

	$active = evebb_active_plugins();
	if (!in_array($slug, $active, true))
	{
		$active[] = $slug;
		evebb_write_active_plugins($active);
	}

	return true;
}


//
// Deactivate a plugin (leaves the files in place)
//
function evebb_deactivate_plugin($slug)
{
	$active = evebb_active_plugins();
	$filtered = array_values(array_diff($active, array($slug)));

	if (count($filtered) !== count($active))
		evebb_write_active_plugins($filtered);

	return true;
}


//
// Recursively delete a directory tree
//
function evebb_plugin_rmtree($dir)
{
	if (!is_dir($dir))
		return;

	foreach (scandir($dir) as $entry)
	{
		if ($entry === '.' || $entry === '..')
			continue;

		$path = $dir.'/'.$entry;
		if (is_dir($path) && !is_link($path))
			evebb_plugin_rmtree($path);
		else
			@unlink($path);
	}

	@rmdir($dir);
}


//
// Deactivate and delete a plugin's files
//
function evebb_delete_plugin($slug)
{
	if (!evebb_plugin_slug_is_valid($slug))
		return false;

	evebb_deactivate_plugin($slug);
	evebb_plugin_rmtree(PUN_ROOT.'plugins/'.$slug);

	return !is_dir(PUN_ROOT.'plugins/'.$slug);
}


//
// Is a zip entry name safe to extract? (no zip-slip / absolute paths)
//
function evebb_plugin_entry_is_safe($name)
{
	if ($name === '' || $name[0] === '/' || $name[0] === '\\')
		return false;
	if (strpos($name, '..') !== false)
		return false;
	if (preg_match('%^[a-zA-Z]:%', $name))
		return false;

	return true;
}


//
// Install a plugin from an uploaded zip. The archive must contain a
// single top-level folder holding plugin.json (the common shape when you
// zip a plugin directory). Returns the installed slug on success, or
// false; $log collects human-readable detail either way.
//
function evebb_install_plugin_zip($zip_path, &$log)
{
	$log = array();

	if (!class_exists('ZipArchive'))
	{
		$log[] = 'The PHP zip extension is required to upload plugins.';
		return false;
	}

	if (!is_writable(PUN_ROOT.'plugins'))
	{
		$log[] = 'The plugins directory is not writable by PHP.';
		return false;
	}

	$zip = new ZipArchive();
	if ($zip->open($zip_path) !== true)
	{
		$log[] = 'The uploaded file is not a valid zip archive.';
		return false;
	}

	// Every entry must be safe, and we need exactly one top-level folder
	$top = null;
	$manifest_entry = null;
	for ($i = 0; $i < $zip->numFiles; $i++)
	{
		$name = $zip->getNameIndex($i);

		if (!evebb_plugin_entry_is_safe($name))
		{
			$log[] = 'The archive contains an unsafe path and was rejected: '.$name;
			$zip->close();
			return false;
		}

		$first = strtok($name, '/');
		if ($top === null)
			$top = $first;
		else if ($first !== $top)
		{
			$log[] = 'The archive must contain a single plugin folder at its root.';
			$zip->close();
			return false;
		}

		if ($name === $top.'/plugin.json')
			$manifest_entry = $name;
	}

	if ($top === null || $manifest_entry === null)
	{
		$log[] = 'The archive does not contain a plugin folder with a plugin.json manifest.';
		$zip->close();
		return false;
	}

	// Validate the manifest before writing anything
	$manifest = json_decode($zip->getFromName($manifest_entry), true);
	$check = evebb_manifest_check($manifest, $top);
	if ($check !== true)
	{
		$log[] = $check;
		$zip->close();
		return false;
	}

	$slug = $manifest['slug'];

	if (is_dir(PUN_ROOT.'plugins/'.$slug))
	{
		$log[] = 'A plugin named "'.$slug.'" is already installed. Delete it first to reinstall.';
		$zip->close();
		return false;
	}

	if (!$zip->extractTo(PUN_ROOT.'plugins'))
	{
		$log[] = 'Unable to extract the plugin into the plugins directory.';
		$zip->close();
		return false;
	}
	$zip->close();

	// Re-validate what actually landed on disk
	if (evebb_read_manifest($slug) === null)
	{
		$log[] = 'The extracted plugin failed validation and was removed.';
		evebb_plugin_rmtree(PUN_ROOT.'plugins/'.$slug);
		return false;
	}

	$log[] = 'Installed '.$manifest['name'].' '.$manifest['version'].'.';

	return $slug;
}
