/**
 * eveBB BBCode toolbar.
 *
 * Based on the EZBBC Toolbar plugin for FluxBB, copyright (C) 2008-2010
 * Jojaba, itself based on a tutorial by Thunderseb (see CREDITS).
 * Modernized for eveBB: configuration comes from the EVEBB_TOOLBAR
 * object emitted by plugins/toolbar/toolbar_head.php.
 *
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

(function () {
	'use strict';

	if (typeof EVEBB_TOOLBAR === 'undefined')
		return;

	var cfg = EVEBB_TOOLBAR;
	var L = cfg.lang;

	function field() {
		return document.getElementsByName(cfg.textarea)[0];
	}

	// Insert startTag + selection + endTag at the cursor, with per-type
	// prompt flows matching the original EZBBC behaviour
	function insertTag(startTag, endTag, tagType) {
		var f = field();
		if (!f)
			return;

		var scroll = f.scrollTop;
		f.focus();

		var before = f.value.substring(0, f.selectionStart);
		var sel = f.value.substring(f.selectionStart, f.selectionEnd);
		var after = f.value.substring(f.selectionEnd);

		var url, label, text, value;

		switch (tagType) {
			case 'color':
				value = prompt(L['Ask color'] + ' (' + L['Ask color explanation'] + ')', '');
				if (value === null || value === '') { startTag = endTag = ''; break; }
				startTag = '[color=' + value + ']';
				if (!sel) {
					text = prompt(L['Ask colorized text'], '');
					sel = (text !== null && text !== '') ? text : L['Ask colorized text'];
				}
				break;

			case 'heading':
				if (!sel) {
					text = prompt(L['Ask title'], '');
					if (text === null) { startTag = endTag = ''; break; }
					sel = (text !== '') ? text : L['Ask title'];
				}
				break;

			case 'link':
				if (sel && /^(https?:\/\/|ftp:\/\/|www\.)/.test(sel)) {
					startTag = '[url=' + sel + ']';
					sel = '';
				} else {
					url = prompt(L['Ask url'], '');
					if (url === null || url === '' || !/^(https?:\/\/|ftp:\/\/|www\.)/.test(url)) { startTag = endTag = ''; break; }
					startTag = '[url=' + url + ']';
					if (!sel) {
						label = prompt(L['Ask label'], '');
						sel = (label !== null && label !== '') ? label : url;
					}
				}
				break;

			case 'email':
				if (sel && sel.indexOf('@') !== -1) {
					startTag = '[email=' + sel + ']';
					sel = '';
				} else {
					url = prompt(L['Ask email'], '');
					if (url === null || url === '' || url.indexOf('@') === -1) { startTag = endTag = ''; break; }
					startTag = '[email=' + url + ']';
					if (!sel) {
						label = prompt(L['Ask label'], '');
						sel = (label !== null && label !== '') ? label : url;
					}
				}
				break;

			case 'img':
				if (sel && /^(https?:\/\/|ftp:\/\/)/.test(sel)) {
					label = prompt(L['Ask alt'], '');
					startTag = (label !== null && label !== '') ? '[img=' + label + ']' : '[img]';
				} else {
					url = prompt(L['Ask url img'], '');
					if (url === null || url === '' || !/^(https?:\/\/|ftp:\/\/)/.test(url)) { startTag = endTag = ''; break; }
					startTag = sel ? '[img=' + sel + ']' : '[img]';
					sel = url;
				}
				break;

			case 'quote':
				label = prompt(L['Ask author'], '');
				startTag = (label !== null && label !== '') ? '[quote=' + label + ']\n' : '[quote]\n';
				if (!sel) {
					text = prompt(L['Ask quotation'], '');
					if (text === null) { startTag = endTag = ''; break; }
					sel = text;
				}
				break;

			case 'code':
				if (!sel) {
					text = prompt(L['Ask code'], '');
					if (text === null) { startTag = endTag = ''; break; }
					sel = text;
				}
				break;

			case 'unorderedlist':
			case 'orderedlist':
			case 'alphaorderedlist':
				if (!sel) {
					var items = [];
					var i = 1;
					for (;;) {
						var item = prompt(L['Ask item'] + i + ' (' + L['Ask item explanation'] + ')', '');
						if (item === null || item === '')
							break;
						items.push('[*]' + item + '[/*]');
						i++;
					}
					if (!items.length) { startTag = endTag = ''; break; }
					sel = '\n' + items.join('\n') + '\n';
				} else {
					sel = '[*]' + sel + '[/*]';
				}
				break;
		}

		f.value = before + startTag + sel + endTag + after;
		f.focus();
		f.setSelectionRange(before.length + startTag.length, before.length + startTag.length + sel.length);
		f.scrollTop = scroll;
	}

	function button(name, title, start, end, type) {
		var img = document.createElement('img');
		img.className = 'button';
		img.src = cfg.styleUrl + '/images/' + name + '.png';
		img.title = title;
		img.alt = title;
		img.addEventListener('click', function () { insertTag(start, end, type); });
		return img;
	}

	function spacer() {
		return document.createTextNode(' ');
	}

	function buildToolbar() {
		var bar = document.createElement('span');
		bar.id = 'evebb-toolbar';

		var isSignature = cfg.textarea === 'signature';
		var bbcode = isSignature ? cfg.sigBBCode : cfg.bbcode;
		var imgTag = isSignature ? cfg.sigImgTag : cfg.imgTag;

		// Smiley palette (hidden until toggled)
		if (!isSignature && cfg.showSmilies) {
			var palette = document.createElement('span');
			palette.id = 'evebb-toolbar-smilies';
			palette.style.display = 'none';
			cfg.smilies.forEach(function (s) {
				var img = document.createElement('img');
				img.className = 'smiley';
				img.src = cfg.smileyPath + s.img;
				img.title = s.code;
				img.alt = s.code;
				img.addEventListener('click', function () { insertTag(s.code, '', 'smiley'); });
				palette.appendChild(img);
			});
			bar.appendChild(palette);
			bar.appendChild(document.createElement('br'));
		}

		if (bbcode) {
			bar.appendChild(button('bold', L['Bold'], '[b]', '[/b]', ''));
			bar.appendChild(button('underline', L['Underline'], '[u]', '[/u]', ''));
			bar.appendChild(button('italic', L['Italic'], '[i]', '[/i]', ''));
			bar.appendChild(button('strike-through', L['Strike-through'], '[s]', '[/s]', ''));
			bar.appendChild(button('delete', L['Delete'], '[del]', '[/del]', ''));
			bar.appendChild(button('insert', L['Insert'], '[ins]', '[/ins]', ''));
			bar.appendChild(button('emphasis', L['Emphasis'], '[em]', '[/em]', ''));
			bar.appendChild(spacer());

			bar.appendChild(button('color', L['Colorize'], '[color]', '[/color]', 'color'));
			bar.appendChild(button('heading', L['Heading'], '[h]', '[/h]', 'heading'));
			bar.appendChild(spacer());

			bar.appendChild(button('link', L['URL'], '[url]', '[/url]', 'link'));
			bar.appendChild(button('email', L['E-mail'], '[email]', '[/email]', 'email'));
			if (imgTag)
				bar.appendChild(button('image', L['Image'], '[img]', '[/img]', 'img'));
			bar.appendChild(spacer());

			bar.appendChild(button('quote', L['Quote'], '[quote]\n', '\n[/quote]', 'quote'));
			bar.appendChild(button('code', L['Code'], '[code]\n', '\n[/code]', 'code'));
			bar.appendChild(spacer());

			bar.appendChild(button('list-unordered', L['Unordered List'], '[list=*]', '[/list]', 'unorderedlist'));
			bar.appendChild(button('list-ordered', L['Ordered List'], '[list=1]', '[/list]', 'orderedlist'));
			bar.appendChild(button('list-ordered-alpha', L['Alphabetical Ordered List'], '[list=a]', '[/list]', 'alphaorderedlist'));
			bar.appendChild(spacer());
		}

		// Smiley palette toggle
		if (!isSignature && cfg.showSmilies) {
			var toggle = document.createElement('img');
			toggle.className = 'button';
			toggle.src = cfg.styleUrl + '/images/smilie.png';
			toggle.title = L['Smilies toggle'];
			toggle.alt = L['Smilies toggle'];
			toggle.addEventListener('click', function () {
				var palette = document.getElementById('evebb-toolbar-smilies');
				palette.style.display = (palette.style.display === 'none') ? 'inline' : 'none';
			});
			bar.appendChild(toggle);
		}

		// Help
		var help = document.createElement('a');
		help.className = 'toolbar_help';
		help.href = cfg.helpUrl;
		help.target = '_blank';
		help.rel = 'noopener';
		var helpImg = document.createElement('img');
		helpImg.src = cfg.styleUrl + '/images/help.png';
		helpImg.title = L['Toolbar help'];
		helpImg.alt = L['Toolbar help'];
		help.appendChild(helpImg);
		bar.appendChild(help);

		return bar;
	}

	function attach() {
		var f = field();
		if (!f)
			return;
		f.parentNode.insertBefore(buildToolbar(), f);
	}

	if (document.readyState === 'loading')
		document.addEventListener('DOMContentLoaded', attach);
	else
		attach();
}());
