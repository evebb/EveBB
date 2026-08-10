<?php

/**
 * Sends one deliberately hostile notification through the real pun_mail() at
 * the SMTP sink, for tests/e2e/mail-test.sh.
 *
 * The message is a real new_reply_full.tpl with a post pasted into it that
 * would have been fatal before quoted-printable encoding: a single unbroken
 * 5000-character URL (bbcode2email()'s wordwrap does not break long "words"),
 * plus UTF-8 that has to survive the round trip.
 *
 *   php tests/e2e/mail/send.php <port> <expected-post-file>
 */

$port = isset($argv[1]) ? (int) $argv[1] : 2526;
$expected_file = isset($argv[2]) ? $argv[2] : '/tmp/evebb-mail-post.txt';

define('PUN', 1);
define('PUN_ROOT', dirname(dirname(dirname(__DIR__))).'/');
define('FORUM_EOL', "\r\n");

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require PUN_ROOT.'include/utf8/utf8.php';
require PUN_ROOT.'include/functions.php';
require PUN_ROOT.'include/email.php';

$GLOBALS['pun_config'] = array(
	'o_smtp_host'		=> '127.0.0.1:'.$port,
	'o_smtp_ssl'		=> '0',
	'o_smtp_user'		=> 'tester',
	'o_smtp_pass'		=> 'tester',
	'o_webmaster_email'	=> 'webmaster@evebb.test',
	'o_board_title'		=> 'eveBB',
	'o_base_url'		=> 'https://evebb.test',
);
$GLOBALS['lang_common'] = array('Mailer' => '%s Mailer');

// Build the notification the way post.php does
$tpl = trim(file_get_contents(PUN_ROOT.'lang/English/mail_templates/new_reply_full.tpl'));
$first_crlf = strpos($tpl, "\n");
$subject = trim(substr($tpl, 8, $first_crlf - 8));
$message = trim(substr($tpl, $first_crlf));

$post = 'Found it here: '.str_repeat('https://example.test/really/long/path?ref=', 120)."\n".
	"Price was \xC2\xA32.99 \xE2\x80\x94 caf\xC3\xA9 na\xC3\xAFve r\xC3\xB4le.\n".
	"Trailing spaces matter too   \n".
	".a line starting with a dot\n".
	'1 + 1 = 2';

$subject = str_replace('<topic_subject>', 'Long links', $subject);
$message = str_replace(
	array('<topic_subject>', '<replier>', '<post_url>', '<message>', '<unsubscribe_url>', '<board_mailer>'),
	array('Long links', 'someone', 'https://evebb.test/viewtopic.php?pid=1', $post, 'https://evebb.test/misc.php?email=1', 'eveBB'),
	$message);

file_put_contents($expected_file, $post);

pun_mail('subscriber@example.test', $subject, $message);
