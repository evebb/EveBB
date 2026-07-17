<?php

/**
 * Minimal PHPUnit-compatible TestCase for environments where PHPUnit
 * cannot be installed (e.g. no access to packagist). Tests written
 * against this API run unchanged under real PHPUnit in CI.
 *
 * Only loaded when the real PHPUnit is absent — see tests/lite/run.php.
 */

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception
{
}

class SkippedTest extends \Exception
{
}

class TestCase
{
	public function setUp(): void
	{
	}

	public function tearDown(): void
	{
	}

	public static function setUpBeforeClass(): void
	{
	}

	public static function tearDownAfterClass(): void
	{
	}

	private static function toStr($v)
	{
		if (is_string($v))
			return "'".(strlen($v) > 200 ? substr($v, 0, 200).'…' : $v)."'";
		return var_export($v, true);
	}

	private function failWith($message, $default)
	{
		throw new AssertionFailedError($message !== '' ? $message : $default);
	}

	public function fail(string $message = ''): void
	{
		$this->failWith($message, 'Failed');
	}

	public function markTestSkipped(string $message = ''): void
	{
		throw new SkippedTest($message);
	}

	public function assertTrue($actual, string $message = ''): void
	{
		if ($actual !== true)
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is true');
	}

	public function assertFalse($actual, string $message = ''): void
	{
		if ($actual !== false)
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is false');
	}

	public function assertNull($actual, string $message = ''): void
	{
		if ($actual !== null)
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is null');
	}

	public function assertNotNull($actual, string $message = ''): void
	{
		if ($actual === null)
			$this->failWith($message, 'Failed asserting that value is not null');
	}

	public function assertSame($expected, $actual, string $message = ''): void
	{
		if ($expected !== $actual)
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is identical to '.self::toStr($expected));
	}

	public function assertNotSame($expected, $actual, string $message = ''): void
	{
		if ($expected === $actual)
			$this->failWith($message, 'Failed asserting that two values are not identical');
	}

	public function assertEquals($expected, $actual, string $message = ''): void
	{
		if ($expected != $actual)
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' equals '.self::toStr($expected));
	}

	public function assertNotEquals($expected, $actual, string $message = ''): void
	{
		if ($expected == $actual)
			$this->failWith($message, 'Failed asserting that two values are not equal');
	}

	public function assertGreaterThan($expected, $actual, string $message = ''): void
	{
		if (!($actual > $expected))
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is greater than '.self::toStr($expected));
	}

	public function assertCount(int $expected, $haystack, string $message = ''): void
	{
		$count = is_array($haystack) || $haystack instanceof \Countable ? count($haystack) : -1;
		if ($count !== $expected)
			$this->failWith($message, 'Failed asserting that count '.$count.' matches expected '.$expected);
	}

	public function assertIsArray($actual, string $message = ''): void
	{
		if (!is_array($actual))
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is an array');
	}

	public function assertIsString($actual, string $message = ''): void
	{
		if (!is_string($actual))
			$this->failWith($message, 'Failed asserting that value is a string');
	}

	public function assertIsInt($actual, string $message = ''): void
	{
		if (!is_int($actual))
			$this->failWith($message, 'Failed asserting that '.self::toStr($actual).' is an int');
	}

	public function assertArrayHasKey($key, $array, string $message = ''): void
	{
		if (!is_array($array) || !array_key_exists($key, $array))
			$this->failWith($message, 'Failed asserting that array has key '.self::toStr($key));
	}

	public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
	{
		if (strpos($haystack, $needle) === false)
			$this->failWith($message, 'Failed asserting that '.self::toStr($haystack).' contains '.self::toStr($needle));
	}

	public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
	{
		if (strpos($haystack, $needle) !== false)
			$this->failWith($message, 'Failed asserting that string does not contain '.self::toStr($needle));
	}

	public function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
	{
		if (!preg_match($pattern, $string))
			$this->failWith($message, 'Failed asserting that '.self::toStr($string).' matches '.self::toStr($pattern));
	}
}
