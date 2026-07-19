<?php

/**
 * eveBB XML sitemap for evebb.net
 *
 * Standalone endpoint: upload to the forum root (next to index.php) and
 * reference it from robots.txt. Lists the homepage, the board index, every
 * publicly readable forum and the newest publicly readable topics, with
 * last-modified dates from the board's own activity.
 *
 * Forums hidden from guests (read_forum = 0 for the Guests group) and all
 * topics inside them are excluded, so private areas never leak into the
 * sitemap. Output is cached for 30 minutes in the forum's cache directory.
 *
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

define('PUN_ROOT', dirname(__FILE__).'/');
define('PUN_QUIET_VISIT', 1);
require PUN_ROOT.'include/common.php';

define('SITEMAP_MAX_TOPICS', 2500);
define('SITEMAP_CACHE_TTL', 1800);

header('Content-Type: application/xml; charset=utf-8');

// Serve the cached copy while it is fresh
$cache_file = FORUM_CACHE_DIR.'cache_sitemap.xml';
if (is_file($cache_file) && (time() - filemtime($cache_file)) < SITEMAP_CACHE_TTL)
{
	readfile($cache_file);
	exit;
}

$base = rtrim($pun_config['o_base_url'], '/');

//
// One <url> entry
//
function sitemap_url($loc, $lastmod = null, $priority = null)
{
	$out = "\t<url>\n\t\t<loc>".pun_htmlspecialchars($loc)."</loc>\n";
	if ($lastmod !== null)
		$out .= "\t\t<lastmod>".gmdate('Y-m-d', (int) $lastmod)."</lastmod>\n";
	if ($priority !== null)
		$out .= "\t\t<priority>".$priority."</priority>\n";

	return $out."\t</url>\n";
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

// Homepage (the landing page) and the board index
$xml .= sitemap_url($base.'/', null, '1.0');
$xml .= sitemap_url($base.'/index.php', null, '0.9');

// Publicly readable forums: guests must not have an explicit read_forum=0 row
$forums = array();
$result = $db->query('
	SELECT f.id, f.last_post
	FROM '.$db->prefix.'forums AS f
	LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.PUN_GUEST.')
	WHERE f.redirect_url IS NULL AND (fp.read_forum IS NULL OR fp.read_forum=1)
	ORDER BY f.id') or error('Unable to fetch forums', __FILE__, __LINE__, $db->error());

while ($cur = $db->fetch_assoc($result))
{
	$forums[] = (int) $cur['id'];
	$xml .= sitemap_url($base.'/viewforum.php?id='.$cur['id'], $cur['last_post'] ?: null, '0.7');
}

// Newest topics in those forums (moved-topic pointers excluded)
if (!empty($forums))
{
	$result = $db->query('
		SELECT id, last_post
		FROM '.$db->prefix.'topics
		WHERE moved_to IS NULL AND forum_id IN ('.implode(',', $forums).')
		ORDER BY last_post DESC
		LIMIT '.SITEMAP_MAX_TOPICS) or error('Unable to fetch topics', __FILE__, __LINE__, $db->error());

	while ($cur = $db->fetch_assoc($result))
		$xml .= sitemap_url($base.'/viewtopic.php?id='.$cur['id'], $cur['last_post'], '0.5');
}

$xml .= '</urlset>'."\n";

// Cache (best effort) and emit
@file_put_contents($cache_file, $xml);
echo $xml;
