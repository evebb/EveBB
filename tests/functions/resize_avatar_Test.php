<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../include/functions.php';

class resize_avatar_Test extends TestCase
{
	private $tmp = array();

	private function makeImage($w, $h, $type)
	{
		$path = tempnam(sys_get_temp_dir(), 'av');
		$this->tmp[] = $path;
		$im = imagecreatetruecolor($w, $h);
		imagefill($im, 0, 0, imagecolorallocate($im, 120, 90, 60));
		switch ($type)
		{
			case IMAGETYPE_JPEG: imagejpeg($im, $path, 92); break;
			case IMAGETYPE_PNG:  imagepng($im, $path); break;
			case IMAGETYPE_GIF:  imagegif($im, $path); break;
		}
		imagedestroy($im);
		return $path;
	}

	public function tearDown(): void
	{
		foreach ($this->tmp as $p)
			@unlink($p);
		$this->tmp = array();
	}

	public function testLargeSquareIsResizedToFit()
	{
		$src = $this->makeImage(460, 460, IMAGETYPE_JPEG);
		$dst = tempnam(sys_get_temp_dir(), 'avo');
		$this->tmp[] = $dst;

		$this->assertTrue(resize_avatar($src, $dst, IMAGETYPE_JPEG, 90, 90));

		list($w, $h) = getimagesize($dst);
		$this->assertSame(90, $w);
		$this->assertSame(90, $h);
	}

	public function testAspectRatioIsPreserved()
	{
		$src = $this->makeImage(400, 300, IMAGETYPE_PNG);
		$dst = tempnam(sys_get_temp_dir(), 'avo');
		$this->tmp[] = $dst;

		$this->assertTrue(resize_avatar($src, $dst, IMAGETYPE_PNG, 90, 90));

		list($w, $h) = getimagesize($dst);
		$this->assertSame(90, $w);
		$this->assertSame(68, $h); // 300 * (90/400) = 67.5 -> 68
	}

	public function testSmallImageIsNotUpscaled()
	{
		$src = $this->makeImage(40, 40, IMAGETYPE_PNG);
		$dst = tempnam(sys_get_temp_dir(), 'avo');
		$this->tmp[] = $dst;

		$this->assertTrue(resize_avatar($src, $dst, IMAGETYPE_PNG, 90, 90));

		list($w, $h) = getimagesize($dst);
		$this->assertSame(40, $w);
		$this->assertSame(40, $h);
	}

	public function testResizedPngIsAValidImage()
	{
		$src = $this->makeImage(500, 250, IMAGETYPE_PNG);
		$dst = tempnam(sys_get_temp_dir(), 'avo');
		$this->tmp[] = $dst;

		$this->assertTrue(resize_avatar($src, $dst, IMAGETYPE_PNG, 90, 90));

		$info = getimagesize($dst);
		$this->assertSame(IMAGETYPE_PNG, $info[2]);
	}

	public function testMissingSourceFails()
	{
		$dst = tempnam(sys_get_temp_dir(), 'avo');
		$this->tmp[] = $dst;

		$this->assertFalse(resize_avatar('/nonexistent/does-not-exist.png', $dst, IMAGETYPE_PNG, 90, 90));
	}
}
