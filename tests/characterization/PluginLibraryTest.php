<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once PUN_ROOT.'include/plugins.php';

/**
 * Unit tests for the plugin management library (include/plugins.php):
 * manifest validation, slug/path safety and zip-entry safety. The
 * database-backed helpers (activate/install) are exercised by the
 * end-to-end suite.
 */
class PluginLibraryTest extends TestCase
{
	public function testValidSlugs()
	{
		$this->assertTrue(evebb_plugin_slug_is_valid('toolbar'));
		$this->assertTrue(evebb_plugin_slug_is_valid('my-plugin_2'));
		$this->assertTrue(evebb_plugin_slug_is_valid('a'));
	}

	public function testInvalidSlugsRejected()
	{
		$this->assertFalse(evebb_plugin_slug_is_valid(''));
		$this->assertFalse(evebb_plugin_slug_is_valid('../evil'));
		$this->assertFalse(evebb_plugin_slug_is_valid('Has Spaces'));
		$this->assertFalse(evebb_plugin_slug_is_valid('UPPER'));
		$this->assertFalse(evebb_plugin_slug_is_valid('with/slash'));
		$this->assertFalse(evebb_plugin_slug_is_valid('-leading'));
	}

	public function testManifestRequiresCoreFields()
	{
		$this->assertSame(true, evebb_manifest_check(array('name' => 'X', 'slug' => 'x', 'version' => '1.0')));
		$this->assertIsString(evebb_manifest_check(array('slug' => 'x', 'version' => '1.0')));       // no name
		$this->assertIsString(evebb_manifest_check(array('name' => 'X', 'version' => '1.0')));        // no slug
		$this->assertIsString(evebb_manifest_check(array('name' => 'X', 'slug' => 'x')));             // no version
		$this->assertIsString(evebb_manifest_check('not an array'));
	}

	public function testManifestSlugMustMatchFolder()
	{
		$m = array('name' => 'X', 'slug' => 'x', 'version' => '1.0');
		$this->assertSame(true, evebb_manifest_check($m, 'x'));
		$this->assertIsString(evebb_manifest_check($m, 'y'));
	}

	public function testManifestFileReferencesMustBeSafe()
	{
		$base = array('name' => 'X', 'slug' => 'x', 'version' => '1.0');

		$this->assertSame(true, evebb_manifest_check($base + array('addon' => 'x.php')));
		$this->assertSame(true, evebb_manifest_check($base + array('admin' => 'admin.php')));

		$this->assertIsString(evebb_manifest_check($base + array('addon' => '../evil.php')));
		$this->assertIsString(evebb_manifest_check($base + array('addon' => '/etc/x.php')));
		$this->assertIsString(evebb_manifest_check($base + array('admin' => 'notphp.txt')));
	}

	public function testZipEntrySafety()
	{
		$this->assertTrue(evebb_plugin_entry_is_safe('hello/plugin.json'));
		$this->assertTrue(evebb_plugin_entry_is_safe('hello/lang/English/x.php'));

		$this->assertFalse(evebb_plugin_entry_is_safe('../evil'));
		$this->assertFalse(evebb_plugin_entry_is_safe('/abs'));
		$this->assertFalse(evebb_plugin_entry_is_safe('a/../../b'));
		$this->assertFalse(evebb_plugin_entry_is_safe('C:/x'));
		$this->assertFalse(evebb_plugin_entry_is_safe(''));
	}

	public function testBundledHelloManifestIsValid()
	{
		// The bundled reference plugin must always parse
		$manifest = evebb_read_manifest('hello');
		$this->assertNotNull($manifest);
		$this->assertSame('hello', $manifest['slug']);
		$this->assertSame('hello.php', $manifest['addon']);
	}

	public function testActivePluginsParsing()
	{
		$GLOBALS['pun_config']['o_active_plugins'] = 'toolbar, hello ,';
		$active = evebb_active_plugins();
		$this->assertSame(array('toolbar', 'hello'), $active);
		$this->assertTrue(evebb_plugin_is_active('hello'));
		$this->assertFalse(evebb_plugin_is_active('missing'));

		$GLOBALS['pun_config']['o_active_plugins'] = '';
		$this->assertSame(array(), evebb_active_plugins());
	}
}
