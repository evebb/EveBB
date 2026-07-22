<?php

/**
 * eveBB stateless registration CAPTCHA
 *
 * No session or database storage is required. The challenge answer is packed
 * with an expiry timestamp, encrypted with AES-256-CBC and authenticated with
 * HMAC-SHA256 (encrypt-then-MAC), then base64url-encoded into an opaque token.
 * The token travels in the form (hidden field) and in the image URL; the image
 * endpoint decodes it to draw the same characters. Because the key is derived
 * from the site's secret $cookie_seed, a client can neither read nor forge a
 * challenge, and a captured token stops working after it expires.
 *
 * Copyright (C) 2026 eveBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

if (!defined('PUN'))
	exit;

// Characters that are hard to confuse with one another (no 0/O, 1/I/L, etc.).
define('EVEBB_CAPTCHA_ALPHABET', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
define('EVEBB_CAPTCHA_LENGTH', 5);
define('EVEBB_CAPTCHA_TTL', 600); // seconds a challenge stays valid


//
// Is the CAPTCHA feature usable on this server? Requires GD for the image and
// OpenSSL (with AES-256-CBC) for the sealed token.
//
function evebb_captcha_available()
{
	static $available = null;

	if ($available !== null)
		return $available;

	$available = function_exists('imagecreatetruecolor')
		&& function_exists('imagepng')
		&& function_exists('openssl_encrypt')
		&& function_exists('random_int')
		&& in_array('aes-256-cbc', array_map('strtolower', openssl_get_cipher_methods()), true);

	return $available;
}


//
// Derive the 32-byte encryption key from the site secret.
//
function evebb_captcha_enc_key()
{
	global $cookie_seed;

	return hash('sha256', $cookie_seed.'|evebb_captcha_enc', true);
}


//
// Derive a separate 32-byte key for the authentication tag.
//
function evebb_captcha_mac_key()
{
	global $cookie_seed;

	return hash('sha256', $cookie_seed.'|evebb_captcha_mac', true);
}


//
// Generate a fresh random answer.
//
function evebb_captcha_new_answer()
{
	$alphabet = EVEBB_CAPTCHA_ALPHABET;
	$max = strlen($alphabet) - 1;
	$answer = '';

	for ($i = 0; $i < EVEBB_CAPTCHA_LENGTH; $i++)
		$answer .= $alphabet[random_int(0, $max)];

	return $answer;
}


//
// Seal an answer into an opaque, self-expiring token.
//
function evebb_captcha_encode($answer)
{
	$payload = $answer.'|'.(time() + EVEBB_CAPTCHA_TTL);
	$iv = random_bytes(16);
	$ciphertext = openssl_encrypt($payload, 'aes-256-cbc', evebb_captcha_enc_key(), OPENSSL_RAW_DATA, $iv);

	if ($ciphertext === false)
		return '';

	$mac = hash_hmac('sha256', $iv.$ciphertext, evebb_captcha_mac_key(), true);

	return rtrim(strtr(base64_encode($iv.$ciphertext.$mac), '+/', '-_'), '=');
}


//
// Create a brand-new challenge token (answer chosen at random).
//
function evebb_captcha_new_token()
{
	return evebb_captcha_encode(evebb_captcha_new_answer());
}


//
// Open a token and return its answer, or false if the token is missing,
// tampered with, or expired.
//
function evebb_captcha_decode($token)
{
	if (!is_string($token) || $token === '')
		return false;

	$raw = base64_decode(strtr($token, '-_', '+/'), true);

	// 16 (iv) + at least one 16-byte cipher block + 32 (mac)
	if ($raw === false || strlen($raw) < 16 + 16 + 32)
		return false;

	$iv = substr($raw, 0, 16);
	$mac = substr($raw, -32);
	$ciphertext = substr($raw, 16, -32);

	$expected = hash_hmac('sha256', $iv.$ciphertext, evebb_captcha_mac_key(), true);
	if (!hash_equals($expected, $mac))
		return false;

	$payload = openssl_decrypt($ciphertext, 'aes-256-cbc', evebb_captcha_enc_key(), OPENSSL_RAW_DATA, $iv);
	if ($payload === false)
		return false;

	$parts = explode('|', $payload, 2);
	if (count($parts) !== 2)
		return false;

	list($answer, $expiry) = $parts;

	if (!ctype_digit($expiry) || (int) $expiry < time())
		return false;

	if (strlen($answer) !== EVEBB_CAPTCHA_LENGTH)
		return false;

	return $answer;
}


//
// Validate a user's typed response against a token. Case-insensitive.
//
function evebb_captcha_check($token, $response)
{
	$answer = evebb_captcha_decode($token);
	if ($answer === false)
		return false;

	$response = strtoupper(trim((string) $response));

	return hash_equals(strtoupper($answer), $response);
}


//
// Emit a PNG of the answer to the browser (with anti-OCR distortion). Sends its
// own Content-Type header, so nothing else may be output on this request.
//
function evebb_captcha_render($answer)
{
	$width = 200;
	$height = 60;

	$img = imagecreatetruecolor($width, $height);
	$bg = imagecolorallocate($img, 248, 248, 248);
	imagefilledrectangle($img, 0, 0, $width, $height, $bg);

	// Background speckle so a flat OCR pass has to fight noise.
	for ($i = 0; $i < 400; $i++)
	{
		$c = imagecolorallocate($img, random_int(170, 225), random_int(170, 225), random_int(170, 225));
		imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
	}

	// Each glyph is drawn large on its own transparent tile, rotated a little,
	// and composited — this only needs GD's built-in bitmap font, so there is
	// no dependency on any TrueType font being installed on the host.
	$len = strlen($answer);
	$margin = 14;
	$slot = (int) (($width - 2 * $margin) / $len);
	$font = 5; // largest built-in GD font

	for ($i = 0; $i < $len; $i++)
	{
		$tile_w = imagefontwidth($font) + 2;
		$tile_h = imagefontheight($font) + 2;
		$tile = imagecreatetruecolor($tile_w, $tile_h);
		$trans = imagecolorallocate($tile, 0, 0, 0);
		imagecolortransparent($tile, $trans);
		imagefilledrectangle($tile, 0, 0, $tile_w, $tile_h, $trans);

		$ink = imagecolorallocate($tile, random_int(0, 90), random_int(0, 90), random_int(0, 120));
		imagestring($tile, $font, 1, 1, $answer[$i], $ink);

		// Scale up then rotate for a wobbly baseline.
		$scale = 2.8;
		$scale_w = (int) ($tile_w * $scale);
		$scale_h = (int) ($tile_h * $scale);
		$big = imagecreatetruecolor($scale_w, $scale_h);
		imagecolortransparent($big, imagecolorallocate($big, 0, 0, 0));
		imagefilledrectangle($big, 0, 0, $scale_w, $scale_h, imagecolorat($big, 0, 0));
		imagecopyresized($big, $tile, 0, 0, 0, 0, $scale_w, $scale_h, $tile_w, $tile_h);

		$angle = random_int(-22, 22);
		$rot = imagerotate($big, $angle, imagecolorallocatealpha($big, 0, 0, 0, 127));
		imagealphablending($img, true);

		// Centre each glyph within its slot so nothing runs off the edge.
		$dst_x = $margin + $i * $slot + (int) (($slot - imagesx($rot)) / 2);
		$dst_y = (int) (($height - imagesy($rot)) / 2) + random_int(-3, 3);
		imagecopy($img, $rot, $dst_x, $dst_y, 0, 0, imagesx($rot), imagesy($rot));

		// GdImage objects free themselves when they go out of scope (PHP 8.0+);
		// imagedestroy() is deprecated as of PHP 8.5.
		unset($tile, $big, $rot);
	}

	// A few wavy lines over the top for good measure.
	for ($i = 0; $i < 5; $i++)
	{
		$c = imagecolorallocate($img, random_int(120, 190), random_int(120, 190), random_int(120, 190));
		imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $c);
	}

	header('Content-Type: image/png');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

	imagepng($img);
}
