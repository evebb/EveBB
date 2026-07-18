<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once PUN_ROOT.'include/styles.php';

/**
 * Unit tests for the style management library (include/styles.php):
 * slug/manifest/zip-entry validation and discovery of the bundled
 * styles. Upload/default/delete are exercised by the e2e suite.
 */
class StyleLibraryTest extends TestCase
{
	public function testValidSlugs()
	{
		// Style names allow uppercase (Air, Dark-buttons)
		$this->assertTrue(evebb_style_slug_is_valid('Air'));
		$this->assertTrue(evebb_style_slug_is_valid('Dark-buttons'));
		$this->assertTrue(evebb_style_slug_is_valid('my_style2'));
	}

	public function testInvalidSlugsRejected()
	{
		$this->assertFalse(evebb_style_slug_is_valid(''));
		$this->assertFalse(evebb_style_slug_is_valid('../evil'));
		$this->assertFalse(evebb_style_slug_is_valid('with space'));
		$this->assertFalse(evebb_style_slug_is_valid('has.dot'));
		$this->assertFalse(evebb_style_slug_is_valid('with/slash'));
		$this->assertFalse(evebb_style_slug_is_valid('-leading'));
	}

	public function testManifestValidation()
	{
		$this->assertSame(true, evebb_style_manifest_check(array('name' => 'X', 'slug' => 'x', 'version' => '1.0')));
		$this->assertIsString(evebb_style_manifest_check(array('slug' => 'x', 'version' => '1.0')));   // no name
		$this->assertIsString(evebb_style_manifest_check(array('name' => 'X', 'slug' => 'x')));         // no version
		$this->assertIsString(evebb_style_manifest_check('not an array'));

		$m = array('name' => 'X', 'slug' => 'x', 'version' => '1.0');
		$this->assertSame(true, evebb_style_manifest_check($m, 'x'));
		$this->assertIsString(evebb_style_manifest_check($m, 'y'));   // slug/folder mismatch
	}

	public function testZipEntrySafety()
	{
		$this->assertTrue(evebb_style_entry_is_safe('mystyle/style.json'));
		$this->assertTrue(evebb_style_entry_is_safe('mystyle/img/x.png'));

		$this->assertFalse(evebb_style_entry_is_safe('../evil'));
		$this->assertFalse(evebb_style_entry_is_safe('/abs'));
		$this->assertFalse(evebb_style_entry_is_safe('a/../../b'));
		$this->assertFalse(evebb_style_entry_is_safe('C:/x'));
		$this->assertFalse(evebb_style_entry_is_safe(''));
	}

	public function testInstalledStylesIncludeBundled()
	{
		// The bundled Air/Earth/Fire styles must be discovered, with Air
		// marked default (bootstrap sets no default, so just check presence)
		$GLOBALS['pun_config']['o_default_style'] = 'Air';
		$styles = evebb_installed_styles();

		$this->assertArrayHasKey('Air', $styles);
		$this->assertArrayHasKey('Earth', $styles);
		$this->assertArrayHasKey('Fire', $styles);
		$this->assertTrue($styles['Air']['is_default']);
		$this->assertFalse($styles['Earth']['is_default']);
	}

	public function testStyleExists()
	{
		$this->assertTrue(evebb_style_exists('Air'));
		$this->assertFalse(evebb_style_exists('Nonexistent'));
		$this->assertFalse(evebb_style_exists('../evil'));
	}
}
