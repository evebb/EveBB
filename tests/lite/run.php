<?php

/**
 * Lite test runner: php tests/lite/run.php [file-or-dir ...]
 *
 * Discovers *Test.php / *_Test.php files, runs all test* methods with
 * setUp/tearDown, prints a PHPUnit-style summary and exits non-zero on
 * failure. Uses the bundled PHPUnit-compatible TestCase, so the same
 * test files run unchanged under real PHPUnit in CI.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!class_exists('PHPUnit\\Framework\\TestCase'))
	require_once __DIR__.'/TestCase.php';

$targets = array_slice($argv, 1);
if (!$targets)
	$targets = array(dirname(__DIR__)); // default: whole tests/ dir

$files = array();
foreach ($targets as $t)
{
	if (is_file($t))
		$files[] = $t;
	else if (is_dir($t))
	{
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f)
			if (preg_match('%(Test|_Test)\.php$%', $f->getPathname()) && strpos($f->getPathname(), '/lite/') === false)
				$files[] = $f->getPathname();
	}
}
sort($files);

$before = get_declared_classes();
foreach ($files as $file)
	require_once $file;
$testClasses = array();
foreach (array_diff(get_declared_classes(), $before) as $class)
	if (is_subclass_of($class, 'PHPUnit\\Framework\\TestCase'))
		$testClasses[] = $class;

$passed = $failed = $skipped = $errored = 0;
$failures = array();

foreach ($testClasses as $class)
{
	$rc = new ReflectionClass($class);
	if ($rc->isAbstract())
		continue;

	$class::setUpBeforeClass();

	foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
	{
		$name = $method->getName();
		if (strpos($name, 'test') !== 0)
			continue;

		$label = "$class::$name";
		$obj = $rc->newInstance();

		try
		{
			$obj->setUp();
			$obj->$name();
			$obj->tearDown();
			$passed++;
			echo '.';
		}
		catch (PHPUnit\Framework\SkippedTest $e)
		{
			$skipped++;
			echo 'S';
		}
		catch (PHPUnit\Framework\AssertionFailedError $e)
		{
			$failed++;
			$failures[] = "$label\n    ".$e->getMessage();
			echo 'F';
		}
		catch (Throwable $e)
		{
			$errored++;
			$failures[] = "$label\n    ".get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
			echo 'E';
		}

		if (($passed + $failed + $skipped + $errored) % 60 === 0)
			echo "\n";
	}

	$class::tearDownAfterClass();
}

echo "\n\n";
foreach ($failures as $i => $f)
	echo ($i + 1).") $f\n\n";

$total = $passed + $failed + $skipped + $errored;
echo "Tests: $total, Passed: $passed, Failures: $failed, Errors: $errored, Skipped: $skipped\n";
exit(($failed + $errored) > 0 ? 1 : 0);
