<?php

/**
 * eveBB registration CAPTCHA image endpoint
 *
 * Decodes the sealed token supplied in ?t= and renders the matching PNG.
 * Stateless: nothing is read from or written to the session or database.
 *
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

define('PUN_ROOT', dirname(__FILE__).'/');
define('PUN_QUIET_VISIT', 1); // don't disturb the "users online" list for an image
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/captcha.php';

if (!isset($pun_config['o_regs_captcha']) || $pun_config['o_regs_captcha'] != '1' || !evebb_captcha_available())
{
	header('HTTP/1.1 404 Not Found');
	exit;
}

$answer = evebb_captcha_decode(isset($_GET['t']) ? $_GET['t'] : '');

if ($answer === false)
{
	header('HTTP/1.1 400 Bad Request');
	exit;
}

evebb_captcha_render($answer);
