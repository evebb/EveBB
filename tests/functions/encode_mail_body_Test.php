<?php

/**
 * encode_mail_body() has one job: make sure nothing the board sends can be
 * rejected in transport for having a line that is too long, while leaving the
 * message the recipient reads byte-for-byte identical to what was written.
 *
 * The bug this guards against is not hypothetical - the eveBB Hosted portal
 * hit exactly it in July 2026: Outlook's Exim refused a message with "lines
 * too long for transport (received 4604, limit 2048)". On a board the same
 * thing arrives via new_reply_full.tpl / new_topic_full.tpl, which embed the
 * post itself: bbcode2email() wordwraps at 72, but wordwrap() does not break
 * a single long "word", so one pasted URL is enough.
 */

use PHPUnit\Framework\TestCase;

if (!defined('PUN'))
	define('PUN', 1);
if (!defined('PUN_ROOT'))
	define('PUN_ROOT', dirname(dirname(__DIR__)).'/');

require_once PUN_ROOT.'include/email.php';

class encode_mail_body_Test extends TestCase
{
	// RFC 2045 caps a quoted-printable line at 76 octets before the CRLF
	const MAX_LINE = 76;

	private function lines($encoded, $EOL = "\r\n")
	{
		return explode($EOL, $encoded);
	}

	private function assertNoLineTooLong($encoded, $EOL = "\r\n")
	{
		foreach ($this->lines($encoded, $EOL) as $i => $line)
			$this->assertLessThanOrEqual(self::MAX_LINE, strlen($line),
				'line '.($i + 1).' is '.strlen($line).' octets long');
	}

	public function testUnbrokenUrlIsSoftWrapped()
	{
		// The realistic shape of the bug: one enormous "word" with no spaces
		$post = 'See '.str_repeat('https://example.test/a?ref=', 120)."\r\n";

		$encoded = encode_mail_body($post);

		$this->assertGreaterThan(2048, strlen($post), 'fixture is not long enough to be interesting');
		$this->assertNoLineTooLong($encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testBase64BlobIsSoftWrapped()
	{
		$post = "Here is the dump:\r\n".base64_encode(str_repeat('eveBB', 900))."\r\nThanks.\r\n";

		$encoded = encode_mail_body($post);

		$this->assertNoLineTooLong($encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testOrdinaryNotificationRoundTripsAndStaysReadable()
	{
		$post = "Thank you for registering in the forums at https://evebb.net.\r\n\r\n".
			"Username: someone\r\nPassword: hunter2\r\n";

		$encoded = encode_mail_body($post);

		// A short plain-text mail should come out essentially untouched, so a
		// human reading the raw source still sees a normal email
		$this->assertStringContainsString('Username: someone', $encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testUtf8SurvivesIntact()
	{
		$post = "Subscription costs \xC2\xA32.99 a month.\r\nCaf\xC3\xA9 \xE2\x80\x94 na\xC3\xAFve r\xC3\xB4le\r\n";

		$encoded = encode_mail_body($post);

		// The high bytes must be encoded, not passed through raw
		$this->assertStringNotContainsString("\xC2\xA3", $encoded);
		$this->assertNoLineTooLong($encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testUtf8IsNotSplitAcrossASoftBreak()
	{
		// A multi-byte character landing exactly on the wrap boundary must not
		// be cut in half, or the recipient sees a replacement character
		for ($pad = 60; $pad <= 80; $pad++)
		{
			$post = str_repeat('a', $pad)."\xC2\xA3".str_repeat('b', 90)."\r\n";
			$encoded = encode_mail_body($post);

			$this->assertNoLineTooLong($encoded);
			$this->assertSame($post, quoted_printable_decode($encoded), 'padding '.$pad);
		}
	}

	public function testBareLfAndCrAreNormalisedToCrlf()
	{
		$encoded = encode_mail_body("one\ntwo\rthree\r\nfour");

		$this->assertSame("one\r\ntwo\r\nthree\r\nfour", quoted_printable_decode($encoded));
	}

	public function testLocalMailerGetsTheSystemLineEnding()
	{
		$post = "one\ntwo\n".str_repeat('x', 400)."\n";

		$encoded = encode_mail_body($post, "\n");

		$this->assertStringNotContainsString("\r", $encoded);
		$this->assertNoLineTooLong($encoded, "\n");

		// Once the MTA canonicalises the line endings it must still decode
		$this->assertSame("one\r\ntwo\r\n".str_repeat('x', 400)."\r\n",
			quoted_printable_decode(str_replace("\n", "\r\n", $encoded)));
	}

	public function testNullBytesAreStripped()
	{
		$encoded = encode_mail_body("clean\0text\r\n");

		$this->assertSame("cleantext\r\n", quoted_printable_decode($encoded));
	}

	public function testTrailingWhitespaceIsPreserved()
	{
		// Trailing spaces must be encoded (=20) or the receiving MTA is free to
		// strip them, which would change the message
		$post = "signature line   \r\nnext\r\n";

		$encoded = encode_mail_body($post);

		$this->assertStringContainsString('=20', $encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testEqualsSignIsEscaped()
	{
		$post = "1 + 1 = 2\r\n";

		$encoded = encode_mail_body($post);

		$this->assertStringContainsString('=3D', $encoded);
		$this->assertSame($post, quoted_printable_decode($encoded));
	}

	public function testEmptyMessageIsHarmless()
	{
		$this->assertSame('', encode_mail_body(''));
	}

	public function testRealTemplatesEncodeCleanly()
	{
		$dir = PUN_ROOT.'lang/English/mail_templates/';
		$templates = glob($dir.'*.tpl');

		$this->assertNotEmpty($templates, 'no mail templates found');

		foreach ($templates as $tpl)
		{
			$body = file_get_contents($tpl);
			$encoded = encode_mail_body($body);

			$this->assertNoLineTooLong($encoded);
			$this->assertSame(str_replace(array("\r\n", "\r", "\n"), "\r\n", $body),
				quoted_printable_decode($encoded), basename($tpl));
		}
	}

	public function testPunMailDeclaresTheMatchingTransferEncoding()
	{
		// Encoding the body without advertising it in the header would leave
		// recipients reading =3D and =C2=A3 in plain text - the two halves have
		// to stay together
		$source = file_get_contents(PUN_ROOT.'include/email.php');

		$this->assertStringContainsString('Content-transfer-encoding: quoted-printable', $source);
		$this->assertStringNotContainsString('Content-transfer-encoding: 8bit', $source);
	}
}
