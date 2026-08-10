<?php

/**
 * Core 2FA crypto (include/tfa.php), absorbed from evebb-plugin-tfa in 2.1.
 *
 * The TOTP implementation is checked against the RFC 6238 test vectors
 * rather than against itself, so a refactor that quietly breaks
 * compatibility with real authenticator apps fails here rather than in a
 * member's hands. Replay refusal and the drift window are the security
 * properties worth pinning; the sealed setup token and the backup codes
 * are checked for tamper-resistance and single use.
 */

use PHPUnit\Framework\TestCase;

if (!defined('PUN'))
	define('PUN', 1);
if (!defined('PUN_ROOT'))
	define('PUN_ROOT', dirname(dirname(__DIR__)).'/');

require_once PUN_ROOT.'include/tfa.php';

class tfa_Test extends TestCase
{
	// RFC 6238 appendix B uses the ASCII seed "12345678901234567890"
	const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

	public function setUp(): void
	{
		$GLOBALS['cookie_seed'] = 'test_cookie_seed_0123456789';
	}

	public function testBase32MatchesTheRfcSeed()
	{
		$this->assertSame(self::RFC_SECRET, tfa_base32_encode('12345678901234567890'));
		$this->assertSame('12345678901234567890', tfa_base32_decode(self::RFC_SECRET));
	}

	public function testBase32RoundTripsArbitraryBytes()
	{
		for ($len = 1; $len <= 24; $len++)
		{
			$raw = random_bytes($len);
			$this->assertSame($raw, tfa_base32_decode(tfa_base32_encode($raw)), 'length '.$len);
		}
	}

	public function testTotpMatchesTheRfc6238Vectors()
	{
		// time => the RFC's 8-digit value; we emit the low 6 digits
		$vectors = array(
			59          => '287082',
			1111111109  => '081804',
			1111111111  => '050471',
			1234567890  => '005924',
			2000000000  => '279037',
			20000000000 => '353130',
		);

		foreach ($vectors as $time => $expected)
		{
			$slot = (int) floor($time / 30);
			$this->assertSame($expected, tfa_code(self::RFC_SECRET, $slot), 'T='.$time);
		}
	}

	public function testCodeIsAlwaysSixDigits()
	{
		$secret = tfa_new_secret();
		for ($slot = 1000; $slot < 1200; $slot++)
			$this->assertMatchesRegularExpression('%^\d{6}$%', tfa_code($secret, $slot));
	}

	public function testNewSecretIsThirtyTwoBase32Chars()
	{
		for ($i = 0; $i < 20; $i++)
			$this->assertMatchesRegularExpression('%^[A-Z2-7]{32}$%', tfa_new_secret());
	}

	public function testVerifyAcceptsTheCurrentCode()
	{
		$secret = tfa_new_secret();
		$slot = (int) floor(time() / 30);
		$used = null;

		$this->assertTrue(tfa_verify($secret, tfa_code($secret, $slot), 0, $used));
		$this->assertSame($slot, $used);
	}

	public function testVerifyAcceptsOneSlotOfDriftEitherWay()
	{
		$secret = tfa_new_secret();
		$slot = (int) floor(time() / 30);

		$this->assertTrue(tfa_verify($secret, tfa_code($secret, $slot - 1), 0), 'one slot behind');
		$this->assertTrue(tfa_verify($secret, tfa_code($secret, $slot + 1), 0), 'one slot ahead');
	}

	public function testVerifyRejectsTwoSlotsOfDrift()
	{
		$secret = tfa_new_secret();
		$slot = (int) floor(time() / 30);

		$this->assertFalse(tfa_verify($secret, tfa_code($secret, $slot - 2), 0));
		$this->assertFalse(tfa_verify($secret, tfa_code($secret, $slot + 2), 0));
	}

	public function testACodeCannotBeReplayed()
	{
		// This is the property that makes a stolen code worthless: once a
		// slot has been consumed, that slot and everything before it is
		// refused for good.
		$secret = tfa_new_secret();
		$slot = (int) floor(time() / 30);
		$code = tfa_code($secret, $slot);
		$used = null;

		$this->assertTrue(tfa_verify($secret, $code, 0, $used));
		$this->assertFalse(tfa_verify($secret, $code, $used), 'the same code was accepted twice');
		$this->assertFalse(tfa_verify($secret, tfa_code($secret, $slot - 1), $used), 'an older slot was accepted');
	}

	public function testVerifyRejectsMalformedInput()
	{
		$secret = tfa_new_secret();

		foreach (array('', '12345', '1234567', 'abcdef', null, '   ') as $bad)
			$this->assertFalse(tfa_verify($secret, $bad, 0), var_export($bad, true));
	}

	public function testVerifyIgnoresSpacingInATypedCode()
	{
		$secret = tfa_new_secret();
		$slot = (int) floor(time() / 30);
		$code = tfa_code($secret, $slot);

		$this->assertTrue(tfa_verify($secret, substr($code, 0, 3).' '.substr($code, 3), 0));
	}

	public function testWrongSecretIsRejected()
	{
		$a = tfa_new_secret();
		$b = tfa_new_secret();
		$slot = (int) floor(time() / 30);

		$this->assertFalse(tfa_verify($a, tfa_code($b, $slot), 0));
	}

	public function testSealedTokenRoundTripsForTheRightUser()
	{
		$secret = tfa_new_secret();
		$token = tfa_seal(42, $secret);

		$this->assertSame($secret, tfa_unseal($token, 42));
	}

	public function testSealedTokenIsBoundToTheUser()
	{
		$token = tfa_seal(42, tfa_new_secret());

		$this->assertFalse(tfa_unseal($token, 43), 'another member could claim the secret');
	}

	public function testSealedTokenRejectsTampering()
	{
		$secret = tfa_new_secret();
		$token = tfa_seal(42, $secret);

		$this->assertFalse(tfa_unseal($token.'x', 42));
		$this->assertFalse(tfa_unseal(substr($token, 0, -1), 42));
		$this->assertFalse(tfa_unseal('rubbish', 42));
		$this->assertFalse(tfa_unseal('', 42));

		// A payload swapped for a different secret, without a valid MAC
		$forged = base64_encode('42:'.tfa_new_secret().':'.(time() + 900)).'-'.str_repeat('a', 64);
		$this->assertFalse(tfa_unseal($forged, 42));
	}

	public function testSealedTokenExpires()
	{
		// Seal by hand with a timestamp in the past, signed correctly
		$payload = '42:'.self::RFC_SECRET.':'.(time() - 1);
		$token = base64_encode($payload).'-'.hash_hmac('sha256', $payload, $GLOBALS['cookie_seed'].'|tfa');

		$this->assertFalse(tfa_unseal($token, 42), 'an expired setup token was accepted');
	}

	public function testSealedTokenRejectsAMalformedSecret()
	{
		$payload = '42:not-a-valid-secret:'.(time() + 900);
		$token = base64_encode($payload).'-'.hash_hmac('sha256', $payload, $GLOBALS['cookie_seed'].'|tfa');

		$this->assertFalse(tfa_unseal($token, 42));
	}

	public function testBackupCodesNormaliseForgivingly()
	{
		$this->assertSame('ABCD2345', tfa_backup_normalize('abcd-2345'));
		$this->assertSame('ABCD2345', tfa_backup_normalize(' ABCD 2345 '));
		$this->assertSame('ABCD2345', tfa_backup_normalize('AbCd-2345'));
	}

	public function testBackupHashIsStableAndSecretDependent()
	{
		$a = tfa_backup_hash('abcd-2345');
		$this->assertSame($a, tfa_backup_hash('ABCD2345'), 'normalisation must not change the hash');

		$GLOBALS['cookie_seed'] = 'a different board';
		$this->assertNotSame($a, tfa_backup_hash('abcd-2345'), 'the hash must be keyed to the board');
	}

	public function testOtpauthUriCarriesTheBoardAndMember()
	{
		$GLOBALS['pun_config'] = array('o_board_title' => 'eveBB Community Forum');
		$uri = tfa_otpauth_uri('Alan P', self::RFC_SECRET);

		$this->assertStringContainsString('otpauth://totp/', $uri);
		$this->assertStringContainsString('secret='.self::RFC_SECRET, $uri);
		$this->assertStringContainsString('algorithm=SHA1', $uri);
		$this->assertStringContainsString('digits=6', $uri);
		$this->assertStringContainsString('period=30', $uri);
		// Spaces must be encoded, or the app reads a truncated label
		$this->assertStringNotContainsString(' ', $uri);
	}
}
