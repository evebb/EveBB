<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

/**
 * Characterization tests: pin down the current behavior of the utf8
 * library (as loaded by utf8.php — the mbstring implementation on any
 * normal host) and the pure helpers in include/functions.php, so later
 * refactoring (PDO layer, namespaces) can prove it changed nothing.
 */
class Utf8AndHelpersTest extends TestCase
{
	// ---- utf8 API ----

	public function testStrlenCountsCharactersNotBytes()
	{
		$this->assertSame(5, utf8_strlen('héllo'));
		$this->assertSame(3, utf8_strlen('日本語'));
		$this->assertSame(0, utf8_strlen(''));
		$this->assertSame(17, utf8_strlen('héllo wörld — 日本語'));
	}

	public function testSubstrOperatesOnCharacters()
	{
		$this->assertSame('éll', utf8_substr('héllo', 1, 3));
		$this->assertSame('本語', utf8_substr('日本語', 1, 2));
		$this->assertSame('lo', utf8_substr('héllo', 3));
	}

	public function testStrposOperatesOnCharacters()
	{
		$this->assertSame(6, utf8_strpos('héllo wörld', 'wörld'));
		$this->assertSame(false, utf8_strpos('héllo', 'x'));
		$this->assertSame(2, utf8_strrpos('日本日', '日'));
	}

	public function testCaseConversionHandlesAccents()
	{
		$this->assertSame('HÉLLO', utf8_strtoupper('héllo'));
		$this->assertSame('héllo', utf8_strtolower('HÉLLO'));
	}

	public function testTrimHandlesUnicodeAndCharlists()
	{
		$this->assertSame('abc', utf8_trim('  abc  '));
		$this->assertSame('b', utf8_trim('aba', 'a'));
	}

	public function testStrPadPadsToCharacterLength()
	{
		$this->assertSame('héllo---', utf8_str_pad('héllo', 8, '-'));
		$this->assertSame('héllo', utf8_str_pad('héllo', 3, '-'));
	}

	public function testStrPadRejectsUnknownPadType()
	{
		// Phase 1 changed trigger_error(E_USER_ERROR) to an exception —
		// pin the new behavior
		try
		{
			utf8_str_pad('x', 5, '-', 99);
			$this->fail('Expected InvalidArgumentException');
		}
		catch (InvalidArgumentException $e)
		{
			$this->assertStringContainsString('Unknown padding type', $e->getMessage());
		}
	}

	public function testBadStripRemovesInvalidSequences()
	{
		$this->assertSame('ab', utf8_bad_strip("a\xFFb"));
		$this->assertSame('héllo', utf8_bad_strip('héllo'));
	}

	// The mbstring wrappers were made null-tolerant in Phase 1 (PHP 7
	// treated null as '') — pin that so it survives future refactors
	public function testWrappersTolerateNull()
	{
		$this->assertSame(0, utf8_strlen(null));
		$this->assertSame('', utf8_strtolower(null));
	}

	// ---- native implementation equivalence (run in a subprocess,
	// because native and mbstring define the same function names) ----

	public function testNativeImplementationMatchesMbstring()
	{
		$fixtures = array('', 'hello', 'héllo wörld', '日本語のテスト', 'mixed é 中 z');

		$probe = <<<'EOT'
<?php
define('PUN', 1);
define('UTF8_USE_NATIVE', true);
require $argv[1].'/include/utf8/utf8.php';
$out = array();
foreach (json_decode($argv[2]) as $s) {
	$out[] = array(
		'strlen' => utf8_strlen($s),
		'lower'  => utf8_strtolower($s),
		'upper'  => utf8_strtoupper($s),
		'substr' => utf8_substr($s, 1, 3),
		'strpos' => utf8_strpos($s, 'l'),
	);
}
echo json_encode($out);
EOT;
		$probeFile = sys_get_temp_dir().'/utf8_native_probe.php';
		file_put_contents($probeFile, $probe);

		$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($probeFile).' '.escapeshellarg(PUN_ROOT).' '.escapeshellarg(json_encode($fixtures));
		$native = json_decode(shell_exec($cmd), true);
		$this->assertIsArray($native, 'native probe must return JSON');

		foreach ($fixtures as $i => $s)
		{
			$this->assertSame(utf8_strlen($s), $native[$i]['strlen'], "strlen('$s')");

			// Known upstream divergence: the native case functions return
			// false for the empty string where mbstring returns ''
			if ($s === '')
			{
				$this->assertFalse($native[$i]['lower'], 'native lower("")');
				$this->assertFalse($native[$i]['upper'], 'native upper("")');
				continue;
			}

			$this->assertSame(utf8_strtolower($s), $native[$i]['lower'], "strtolower('$s')");
			$this->assertSame(utf8_strtoupper($s), $native[$i]['upper'], "strtoupper('$s')");
			$this->assertSame(utf8_substr($s, 1, 3), $native[$i]['substr'], "substr('$s')");
			$this->assertSame(utf8_strpos($s, 'l'), $native[$i]['strpos'], "strpos('$s')");
		}
	}

	// ---- functions.php helpers ----

	public function testPunHtmlspecialcharsEscapesAndToleratesNull()
	{
		$this->assertSame('&lt;b&gt;&quot;&#039;&amp;', pun_htmlspecialchars('<b>"\'&'));
		$this->assertSame('', pun_htmlspecialchars(null));
		$this->assertSame('a&amp;b', pun_htmlspecialchars_decode('a&amp;amp;b'));
	}

	public function testPunTrimReturnsEmptyStringForNonStrings()
	{
		$this->assertSame('abc', pun_trim(' abc '));
		$this->assertSame('', pun_trim(null));
		$this->assertSame('', pun_trim(array('x')));
	}

	public function testPunLinebreaksNormalizesLineEndings()
	{
		$this->assertSame("a\nb\nc", pun_linebreaks("a\r\nb\rc"));
	}

	public function testForumHmacIsStableSha1Hmac()
	{
		$this->assertSame(hash_hmac('sha1', 'data', 'key'), forum_hmac('data', 'key'));
	}

	public function testRandomKeyRespectsLengthAndReadability()
	{
		$this->assertSame(12, strlen(random_key(12)));
		$this->assertMatchesRegularExpression('%^[a-zA-Z0-9]{16}$%', random_key(16, true));
	}

	public function testRandomPassLength()
	{
		$this->assertSame(10, strlen(random_pass(10)));
	}

	public function testFluxPasswordRoundTrip()
	{
		$GLOBALS['password_hash_cost'] = 10;
		$hash = flux_password_hash('secret');
		$this->assertTrue(flux_password_verify('secret', $hash));
		$this->assertFalse(flux_password_verify('wrong', $hash));
		$this->assertFalse(flux_password_needs_rehash($hash));
	}

	public function testCsrfTokenIsStablePerUserAndSeed()
	{
		$GLOBALS['pun_user'] += array('id' => 2, 'password' => 'hash');
		$GLOBALS['cookie_seed'] = 'seedvalue';
		$t1 = pun_csrf_token();
		$this->assertSame($t1, pun_csrf_token());
		$this->assertSame(40, strlen($t1));
	}
}
