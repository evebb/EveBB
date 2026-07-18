<?php

/**
 * Copyright (C) 2026 eveBB
 * based on FluxBB, copyright (C) 2008-2012 FluxBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * Self-update support: check GitHub for the latest eveBB release and
 * apply it in place. Used by admin_maintenance.php.
 *
 * config.php may define FORUM_UPDATE_API to point the checker at a
 * different (GitHub-compatible) releases endpoint - used by the test
 * suite and useful for private mirrors.
 */

if (!defined('PUN'))
	exit;


//
// The releases endpoint to query (GitHub API format)
//
function evebb_update_api_url()
{
	if (defined('FORUM_UPDATE_API'))
		return FORUM_UPDATE_API;

	return 'https://api.github.com/repos/evebb/EveBB/releases?per_page=10';
}


//
// Fetch a URL (GitHub requires a User-Agent). Returns body or false.
//
function evebb_http_get($url, $timeout = 30)
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER	=> true,
			CURLOPT_FOLLOWLOCATION	=> true,
			CURLOPT_MAXREDIRS		=> 5,
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_CONNECTTIMEOUT	=> 10,
			CURLOPT_USERAGENT		=> 'eveBB-updater/'.FORUM_VERSION,
			CURLOPT_HTTPHEADER		=> array('Accept: application/vnd.github+json, application/octet-stream, */*'),
		));
		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		unset($ch); // curl handles are garbage-collected; curl_close() is deprecated in PHP 8.5

		return ($body !== false && $status >= 200 && $status < 300) ? $body : false;
	}

	if (ini_get('allow_url_fopen'))
	{
		$context = stream_context_create(array('http' => array(
			'timeout'			=> $timeout,
			'follow_location'	=> 1,
			'header'			=> "User-Agent: eveBB-updater/".FORUM_VERSION."\r\nAccept: application/vnd.github+json, application/octet-stream, */*\r\n",
		)));
		$body = @file_get_contents($url, false, $context);

		return ($body !== false) ? $body : false;
	}

	return false;
}


//
// Query the releases feed. Returns an array describing the newest
// release (preferring stable over prereleases), or false on failure.
//
function evebb_check_latest_release()
{
	$body = evebb_http_get(evebb_update_api_url());
	if ($body === false)
		return false;

	$releases = json_decode($body, true);
	if (!is_array($releases))
		return false;

	// A single-release response (e.g. /releases/latest) is also accepted
	if (isset($releases['tag_name']))
		$releases = array($releases);

	$stable = $fallback = null;
	foreach ($releases as $release)
	{
		if (!is_array($release) || !isset($release['tag_name']) || !empty($release['draft']))
			continue;

		if ($fallback === null)
			$fallback = $release;

		if (empty($release['prerelease']))
		{
			$stable = $release;
			break;
		}
	}

	$release = ($stable !== null) ? $stable : $fallback;
	if ($release === null)
		return false;

	// Prefer a packaged asset (evebb-*.zip built by the release
	// workflow) over the raw source zipball
	$zip_url = isset($release['zipball_url']) ? $release['zipball_url'] : '';
	if (isset($release['assets']) && is_array($release['assets']))
	{
		foreach ($release['assets'] as $asset)
		{
			if (isset($asset['name'], $asset['browser_download_url']) && preg_match('%^evebb-.*\.zip$%i', $asset['name']))
			{
				$zip_url = $asset['browser_download_url'];
				break;
			}
		}
	}

	return array(
		'version'		=> ltrim((string) $release['tag_name'], 'vV'),
		'tag'			=> (string) $release['tag_name'],
		'prerelease'	=> !empty($release['prerelease']),
		'zip_url'		=> $zip_url,
		'url'			=> isset($release['html_url']) ? $release['html_url'] : '',
	);
}


//
// Is the given release newer than the running version?
//
function evebb_update_is_newer($version)
{
	return version_compare(FORUM_VERSION, $version, '<');
}


//
// Validate that a zip entry name is safe to extract (no zip-slip)
//
function evebb_update_entry_is_safe($name)
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
// Files/directories (relative to forum root) never written by an update.
// install.php ships in the release for fresh FTP installs, but a
// self-updating forum is already installed and must never (re)create it
// — it is a security risk and triggers the admin "install.php still
// exists" alert. It is skipped here and removed after applying.
//
function evebb_update_preserved_paths()
{
	return array(
		'config.php',
		'img/avatars',
		'cache',
		'.git',
		'install.php',
	);
}


//
// Recursively copy the extracted package over the forum root,
// skipping preserved paths. Returns true/false; appends to $log.
//
function evebb_update_copy_tree($from, $to, $prefix, &$log)
{
	$preserved = evebb_update_preserved_paths();

	$entries = scandir($from);
	if ($entries === false)
	{
		$log[] = 'Unable to read directory: '.$from;
		return false;
	}

	foreach ($entries as $entry)
	{
		if ($entry === '.' || $entry === '..')
			continue;

		$rel = ($prefix === '') ? $entry : $prefix.'/'.$entry;
		if (in_array($rel, $preserved))
			continue;

		$src = $from.'/'.$entry;
		$dst = $to.'/'.$entry;

		if (is_dir($src))
		{
			if (!is_dir($dst) && !@mkdir($dst, 0755, true))
			{
				$log[] = 'Unable to create directory: '.$rel;
				return false;
			}
			if (!evebb_update_copy_tree($src, $dst, $rel, $log))
				return false;
		}
		else
		{
			if (!@copy($src, $dst))
			{
				$log[] = 'Unable to write file: '.$rel;
				return false;
			}
		}
	}

	return true;
}


//
// Remove a directory tree (best effort)
//
function evebb_update_rmtree($dir)
{
	if (!is_dir($dir))
		return;

	foreach (scandir($dir) as $entry)
	{
		if ($entry === '.' || $entry === '..')
			continue;

		$path = $dir.'/'.$entry;
		if (is_dir($path))
			evebb_update_rmtree($path);
		else
			@unlink($path);
	}

	@rmdir($dir);
}


//
// Download and apply a release zip. Returns true on success; $log
// collects human-readable progress/errors either way.
//
function evebb_apply_update($zip_url, &$log)
{
	$log = array();

	// Preconditions
	if (!class_exists('ZipArchive'))
	{
		$log[] = 'The PHP zip extension is required for automatic updates. Update manually instead.';
		return false;
	}

	if ($zip_url == '')
	{
		$log[] = 'The release has no downloadable package.';
		return false;
	}

	if (!is_writable(PUN_ROOT) || !is_writable(PUN_ROOT.'index.php'))
	{
		$log[] = 'The forum directory is not writable by PHP, so files cannot be replaced automatically. Update manually instead.';
		return false;
	}

	@set_time_limit(0);

	// Download
	$log[] = 'Downloading '.$zip_url;
	$zip_data = evebb_http_get($zip_url, 120);
	if ($zip_data === false || strlen($zip_data) < 1024)
	{
		$log[] = 'Download failed.';
		return false;
	}

	$zip_file = FORUM_CACHE_DIR.'evebb_update.zip';
	if (@file_put_contents($zip_file, $zip_data) === false)
	{
		$log[] = 'Unable to write the download to the cache directory.';
		return false;
	}
	$log[] = 'Downloaded '.strlen($zip_data).' bytes.';

	// Open and validate
	$zip = new ZipArchive();
	if ($zip->open($zip_file) !== true)
	{
		$log[] = 'The downloaded file is not a valid zip archive.';
		@unlink($zip_file);
		return false;
	}

	for ($i = 0; $i < $zip->numFiles; $i++)
	{
		if (!evebb_update_entry_is_safe($zip->getNameIndex($i)))
		{
			$log[] = 'The archive contains an unsafe path and was rejected: '.$zip->getNameIndex($i);
			$zip->close();
			@unlink($zip_file);
			return false;
		}
	}

	// Extract
	$tmp_dir = FORUM_CACHE_DIR.'evebb_update_tmp';
	evebb_update_rmtree($tmp_dir);
	if (!@mkdir($tmp_dir, 0755, true) || !$zip->extractTo($tmp_dir))
	{
		$log[] = 'Unable to extract the archive.';
		$zip->close();
		@unlink($zip_file);
		return false;
	}
	$zip->close();

	// GitHub source zipballs wrap everything in a single top directory;
	// packaged assets have the files at the root. Find the package root.
	$package_root = $tmp_dir;
	if (!file_exists($package_root.'/include/common.php'))
	{
		foreach (scandir($tmp_dir) as $entry)
		{
			if ($entry !== '.' && $entry !== '..' && is_dir($tmp_dir.'/'.$entry) && file_exists($tmp_dir.'/'.$entry.'/include/common.php'))
			{
				$package_root = $tmp_dir.'/'.$entry;
				break;
			}
		}
	}

	if (!file_exists($package_root.'/include/common.php') || !file_exists($package_root.'/index.php'))
	{
		$log[] = 'The archive does not look like an eveBB release package.';
		evebb_update_rmtree($tmp_dir);
		@unlink($zip_file);
		return false;
	}

	// Apply
	$log[] = 'Copying files ...';
	$ok = evebb_update_copy_tree($package_root, rtrim(PUN_ROOT, '/'), '', $log);

	// Clear cached config/lang so the new version regenerates it
	foreach (glob(FORUM_CACHE_DIR.'cache_*.php') ?: array() as $cache_file)
		@unlink($cache_file);

	// A self-updating forum is already installed; remove install.php so
	// it is never left behind after an update (security + the admin alert)
	if ($ok && file_exists(PUN_ROOT.'install.php'))
	{
		@unlink(PUN_ROOT.'install.php');
		$log[] = 'Removed install.php.';
	}

	evebb_update_rmtree($tmp_dir);
	@unlink($zip_file);

	if ($ok)
		$log[] = 'Update applied.';

	return $ok;
}
