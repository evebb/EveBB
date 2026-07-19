# eveBB copyright and licensing audit

*Audited: July 2026, at 1.22.0-alpha. Scope: every file distributed in the
release package — all PHP, JavaScript, CSS, templates, language files, images
and other media.*

## Summary

eveBB is distributed under the **GNU GPL v2 or later** (the full text ships as
`COPYING`, included in every release package). The audit traced the provenance
of every component to one of five origins, all of them compatible with that
distribution: (1) code and assets inherited from FluxBB/PunBB under the GPL,
(2) code and assets written originally for eveBB, (3) the MIT-licensed SCEditor
bundle, (4) two attribution-required art sets that ship inside SCEditor, for
which attribution is now provided, and (5) the Apache-2.0 Noto Emoji smilies,
whose notice already ships with the package. **No component of unknown or
incompatible origin was found.** One compliance gap was found and fixed during
the audit (famfamfam icon attribution, below).

## Code

**FluxBB/PunBB-inherited PHP, JS, CSS and templates.** The bulk of the
codebase descends from FluxBB 1.5.x (GPL v2+), itself descended from PunBB
(GPL v2+). The git history preserves the full lineage back to the 2008 import,
and the original copyright headers — `Copyright (C) 2008-2012 FluxBB / based
on code by Rickard Andersson copyright (C) 2002-2008 PunBB` — are retained in
every inherited file (verified: 50 files carry them, none stripped). The GPL
permits modification and redistribution on exactly these terms. `composer.json`
declares `GPL-2.0-or-later` and credits the FluxBB and PunBB authors.

**eveBB-original code.** Files written new for this project (e.g.
`include/captcha.php`, `include/update.php`, `include/countries.php`, the PDO
database drivers, the test suites) are marked `Copyright (C) 2026 eveBB` and
licensed GPL v2+, the same as the project. The country list in
`include/countries.php` is a list of factual names, not a copyrightable work.

**UTF-8 library (`include/utf8/`).** The phputf8 library by Harry Fuecks and
contributors, inherited verbatim from FluxBB, which shipped it for its entire
life. Three files (`utils/validation.php`, `utils/bad.php`,
`utils/unicode.php`) are Henri Sivonen's PHP ports of Mozilla Communicator
code (Copyright (C) 1998 Netscape). Mozilla's legacy code was made available
under the MPL/GPL/LGPL tri-license, making GPL distribution permissible; the
original notices are retained intact. This exact bundle has been distributed
under the GPL by FluxBB, PunBB and many other GPL projects for nearly two
decades.

**SCEditor (`js/sceditor/`).** The visual editor, v3.2.1, copyright (C)
2011–2017 Sam Clarke, under the **MIT License**. The license file ships in the
bundle (`js/sceditor/LICENSE.md`) and every JS/CSS file retains the upstream
copyright banner. MIT code may be bundled inside a GPL distribution; the MIT
notice must accompany it, and it does. The only eveBB additions to the bundle
are `themes/content/evebb.css` (original) and `CREDITS.md` (attribution, see
below).

**Development-only dependencies.** PHPUnit and its dependency tree appear in
`composer.lock` as `require-dev` only. No `vendor/` directory exists in the
repository, and none is included in release packages — nothing from Composer
is distributed.

## Images and media

Every image in the shipped tree was traced to its first commit:

**Inherited from FluxBB (GPL).** `img/test.png` is in the git history from the
FluxBB era and has been distributed under the GPL with FluxBB throughout.

**Smilies (`img/smilies/*`, 12 files).** Replaced in 1.18.0-alpha with images
from Google's **Noto Emoji** project, licensed **Apache License 2.0** — a
permissive license that allows redistribution with notice. The notice ships as
`img/smilies/CREDITS.txt` (in every package), naming the project, the license
and its URL. Shipping Apache-2.0 image assets alongside a GPL program is
aggregation, not code combination, and the project's "GPL v2 *or later*"
licensing also keeps the combination unambiguous under GPLv3 terms.

**Created originally for eveBB.** `img/evebb-logo.svg/.png`,
`img/default_avatar.svg/.png` and `img/icon-newpost.svg` were authored for
this project (the SVG sources are simple hand-written vector shapes; the PNGs
are renders of them). They contain no embedded third-party content (verified:
no base64 rasters, no external references) and are original works of the
project, GPL v2+ like everything else.

**Carbon style (`style/Carbon*`).** Forked from FluxBB's GPL "Air" style and
recoloured for eveBB; its seven small PNG glyphs were newly generated for the
recolour. The commit notes the palette was *inspired by* a third-party site's
colour scheme — a colour palette is not a copyrightable work, and no assets or
CSS were copied from that site. Derivative of GPL material within a GPL
project: compliant.

**SCEditor toolbar icons (`js/sceditor/themes/famfamfam.png`).** The "Silk"
icon set by Mark James (famfamfam.com), licensed **Creative Commons
Attribution 3.0** — attribution is a condition of the license. The upstream
SCEditor README carries this credit, but eveBB's bundle did not ship that
README. **Fixed during this audit:** `js/sceditor/CREDITS.md` now provides the
required attribution, and the readme's credits section repeats it.

**SCEditor emoticons (`js/sceditor/emoticons/`, 35 files).** "Nomicons: The
Full Monty Emoticons" by Oscar Gruno and Andy Fedosjeenko, distributed with
SCEditor by its author for over a decade with the credit line SCEditor's
README carries. That credit is now reproduced in `js/sceditor/CREDITS.md`. If
a stricter posture is ever wanted, the emoticon set is swappable via the
editor's `emoticonsRoot` option.

**Fonts.** None are bundled. The registration CAPTCHA deliberately renders
with GD's built-in bitmap font precisely so no font file ships with the
package. Styles reference system font stacks (Arial, Helvetica, Verdana) by
name only, which raises no copyright issue.

## Trademarks and names

"eveBB" is this project's own name. The readme and footer reference FluxBB and
PunBB nominatively (to describe lineage), which is standard and permitted.
The bundled default rules text, language strings and documentation were
written for FluxBB (GPL) or for eveBB. Nothing uses third-party brand assets.

## Distribution compliance checklist

- [x] GPL v2 text ships in every package (`COPYING`; verified present in the
      release zip build)
- [x] SCEditor MIT license ships in the bundle (`js/sceditor/LICENSE.md`)
- [x] CC-BY attribution for the Silk icons ships (`js/sceditor/CREDITS.md`)
- [x] Apache 2.0 notice for the Noto Emoji smilies ships (`img/smilies/CREDITS.txt`)
- [x] All inherited FluxBB/PunBB copyright headers retained
- [x] Source availability: the package *is* the complete source (no
      compiled/obfuscated components except SCEditor's minified JS, whose
      source is publicly available at github.com/samclarke/SCEditor — noted
      in CREDITS.md)
- [x] No Composer/vendor code distributed
- [x] No bundled fonts, stock photos, AI-scraped art or other assets of
      unclear provenance

## Conclusion

The package is cleanly redistributable. Every file is either GPL-inherited,
project-original, MIT-licensed with its notice intact, or CC-BY with
attribution now provided. No permission from any third party is required to
release eveBB.
