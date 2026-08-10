<?php

/**
 * A throwaway SMTP sink for tests/e2e/mail-test.sh.
 *
 * Speaks just enough SMTP for smtp_mail() to get through (EHLO, AUTH LOGIN,
 * MAIL FROM, RCPT TO, DATA) and writes the DATA payload to disk exactly as it
 * arrived on the wire - dot-stuffing and all - so the test can measure what a
 * real receiving MTA would have had to accept.
 *
 * Touches <ready-file> once it is listening, so the caller can wait for that
 * rather than probing the port - a probe connection would be accepted as the
 * one and only client and the real message would never arrive.
 *
 *   php tests/e2e/mail/sink.php <port> <capture-file> <ready-file>
 */

$port = isset($argv[1]) ? (int) $argv[1] : 2526;
$out  = isset($argv[2]) ? $argv[2] : '/tmp/evebb-mail-capture.txt';
$ready = isset($argv[3]) ? $argv[3] : '';

$server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
if (!$server)
{
	fwrite(STDERR, 'sink: cannot listen on '.$port.': '.$errstr."\n");
	exit(1);
}

if ($ready !== '')
	file_put_contents($ready, "1\n");

$conn = @stream_socket_accept($server, 30);
if (!$conn)
{
	fwrite(STDERR, "sink: nothing connected\n");
	exit(1);
}

fwrite($conn, "220 evebb-test ESMTP\r\n");

$raw = '';
$in_data = false;
$auth_step = 0;

while (($line = fgets($conn)) !== false)
{
	if ($in_data)
	{
		// A lone dot ends the DATA block
		if (rtrim($line, "\r\n") === '.')
		{
			fwrite($conn, "250 queued\r\n");
			$in_data = false;
		}
		else
			$raw .= $line;

		continue;
	}

	if (preg_match('%^(EHLO|HELO)%i', $line))
		fwrite($conn, "250 evebb-test\r\n");
	else if (preg_match('%^AUTH LOGIN%i', $line))
	{
		$auth_step = 1;
		fwrite($conn, "334 VXNlcm5hbWU6\r\n");
	}
	else if ($auth_step === 1)
	{
		$auth_step = 2;
		fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
	}
	else if ($auth_step === 2)
	{
		$auth_step = 3;
		fwrite($conn, "235 authenticated\r\n");
	}
	else if (preg_match('%^DATA%i', $line))
	{
		$in_data = true;
		fwrite($conn, "354 go ahead\r\n");
	}
	else if (preg_match('%^QUIT%i', $line))
	{
		fwrite($conn, "221 bye\r\n");
		break;
	}
	else
		fwrite($conn, "250 ok\r\n");
}

file_put_contents($out, $raw);
