<?php

/**
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Style management library for the style manager (admin_styles.php).
 *
 * On disk a style keeps the classic FluxBB layout:
 *   style/<Name>.css      the main stylesheet (references assets as
 *                         <Name>/img/...)
 *   style/<Name>/         asset folder (img/, base_admin.css, and an
 *                         optional style.json with name/version/author)
 *
 * Styles dropped into style/ over FTP therefore keep working unchanged.
 *
 * An uploaded style package is a zip containing a single folder named
 * for the style's slug, holding:
 *   style.json            { "name", "slug", "version", "author" }
 *   <slug>.css            the main stylesheet
 *   img/ , base_admin.css , ...   assets
 * On install the package is transformed into the on-disk layout above.
 *
 * "Activating" a style means making it the board default
 * (o_default_style); users may still choose any installed style.
 */

if (!defined('PUN'))
	exit;


//
// Is a style slug well-formed? Style names are the CSS basename and the
// asset folder name, so uppercase is allowed (Air, Dark-buttons, ...).
// Also guards against path traversal.
//
function evebb_style_slug_is_valid($slug)
{
	return is_string($slug) && $slug !== '' && preg_match('%^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$%', $slug) === 1;
}


//
// Validate a decoded style manifest. Returns true or an error string.
//
function evebb_style_manifest_check($manifest, $expected_slug = null)
{
	if (!is_array($manifest))
		return 'The manifest (style.json) is not valid JSON.';

	foreach (array('name', 'slug', 'version') as $field)
	{
		if (empty($manifest[$field]) || !is_string($manifest[$field]))
			return 'The manifest is missing the required "'.$field.'" field.';
	}

	if (!evebb_style_slug_is_valid($manifest['slug']))
		return 'The manifest "slug" must be letters, numbers, hyphens or underscores.';

	if ($expected_slug !== null && $manifest['slug'] !== $expected_slug)
		return 'The manifest "slug" ('.$manifest['slug'].') does not match the style folder ('.$expected_slug.').';

	return true;
}


//
// Read a style's optional manifest metadata (style/<slug>/style.json).
// Returns the manifest array or null if absent/invalid.
//
function evebb_read_style_manifest($slug)
{
	if (!evebb_style_slug_is_valid($slug))
		return null;

	$file = PUN_ROOT.'style/'.$slug.'/style.json';
	if (!file_exists($file))
		return null;

	$manifest = json_decode(file_get_contents($file), true);
	if (evebb_style_manifest_check($manifest, $slug) !== true)
		return null;

	return $manifest + array('author' => '', 'description' => '');
}


//
// Every installed style (anything with a style/<Name>.css), keyed by
// slug, each entry carrying name/version/author (from style.json when
// present) and whether it is the current default.
//
function evebb_installed_styles()
{
	global $pun_config;

	$styles = array();
	$default = isset($pun_config['o_default_style']) ? $pun_config['o_default_style'] : '';

	foreach (scandir(PUN_ROOT.'style') as $entry)
	{
		if ($entry[0] === '.' || substr($entry, -4) !== '.css')
			continue;

		$slug = substr($entry, 0, -4);
		if (!evebb_style_slug_is_valid($slug))
			continue;

		$manifest = evebb_read_style_manifest($slug);
		$styles[$slug] = array(
			'slug'			=> $slug,
			'name'			=> ($manifest !== null) ? $manifest['name'] : str_replace('_', ' ', $slug),
			'version'		=> ($manifest !== null) ? $manifest['version'] : '',
			'author'		=> ($manifest !== null) ? $manifest['author'] : '',
			'is_default'	=> ($slug === $default),
		);
	}

	uasort($styles, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

	return $styles;
}


//
// Does a style exist on disk?
//
function evebb_style_exists($slug)
{
	return evebb_style_slug_is_valid($slug) && file_exists(PUN_ROOT.'style/'.$slug.'.css');
}


//
// Make a style the board default and refresh the config cache
//
function evebb_set_default_style($slug)
{
	global $db, $pun_config;

	if (!evebb_style_exists($slug))
		return false;

	$db->query('UPDATE '.$db->prefix.'config SET conf_value=\''.$db->escape($slug).'\' WHERE conf_name=\'o_default_style\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	$pun_config['o_default_style'] = $slug;

	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require PUN_ROOT.'include/cache.php';
	generate_config_cache();

	return true;
}


//
// Is a zip entry name safe to extract? (no zip-slip / absolute paths)
//
function evebb_style_entry_is_safe($name)
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
// Recursively copy a directory tree
//
function evebb_style_copytree($from, $to)
{
	if (!is_dir($to) && !@mkdir($to, 0755, true))
		return false;

	foreach (scandir($from) as $entry)
	{
		if ($entry === '.' || $entry === '..')
			continue;

		$src = $from.'/'.$entry;
		$dst = $to.'/'.$entry;

		if (is_dir($src))
		{
			if (!evebb_style_copytree($src, $dst))
				return false;
		}
		else if (!@copy($src, $dst))
			return false;
	}

	return true;
}


//
// Recursively remove a directory tree
//
function evebb_style_rmtree($dir)
{
	if (!is_dir($dir))
		return;

	foreach (scandir($dir) as $entry)
	{
		if ($entry === '.' || $entry === '..')
			continue;

		$path = $dir.'/'.$entry;
		if (is_dir($path) && !is_link($path))
			evebb_style_rmtree($path);
		else
			@unlink($path);
	}

	@rmdir($dir);
}


//
// Install a style from an uploaded zip. The archive must contain a
// single top-level folder named for the style slug, holding style.json
// and <slug>.css. Returns the installed slug on success, or false;
// $log collects human-readable detail either way.
//
function evebb_install_style_zip($zip_path, &$log)
{
	$log = array();

	if (!class_exists('ZipArchive'))
	{
		$log[] = 'The PHP zip extension is required to upload styles.';
		return false;
	}

	if (!is_writable(PUN_ROOT.'style'))
	{
		$log[] = 'The style directory is not writable by PHP.';
		return false;
	}

	$zip = new ZipArchive();
	if ($zip->open($zip_path) !== true)
	{
		$log[] = 'The uploaded file is not a valid zip archive.';
		return false;
	}

	// All entries safe, single top-level folder
	$top = null;
	for ($i = 0; $i < $zip->numFiles; $i++)
	{
		$name = $zip->getNameIndex($i);

		if (!evebb_style_entry_is_safe($name))
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
			$log[] = 'The archive must contain a single style folder at its root.';
			$zip->close();
			return false;
		}
	}

	if ($top === null)
	{
		$log[] = 'The archive is empty.';
		$zip->close();
		return false;
	}

	// Validate the manifest before writing anything
	$manifest_raw = $zip->getFromName($top.'/style.json');
	if ($manifest_raw === false)
	{
		$log[] = 'The style folder does not contain a style.json manifest.';
		$zip->close();
		return false;
	}

	$manifest = json_decode($manifest_raw, true);
	$check = evebb_style_manifest_check($manifest, $top);
	if ($check !== true)
	{
		$log[] = $check;
		$zip->close();
		return false;
	}

	$slug = $manifest['slug'];

	if ($zip->getFromName($top.'/'.$slug.'.css') === false)
	{
		$log[] = 'The style folder must contain the main stylesheet named "'.$slug.'.css".';
		$zip->close();
		return false;
	}

	if (evebb_style_exists($slug) || is_dir(PUN_ROOT.'style/'.$slug))
	{
		$log[] = 'A style named "'.$slug.'" is already installed. Delete it first to reinstall.';
		$zip->close();
		return false;
	}

	// Extract to a temporary location, then place files into the classic
	// on-disk layout
	$tmp = FORUM_CACHE_DIR.'evebb_style_tmp';
	evebb_style_rmtree($tmp);
	if (!@mkdir($tmp, 0755, true) || !$zip->extractTo($tmp))
	{
		$log[] = 'Unable to extract the style archive.';
		$zip->close();
		evebb_style_rmtree($tmp);
		return false;
	}
	$zip->close();

	$src = $tmp.'/'.$slug;

	// Main stylesheet -> style/<slug>.css
	$ok = @copy($src.'/'.$slug.'.css', PUN_ROOT.'style/'.$slug.'.css');

	// Everything else -> style/<slug>/ (assets, style.json, base_admin.css)
	if ($ok && !@mkdir(PUN_ROOT.'style/'.$slug, 0755, true))
		$ok = false;

	if ($ok)
	{
		foreach (scandir($src) as $entry)
		{
			if ($entry === '.' || $entry === '..' || $entry === $slug.'.css')
				continue;

			$s = $src.'/'.$entry;
			$d = PUN_ROOT.'style/'.$slug.'/'.$entry;
			$ok = is_dir($s) ? evebb_style_copytree($s, $d) : @copy($s, $d);
			if (!$ok)
				break;
		}
	}

	evebb_style_rmtree($tmp);

	if (!$ok || !evebb_style_exists($slug))
	{
		$log[] = 'The style could not be installed into the style directory.';
		@unlink(PUN_ROOT.'style/'.$slug.'.css');
		evebb_style_rmtree(PUN_ROOT.'style/'.$slug);
		return false;
	}

	$log[] = 'Installed '.$manifest['name'].' '.$manifest['version'].'.';

	return $slug;
}


//
// Delete a style's files. Guards against removing the current default or
// the last remaining style. Users still on the removed style fall back
// to the default automatically. Returns true on success; $log explains a
// failure.
//
function evebb_delete_style($slug, &$log)
{
	global $pun_config;

	$log = array();

	if (!evebb_style_exists($slug))
	{
		$log[] = 'That style could not be found.';
		return false;
	}

	if (isset($pun_config['o_default_style']) && $pun_config['o_default_style'] === $slug)
	{
		$log[] = 'You cannot delete the default style. Make another style the default first.';
		return false;
	}

	if (count(evebb_installed_styles()) <= 1)
	{
		$log[] = 'You cannot delete the only installed style.';
		return false;
	}

	@unlink(PUN_ROOT.'style/'.$slug.'.css');
	evebb_style_rmtree(PUN_ROOT.'style/'.$slug);

	return !evebb_style_exists($slug);
}
