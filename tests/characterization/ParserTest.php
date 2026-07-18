<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

/**
 * Characterization tests for the BBCode parser (include/parser.php).
 * These pin the exact HTML the parser produces today, so any later
 * refactor that changes output is caught immediately.
 */
class ParserTest extends TestCase
{
	private function render($bbcode, $hide_smilies = 0)
	{
		$errors = array();
		$pre = preparse_bbcode($bbcode, $errors);
		$this->assertSame(array(), $errors, 'preparse errors for: '.$bbcode);
		return parse_message($pre, $hide_smilies);
	}

	public function testBoldAndItalic()
	{
		$this->assertSame(
			'<p><strong>bold</strong> and <em>italic</em></p>',
			$this->render('[b]bold[/b] and [i]italic[/i]')
		);
	}

	public function testUrlTagGetsNofollow()
	{
		$this->assertSame(
			'<p><a href="https://example.com" rel="nofollow">link</a></p>',
			$this->render('[url=https://example.com]link[/url]')
		);
	}

	public function testBareUrlsAreAutoLinked()
	{
		$this->assertSame(
			'<p>bare link <a href="https://fluxbb.org" rel="nofollow">https://fluxbb.org</a> here</p>',
			$this->render('bare link https://fluxbb.org here')
		);
	}

	public function testCodeBlockEscapesHtml()
	{
		$this->assertSame(
			'<div class="codebox"><pre><code>x &lt; y</code></pre></div>',
			$this->render('[code]x < y[/code]')
		);
	}

	public function testQuoteWithAuthor()
	{
		$this->assertSame(
			'<div class="quotebox"><cite>Alan wrote:</cite><blockquote><div><p>hi</p></div></blockquote></div>',
			$this->render('[quote=Alan]hi[/quote]')
		);
	}

	public function testUnorderedList()
	{
		$this->assertSame(
			'<ul><li><p>one</p></li><li><p>two</p></li></ul>',
			$this->render('[list][*]one[/*][*]two[/*][/list]')
		);
	}

	public function testImgTag()
	{
		$this->assertSame(
			'<p><span class="postimg"><img src="https://example.com/pic.png" alt="pic.png" /></span></p>',
			$this->render('[img]https://example.com/pic.png[/img]')
		);
	}

	public function testSmileyRendersAsImage()
	{
		$this->assertSame(
			'<p>hi <img src="http://example.test/img/smilies/smile.png" width="20" height="20" alt="smile" /></p>',
			$this->render('hi :)')
		);
	}

	public function testHideSmiliesLeavesTextForm()
	{
		$this->assertSame(
			'<p>text with :) smiley</p>',
			$this->render('text with :) smiley', 1)
		);
	}

	public function testRawHtmlIsEscaped()
	{
		$this->assertSame(
			'<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
			$this->render('<script>alert(1)</script>')
		);
	}

	public function testUnclosedTagReportsError()
	{
		$errors = array();
		preparse_bbcode('[b]unclosed', $errors);
		$this->assertSame(array('The tag [b] has no closing [/b]'), $errors);
	}

	public function testSignatureParsing()
	{
		$errors = array();
		$pre = preparse_bbcode('[b]sig[/b]', $errors, true);
		$this->assertSame(array(), $errors);
		$this->assertSame('<p><strong>sig</strong></p>', parse_signature($pre));
	}

	public function testNestedQuoteRespected()
	{
		$html = $this->render('[quote=A][quote=B]inner[/quote]outer[/quote]');
		$this->assertStringContainsString('<cite>A wrote:</cite>', $html);
		$this->assertStringContainsString('<cite>B wrote:</cite>', $html);
		$this->assertStringContainsString('inner', $html);
	}

	public function testUtf8SurvivesParsing()
	{
		$this->assertSame(
			'<p>héllo wörld 日本語</p>',
			$this->render('héllo wörld 日本語')
		);
	}
}
