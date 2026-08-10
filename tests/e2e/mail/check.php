<?php

/**
 * Inspects what the SMTP sink captured, for tests/e2e/mail-test.sh.
 * Prints one "ok:"/"FAIL:" line per assertion and exits non-zero on failure.
 *
 *   php tests/e2e/mail/check.php <capture-file> <expected-post-file>
 */

// RFC 5321 puts the hard cap at 998 octets; real receivers enforce their own
// (the Exim that bounced eveBB Hosted's mail in July 2026 rejected at 2048).
// Quoted-printable wraps at 76, +1 if the receiver's dot-stuffing kicks in.
define('LINE_LIMIT', 78);

$capture = isset($argv[1]) ? $argv[1] : '/tmp/evebb-mail-capture.txt';
$expected_file = isset($argv[2]) ? $argv[2] : '/tmp/evebb-mail-post.txt';

$pass = 0;
$fail = 0;

function ok($label)
{
	global $pass;
	$pass++;
	echo '  ok: '.$label."\n";
}

function nope($label, $detail = '')
{
	global $fail;
	$fail++;
	echo '  FAIL: '.$label.($detail !== '' ? ' ('.$detail.')' : '')."\n";
}

function check($cond, $label, $detail = '')
{
	$cond ? ok($label) : nope($label, $detail);
}

if (!is_file($capture) || filesize($capture) === 0)
{
	nope('the sink captured a message', 'nothing arrived at the SMTP sink');
	echo "== mail transport: 0 passed, 1 failed ==\n";
	exit(1);
}

$raw = file_get_contents($capture);
$post = file_get_contents($expected_file);

list($head, $body) = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

// --- headers ---------------------------------------------------------------
check(stripos($head, 'Content-transfer-encoding: quoted-printable') !== false,
	'the body is advertised as quoted-printable');
check(stripos($head, 'Content-transfer-encoding: 8bit') === false,
	'8bit is no longer advertised');
check(stripos($head, 'Content-type: text/plain; charset=utf-8') !== false,
	'charset is still declared as UTF-8');

// --- line lengths ----------------------------------------------------------
$longest = 0;
foreach (explode("\r\n", $raw) as $line)
	$longest = max($longest, strlen($line));

check($longest <= LINE_LIMIT, 'no line on the wire exceeds '.LINE_LIMIT.' octets',
	'longest was '.$longest);

$longest_source = 0;
foreach (explode("\n", $post) as $line)
	$longest_source = max($longest_source, strlen($line));

check($longest_source > 2048, 'the fixture really is hostile',
	'longest source line only '.$longest_source.' octets');

// --- the message itself ----------------------------------------------------
// A receiving MTA strips the leading dot from any dot-stuffed line
$unstuffed = array();
foreach (explode("\r\n", $body) as $line)
	$unstuffed[] = (isset($line[0]) && $line[0] === '.') ? substr($line, 1) : $line;

$decoded = quoted_printable_decode(implode("\r\n", $unstuffed));

check(strpos($decoded, str_replace("\n", "\r\n", $post)) !== false,
	'the post arrives byte-identical');
check(strpos($decoded, "\xC2\xA32.99") !== false, 'the pound sign survives');
check(strpos($decoded, "\xE2\x80\x94") !== false, 'the em dash survives');
check(strpos($decoded, "Trailing spaces matter too   \r\n") !== false,
	'trailing whitespace is preserved');
check(strpos($decoded, "\r\n.a line starting with a dot") !== false,
	'a line starting with a dot survives dot-stuffing');
check(strpos($decoded, '1 + 1 = 2') !== false, 'an equals sign survives');
check(strpos($decoded, 'You can unsubscribe by going to') !== false,
	'the template around the post is intact');

echo '== mail transport: '.$pass.' passed, '.$fail." failed ==\n";
exit($fail === 0 ? 0 : 1);
