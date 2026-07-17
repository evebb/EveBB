<?php

/**
 * eveBB BBCode toolbar - <head> integration.
 *
 * Included by addons/addon_toolbar.php through the header_head_end hook
 * on every page; emits output only on pages with a message textarea
 * (post/edit/quickpost) or the profile signature editor.
 *
 * Based on the EZBBC Toolbar plugin for FluxBB, copyright (C) 2008-2010
 * Jojaba (see plugins/toolbar/CREDITS).
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

if ((isset($required_fields['req_message']) && basename($_SERVER['PHP_SELF']) != 'misc.php') || (defined('PUN_ACTIVE_PAGE') && PUN_ACTIVE_PAGE == 'profile' && $pun_config['o_signatures'] == '1'))
{
	// Settings (with defaults for a fresh installation)
	$toolbar_style = isset($pun_config['o_toolbar_style']) ? preg_replace('%[^a-zA-Z0-9_\-]%', '', $pun_config['o_toolbar_style']) : 'Default';
	if (!file_exists(PUN_ROOT.'plugins/toolbar/style/'.$toolbar_style.'/toolbar.css'))
		$toolbar_style = 'Default';

	$toolbar_extended_smilies = isset($pun_config['o_toolbar_smilies']) && $pun_config['o_toolbar_smilies'] == '1';
	$toolbar_smiley_path = $toolbar_extended_smilies ? 'plugins/toolbar/style/smilies/' : 'img/smilies/';

	// Language (English is always loaded first so translations may omit keys)
	require PUN_ROOT.'plugins/toolbar/lang/English/toolbar.php';
	$toolbar_lang_folder = 'English';
	if ($pun_user['language'] != 'English' && file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$pun_user['language'].'/toolbar.php'))
	{
		$toolbar_lang_english = $lang_toolbar;
		$toolbar_lang_folder = $pun_user['language'];
		require PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/toolbar.php';
		$lang_toolbar = array_merge($toolbar_lang_english, $lang_toolbar);
	}

	$toolbar_help_folder = file_exists(PUN_ROOT.'plugins/toolbar/lang/'.$toolbar_lang_folder.'/help.php') ? $toolbar_lang_folder : 'English';

	// The smiley palette offered by the toolbar
	$toolbar_smilies = array(
		array('code' => ':)', 'img' => 'smile.png'),
		array('code' => ':|', 'img' => 'neutral.png'),
		array('code' => ':(', 'img' => 'sad.png'),
		array('code' => ':D', 'img' => 'big_smile.png'),
		array('code' => ':o', 'img' => 'yikes.png'),
		array('code' => ';)', 'img' => 'wink.png'),
		array('code' => ':/', 'img' => 'hmm.png'),
		array('code' => ':p', 'img' => 'tongue.png'),
		array('code' => ':lol:', 'img' => 'lol.png'),
		array('code' => ':mad:', 'img' => 'mad.png'),
		array('code' => ':rolleyes:', 'img' => 'roll.png'),
		array('code' => ':cool:', 'img' => 'cool.png'),
	);
	if ($toolbar_extended_smilies)
	{
		$toolbar_smilies = array_merge($toolbar_smilies, array(
			array('code' => 'O:)', 'img' => 'angel.png'),
			array('code' => '8.(', 'img' => 'cry.png'),
			array('code' => ']:D', 'img' => 'devil.png'),
			array('code' => '8)', 'img' => 'glasses.png'),
			array('code' => '{)', 'img' => 'kiss.png'),
			array('code' => '8o', 'img' => 'monkey.png'),
			array('code' => ':8', 'img' => 'ops.png'),
		));
	}

	$toolbar_config = array(
		'textarea'		=> (defined('PUN_ACTIVE_PAGE') && PUN_ACTIVE_PAGE == 'profile') ? 'signature' : 'req_message',
		'styleUrl'		=> 'plugins/toolbar/style/'.$toolbar_style,
		'smileyPath'	=> $toolbar_smiley_path,
		'smilies'		=> $toolbar_smilies,
		'showSmilies'	=> $pun_config['o_smilies'] == '1',
		'bbcode'		=> $pun_config['p_message_bbcode'] == '1',
		'imgTag'		=> $pun_config['p_message_img_tag'] == '1',
		'sigBBCode'		=> $pun_config['p_sig_bbcode'] == '1',
		'sigImgTag'		=> $pun_config['p_sig_img_tag'] == '1',
		'helpUrl'		=> 'plugins/toolbar/lang/'.$toolbar_help_folder.'/help.php',
		'lang'			=> $lang_toolbar,
	);

?>
<link rel="stylesheet" type="text/css" href="plugins/toolbar/style/<?php echo $toolbar_style ?>/toolbar.css" />
<script type="text/javascript">/* <![CDATA[ */var EVEBB_TOOLBAR = <?php echo json_encode($toolbar_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;/* ]]> */</script>
<script type="text/javascript" src="plugins/toolbar/toolbar.js"></script>
<?php

}
