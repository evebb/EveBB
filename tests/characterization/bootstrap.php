<?php

/**
 * Bootstrap for characterization tests that need eveBB's function
 * library and parser loaded outside a real request. Sets up the minimum
 * globals those files expect.
 */

if (!defined('PUN'))
	define('PUN', 1);
if (!defined('PUN_ROOT'))
	define('PUN_ROOT', dirname(dirname(__DIR__)).'/');

require_once PUN_ROOT.'include/utf8/utf8.php';
require_once PUN_ROOT.'include/utf8/str_pad.php';
require_once PUN_ROOT.'include/functions.php';

// CLI has no REMOTE_ADDR; several helpers assume a web request
if (!isset($_SERVER['REMOTE_ADDR']))
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Minimal config/user/lang state for the parser
$GLOBALS['pun_config'] = array(
	'o_censoring'		=> '0',
	'o_indent_num_spaces'	=> '4',
	'o_make_links'		=> '1',
	'o_quote_depth'		=> '3',
	'o_smilies'			=> '1',
	'o_smilies_sig'		=> '1',
	'o_base_url'		=> 'http://example.test',
	'p_message_bbcode'	=> '1',
	'p_message_img_tag'	=> '1',
	'p_sig_bbcode'		=> '1',
	'p_sig_img_tag'		=> '1',
	'p_sig_length'		=> '400',
	'p_sig_lines'		=> '4',
);

$GLOBALS['pun_user'] = array(
	'is_guest'		=> false,
	'show_img'		=> '1',
	'show_img_sig'	=> '1',
	'show_smilies'	=> '1',
	'g_post_links'	=> '1',
);

$GLOBALS['lang_common'] = array(
	'BBCode list size error'				=> 'The list is too long',
	'BBCode error empty attribute'			=> 'The [%s] tag had an empty attribute',
	'BBCode error tag not allowed'			=> 'The tag [%s] is not allowed',
	'BBCode error invalid nesting'			=> 'The tag [%1$s] was nested badly within [%2$s]',
	'BBCode error invalid self-nesting'		=> 'The tag [%s] was nested within itself',
	'BBCode error no opening tag'			=> 'The tag [/%1$s] has no opening [%1$s]',
	'BBCode error no closing tag'			=> 'The tag [%1$s] has no closing [/%1$s]',
	'BBCode code problem'					=> 'Code tag problem',
	'BBCode quote problem'					=> 'Quote tag problem',
	'wrote'									=> 'wrote:',
	'Image link'							=> 'image',
	'Code'									=> 'Code:',
	'Quote'									=> 'Quote:',
);

$GLOBALS['lang_post'] = array(
	'BBCode list size error'	=> 'The list is too long',
);

require_once PUN_ROOT.'include/parser.php';
