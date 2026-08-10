<?php

/**
 * Two-factor authentication (TOTP).
 *
 * RFC 4648 base32, RFC 6238 TOTP (SHA-1, 6 digits, 30 s, +/-1 slot of
 * drift, replay-proof via a consumed last slot), sealed setup tokens
 * (HMAC keyed on the board's cookie_seed) and single-use backup codes
 * stored as HMACs and consumed atomically. Everything compares with
 * hash_equals.
 *
 * Absorbed into core in 2.1 from the official evebb-plugin-tfa, whose
 * crypto this is - validated against the RFC 6238 test vectors. The
 * tfa_users and tfa_backup tables keep the plugin's names and shapes so
 * a board upgrading from the plugin migrates in place, with its members
 * still enrolled.
 *
 * The pure functions take no globals, so tests can require this file
 * standalone to compute expected codes.
 *
 * Copyright (C) 2026 Alan Paynter
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

// Lets the retired plugin (and any addon) detect that core does this now
if (!defined('FORUM_HAS_TFA'))
	define('FORUM_HAS_TFA', 1);


// ---------------------------------------------------------------------
// Pure crypto helpers (no globals - requireable standalone by tests)
// ---------------------------------------------------------------------

//
// RFC 4648 base32 (A-Z, 2-7), no padding
//
function tfa_base32_encode($bin)
{
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$out = '';
	$buffer = 0;
	$bits = 0;

	for ($i = 0, $len = strlen($bin); $i < $len; $i++)
	{
		$buffer = ($buffer << 8) | ord($bin[$i]);
		$bits += 8;
		while ($bits >= 5)
		{
			$bits -= 5;
			$out .= $alphabet[($buffer >> $bits) & 31];
		}
	}
	if ($bits > 0)
		$out .= $alphabet[($buffer << (5 - $bits)) & 31];

	return $out;
}


function tfa_base32_decode($s)
{
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$s = strtoupper($s);
	$out = '';
	$buffer = 0;
	$bits = 0;

	for ($i = 0, $len = strlen($s); $i < $len; $i++)
	{
		$v = strpos($alphabet, $s[$i]);
		if ($v === false)
			continue;
		$buffer = ($buffer << 5) | $v;
		$bits += 5;
		if ($bits >= 8)
		{
			$bits -= 8;
			$out .= chr(($buffer >> $bits) & 255);
		}
	}

	return $out;
}


//
// A fresh 160-bit secret (32 base32 characters)
//
function tfa_new_secret()
{
	return tfa_base32_encode(random_bytes(20));
}


//
// The 6-digit TOTP code for one 30-second time slot
//
function tfa_code($secret, $slot)
{
	$key = tfa_base32_decode($secret);
	$msg = pack('N2', 0, $slot);
	$hash = hash_hmac('sha1', $msg, $key, true);
	$offset = ord($hash[19]) & 0xf;
	$value = ((ord($hash[$offset]) & 0x7f) << 24)
		| (ord($hash[$offset + 1]) << 16)
		| (ord($hash[$offset + 2]) << 8)
		| ord($hash[$offset + 3]);

	return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}


//
// Verify a 6-digit code against the current slot +/- 1 (90 s of clock
// drift). Slots at or before $last_slot are refused - a code can never be
// replayed. On success $used_slot carries the accepted slot, which the
// caller must persist as the new last_slot.
//
function tfa_verify($secret, $code, $last_slot, &$used_slot = null)
{
	$code = preg_replace('%\D%', '', (string) $code);
	if (strlen($code) != 6)
		return false;

	$now_slot = (int) floor(time() / 30);

	foreach (array(0, -1, 1) as $delta)
	{
		$slot = $now_slot + $delta;
		if ($slot <= (int) $last_slot)
			continue;
		if (hash_equals(tfa_code($secret, $slot), $code))
		{
			$used_slot = $slot;
			return true;
		}
	}

	return false;
}


//
// The otpauth:// URI an authenticator app expects, rendered as a QR code
// on the setup page. The issuer is the board title so a member with
// several boards can tell them apart in the app.
//
function tfa_otpauth_uri($username, $secret)
{
	global $pun_config;

	$issuer = $pun_config['o_board_title'];
	$label = rawurlencode($issuer).':'.rawurlencode($username);

	return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
}


//
// Sealed setup token: carries the not-yet-confirmed secret between the
// setup page and the confirm POST without any server-side session. Bound
// to the user id, HMAC'd with the board's cookie_seed, 15-minute TTL.
//
function tfa_seal($user_id, $secret)
{
	global $cookie_seed;

	$payload = (int) $user_id.':'.$secret.':'.(time() + 900);
	$mac = hash_hmac('sha256', $payload, $cookie_seed.'|tfa');

	return base64_encode($payload).'-'.$mac;
}


function tfa_unseal($token, $user_id)
{
	global $cookie_seed;

	$pos = strrpos((string) $token, '-');
	if ($pos === false)
		return false;

	$payload = base64_decode(substr($token, 0, $pos), true);
	$mac = substr($token, $pos + 1);
	if ($payload === false || !is_string($mac))
		return false;

	if (!hash_equals(hash_hmac('sha256', $payload, $cookie_seed.'|tfa'), $mac))
		return false;

	$parts = explode(':', $payload);
	if (count($parts) != 3)
		return false;

	list($uid, $secret, $expires) = $parts;
	if ((int) $uid != (int) $user_id || (int) $expires < time())
		return false;

	if (!preg_match('%^[A-Z2-7]{32}$%', $secret))
		return false;

	return $secret;
}


// ---------------------------------------------------------------------
// Per-user state
// ---------------------------------------------------------------------

//
// The member's 2FA row, or null when 2FA is off for them
//
function tfa_row($user_id)
{
	global $db;

	$result = $db->query('SELECT user_id, secret, last_slot, enabled_at FROM '.$db->prefix.'tfa_users WHERE user_id='.(int) $user_id) or error('Unable to fetch 2FA state', __FILE__, __LINE__, $db->error());

	return $db->has_rows($result) ? $db->fetch_assoc($result) : null;
}


//
// Turn 2FA on for a member once they have proven a code from the app
//
function tfa_enable($user_id, $secret, $used_slot)
{
	global $db;

	$user_id = (int) $user_id;

	$db->query('DELETE FROM '.$db->prefix.'tfa_users WHERE user_id='.$user_id) or error('Unable to enable 2FA', __FILE__, __LINE__, $db->error());
	$db->query('INSERT INTO '.$db->prefix.'tfa_users (user_id, secret, last_slot, enabled_at) VALUES('.$user_id.', \''.$db->escape($secret).'\', '.(int) $used_slot.', '.time().')') or error('Unable to enable 2FA', __FILE__, __LINE__, $db->error());
}


//
// Normalise a typed backup code (case/spacing/dash insensitive)
//
function tfa_backup_normalize($code)
{
	return strtoupper(preg_replace('%[^0-9a-zA-Z]%', '', (string) $code));
}


function tfa_backup_hash($code)
{
	global $cookie_seed;

	return hash_hmac('sha256', tfa_backup_normalize($code), $cookie_seed.'|tfa_backup');
}


//
// Replace the member's backup codes with 8 fresh single-use codes and
// return the plain codes (shown exactly once)
//
function tfa_gen_backup($user_id)
{
	global $db;

	$user_id = (int) $user_id;
	$alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';   // no 0/O, 1/I/L
	$codes = array();

	$db->query('DELETE FROM '.$db->prefix.'tfa_backup WHERE user_id='.$user_id) or error('Unable to reset backup codes', __FILE__, __LINE__, $db->error());

	for ($i = 0; $i < 8; $i++)
	{
		$code = '';
		for ($j = 0; $j < 8; $j++)
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		$code = substr($code, 0, 4).'-'.substr($code, 4);
		$codes[] = $code;

		$db->query('INSERT INTO '.$db->prefix.'tfa_backup (user_id, code_hash) VALUES('.$user_id.', \''.$db->escape(tfa_backup_hash($code)).'\')') or error('Unable to store backup code', __FILE__, __LINE__, $db->error());
	}

	return $codes;
}


//
// How many backup codes the member has left
//
function tfa_backup_count($user_id)
{
	global $db;

	$result = $db->query('SELECT COUNT(user_id) FROM '.$db->prefix.'tfa_backup WHERE user_id='.(int) $user_id) or error('Unable to count backup codes', __FILE__, __LINE__, $db->error());

	return (int) $db->result($result);
}


//
// Consume a backup code. The DELETE is the check - affected_rows()==1
// means the code existed and can never be used again (atomic even when
// two requests race the same code).
//
function tfa_use_backup($user_id, $code)
{
	global $db;

	$normalized = tfa_backup_normalize($code);
	if (strlen($normalized) != 8)
		return false;

	$db->query('DELETE FROM '.$db->prefix.'tfa_backup WHERE user_id='.(int) $user_id.' AND code_hash=\''.$db->escape(tfa_backup_hash($code)).'\'') or error('Unable to check backup code', __FILE__, __LINE__, $db->error());

	return $db->affected_rows() == 1;
}


//
// Accept either a 6-digit TOTP code (updating last_slot) or a backup
// code. The single entry point used by login and by the profile page.
//
function tfa_check_code($row, $code)
{
	global $db;

	$used_slot = null;
	if (tfa_verify($row['secret'], $code, (int) $row['last_slot'], $used_slot))
	{
		$db->query('UPDATE '.$db->prefix.'tfa_users SET last_slot='.(int) $used_slot.' WHERE user_id='.(int) $row['user_id']) or error('Unable to update 2FA state', __FILE__, __LINE__, $db->error());
		return true;
	}

	return tfa_use_backup($row['user_id'], $code);
}


//
// Switch 2FA off for a member (their own request, or a staff reset)
//
function tfa_disable($user_id)
{
	global $db;

	$db->query('DELETE FROM '.$db->prefix.'tfa_backup WHERE user_id='.(int) $user_id) or error('Unable to disable 2FA', __FILE__, __LINE__, $db->error());
	$db->query('DELETE FROM '.$db->prefix.'tfa_users WHERE user_id='.(int) $user_id) or error('Unable to disable 2FA', __FILE__, __LINE__, $db->error());
}
