#!/usr/bin/env python3
"""Generate 5 Carbon-based eveBB styles by palette remapping.

Light styles (Forest, Ocean, Ruby): rotate Carbon's orange accent family to a
new hue; neutrals untouched.
Dark styles (Midnight, Graphite): invert neutral lightness (tinted), rotate or
brighten accents, darken accent-tinted backgrounds, remap the highlight
yellow, and invert the glyph PNGs.
"""
import colorsys, os, re, shutil, sys

ROOT = '/home/claude/evebb'
OUT = '/tmp/styles-build/out'

def hex_norm(h):
    h = h.lower().lstrip('#')
    if len(h) == 3:
        h = ''.join(c*2 for c in h)
    return h

def to_hls(h):
    h = hex_norm(h)
    r, g, b = (int(h[i:i+2], 16)/255 for i in (0, 2, 4))
    return colorsys.rgb_to_hls(r, g, b)  # (h, l, s)

def from_hls(hh, ll, ss):
    r, g, b = colorsys.hls_to_rgb(hh % 1.0, max(0, min(1, ll)), max(0, min(1, ss)))
    return '#%02x%02x%02x' % (round(r*255), round(g*255), round(b*255))

def classify(hexc):
    hh, ll, ss = to_hls(hexc)
    deg = hh * 360
    if hexc in ('ffffe1', 'ffff00'):
        return 'highlight'
    if hexc == 'd59b9b':
        return 'error'
    if ss < 0.05 and not (15 <= deg <= 50):
        return 'neutral'
    if 15 <= deg <= 50 and ss >= 0.05:
        return 'accent'
    return 'neutral'  # the cool greys (hue ~210-225, low sat)

THEMES = {
    # --- light: accent hue rotation only ---
    'Forest':  dict(dark=False, hue=145/360, sat_mul=0.72, light_mul=0.82),
    'Ocean':   dict(dark=False, hue=208/360, sat_mul=0.95, light_mul=1.00),
    'Ruby':    dict(dark=False, hue=353/360, sat_mul=0.85, light_mul=0.97),
    # --- dark: full remap ---
    'Midnight': dict(dark=True, hue=215/360, sat_mul=1.0, tint_hue=222/360, tint_sat=0.22),
    'Graphite': dict(dark=True, hue=None,    sat_mul=1.0, tint_hue=220/360, tint_sat=0.04),
}

def dark_invert_l(l):
    # light surfaces -> dark surfaces, dark text -> light text
    return max(0.08, min(0.92, 0.98 - 0.88 * l))

def map_color(hexc, t):
    kind = classify(hexc)
    hh, ll, ss = to_hls(hexc)
    if not t['dark']:
        if kind == 'accent':
            return from_hls(t['hue'], ll * t['light_mul'], ss * t['sat_mul'])
        return '#' + hexc  # neutrals/highlight/error untouched in light themes
    # dark themes
    if kind == 'neutral':
        return from_hls(t['tint_hue'], dark_invert_l(ll), t['tint_sat'])
    if kind == 'highlight':
        return from_hls(55/360, 0.22, 0.30)
    if kind == 'error':
        return from_hls(hh, 0.40, 0.35)
    # accent
    target_h = t['hue'] if t['hue'] is not None else hh
    if ll > 0.80:
        # near-white tinted backgrounds (quote boxes etc.) -> dark tinted
        return from_hls(target_h, dark_invert_l(ll), 0.30)
    # visible accents: brighten a little for dark backgrounds
    return from_hls(target_h, min(0.75, 0.22 + 0.85 * ll), ss)

HEX_RE = re.compile(r'#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b')

def transform_css(css, name, t):
    def sub(m):
        return map_color(hex_norm(m.group(0)), t)
    css = HEX_RE.sub(sub, css)
    css = css.replace('url(Carbon/', 'url(%s/' % name)
    if t['dark']:
        # Inversion turns the charcoal nav light; keep it dark and elevated.
        nav_bg = from_hls(t['tint_hue'], 0.155, t['tint_sat'] + 0.06)
        nav_text = from_hls(t['tint_hue'], 0.88, t['tint_sat'])
        hover = from_hls(t['hue'] if t['hue'] is not None else 28/360, 0.52, 0.85)
        css += ('\n/* Dark-theme override: keep the primary navigation dark */\n'
                '#brdmenu, #brdmenu a, #brdmenu a:link, #brdmenu a:visited {\n'
                '\tbackground: %s;\n\tcolor: %s;\n\tborder-color: %s;\n}\n'
                '#brdmenu a:hover, #brdmenu a:active, #brdmenu a:focus {\n'
                '\tbackground: %s;\n\tcolor: #ffffff;\n}\n'
                % (nav_bg, nav_text, from_hls(t['tint_hue'], 0.26, t['tint_sat']), hover))
    header = ('/* %s - an eveBB style based on Carbon (recoloured %s theme).\n'
              '   Drop %s.css and the %s/ folder into your forum\'s style/ directory.\n'
              '   License: GPL v2 or later, same as eveBB. */\n' % (name, 'dark' if t['dark'] else 'light', name, name))
    return header + css

def transform_png(src, dst, t):
    """Invert glyph lightness for dark themes (glyphs are near-black)."""
    import subprocess
    if not t['dark']:
        shutil.copyfile(src, dst)
        return
    php = '''
$im = imagecreatefrompng("%s");
imagesavealpha($im, true); imagealphablending($im, false);
$w = imagesx($im); $h = imagesy($im);
for ($x = 0; $x < $w; $x++) for ($y = 0; $y < $h; $y++) {
    $c = imagecolorat($im, $x, $y);
    $a = ($c >> 24) & 0x7f;
    $r = 210; $g = 218; $b = 230; // light glyph colour for dark surfaces
    imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, $a));
}
imagepng($im, "%s");
''' % (src, dst)
    subprocess.run(['php', '-r', php], check=True)

def build():
    carbon_css = open(os.path.join(ROOT, 'style/Carbon.css')).read()
    admin_css = open(os.path.join(ROOT, 'style/Carbon/base_admin.css')).read()
    imgs = sorted(os.listdir(os.path.join(ROOT, 'style/Carbon/img')))
    for name, t in THEMES.items():
        d = os.path.join(OUT, name)
        os.makedirs(os.path.join(d, name, 'img'), exist_ok=True)
        open(os.path.join(d, '%s.css' % name), 'w').write(transform_css(carbon_css, name, t))
        open(os.path.join(d, name, 'base_admin.css'), 'w').write(HEX_RE.sub(lambda m: map_color(hex_norm(m.group(0)), t), admin_css))
        open(os.path.join(d, name, 'index.html'), 'w').write('<!--  -->')
        open(os.path.join(d, name, 'img', 'index.html'), 'w').write('<!--  -->')
        for f in imgs:
            if not f.endswith('.png'):
                continue
            transform_png(os.path.join(ROOT, 'style/Carbon/img', f),
                          os.path.join(d, name, 'img', f), t)
        print('built', name)

if __name__ == '__main__':
    shutil.rmtree(OUT, ignore_errors=True)
    build()
