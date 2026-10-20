"""
Site colour palette tool (see instruction.md, Site colour palette).
Moves every colour in the site's own CSS (stylesheets + <style> blocks + style="" attributes) into
named CSS variables, keeping the exact original colour as the fallback, so nothing changes visually:
    rgba(139, 92, 255, 0.8)  ->  rgb(var(--c-primary, 139 92 255) / 0.8)
Every colour gets a palette role (or 'fixed' for greys / white / black) and an HSL offset from that
role's default colour, written to backend/includes/palette_map.json for the theme generator.
usage: python backend/scripts/colorize_css.py <repo folder> [--dry]
Run it again after adding CSS with plain colours (#hex, rgb(), rgba(), white, black): already
converted values are left alone, new ones are converted, and palette_map.json keeps its entries.
Check the result in the browser and with Admin -> Site Colours afterwards.
"""
import colorsys
import json
import os
import re
import sys

REPO = sys.argv[1]
DRY = '--dry' in sys.argv

ROLES = {   # key: (label, default hex, group)
    'bg':          ('Page background', '#06040f', 'dark'),
    'surface':     ('Panels and cards', '#0e0924', 'dark'),
    'text':        ('Main text', '#f3f0ff', 'light'),
    'muted':       ('Secondary text', '#aaa3d2', 'mid'),
    'primary':     ('Main colour', '#8b5cff', 'mid'),
    'accent':      ('Accent (teal)', '#2de2e6', 'mid'),
    'accent2':     ('Accent (green)', '#5cffb0', 'mid'),
    'accent3':     ('Accent (pink)', '#ff4fd8', 'mid'),
    'highlight':   ('Highlight (gold)', '#ffcf7d', 'mid'),
    'danger':      ('Warnings and errors', '#ff93b4', 'mid'),
    'paper':       ('White band background', '#fbfaff', 'light'),
    'paperText':   ('White band text', '#15102c', 'dark'),
    'paperAccent': ('White band accent', '#5b34e6', 'mid'),
}
NAMED = {'white': (255, 255, 255), 'black': (0, 0, 0)}

CSS_FILES = ['frontend/css/aurora.css', 'frontend/css/site.css', 'frontend/AdminCss/Dashboard.css',
             'frontend/AdminCss/admin.css', 'frontend/css/templatemo-festava-live.css']
PAGE_DIRS = [('frontend', ('.php', '.html')), ('frontend/includes', ('.php',))]
# The Site Colours page keeps fixed colours on purpose (a bad palette must never make it unreadable)
SKIP_PAGES = {'theme.php'}


def hex_rgb(h):
    h = h.lstrip('#')
    if len(h) in (3, 4):
        h = ''.join(c * 2 for c in h)
    r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
    a = int(h[6:8], 16) / 255 if len(h) == 8 else None
    return (r, g, b), a


def hls(rgb):
    h, l, s = colorsys.rgb_to_hls(*(c / 255 for c in rgb))
    return h * 360, s, l


ROLE_RGB = {k: hex_rgb(v[1])[0] for k, v in ROLES.items()}
ROLE_HSL = {k: hls(v) for k, v in ROLE_RGB.items()}


def assign(rgb):
    """-> (role or None, var name)"""
    hexs = '%02x%02x%02x' % rgb
    for k, v in ROLE_RGB.items():
        if v == rgb:
            return k, '--c-' + k
    mx, mn = max(rgb), min(rgb)
    chroma = (mx - mn) / 255
    h, s, l = hls(rgb)
    if chroma < 0.05:
        return None, '--c-fixed-' + hexs          # greys, white, black: never recoloured
    if l < 0.22:
        cands = ['bg', 'surface', 'paperText']
    elif l > 0.88:
        cands = ['text', 'paper']
    else:
        cands = ['muted', 'primary', 'accent', 'accent2', 'accent3', 'highlight', 'danger', 'paperAccent']

    def dist(k):
        rh, rs, rl = ROLE_HSL[k]
        dh = min(abs(rh - h), 360 - abs(rh - h)) / 180
        return dh * 2 + abs(rl - l) + abs(rs - s) * 0.5
    k = min(cands, key=dist)
    return k, '--c-' + k + '-' + hexs


VARS = {}   # name -> info
_map_path = os.path.join(REPO, 'backend/includes/palette_map.json')
if os.path.exists(_map_path):
    VARS.update(json.load(open(_map_path, encoding='utf-8')).get('vars', {}))   # keep existing entries


def var_for(rgb):
    role, name = assign(rgb)
    if name not in VARS:
        if role:
            rh, rs, rl = ROLE_HSL[role]
            h, s, l = hls(rgb)
            d = [round(((h - rh + 180) % 360) - 180, 3), round(s - rs, 4), round(l - rl, 4)]
        else:
            d = None
        VARS[name] = {'role': role, 'rgb': list(rgb), 'd': d}
    return name


COLOR_RE = re.compile(r'#[0-9a-fA-F]{3,8}\b|(?<![-\w])rgba?\(\s*[^)]*\)|(?<![-\w])(?:white|black)(?![-\w])')


def convert_color(tok):
    t = tok.strip()
    if t.startswith('#'):
        if len(t) - 1 not in (3, 4, 6, 8):
            return None
        rgb, a = hex_rgb(t)
    elif t.lower() in NAMED:
        rgb, a = NAMED[t.lower()], None
    else:
        inner = t[t.index('(') + 1:-1]
        if 'var(' in inner or 'calc' in inner:
            return None
        parts = [p for p in re.split(r'[\s,/]+', inner.strip()) if p]
        if len(parts) not in (3, 4) or any('%' in p for p in parts[:3]):
            return None
        rgb = tuple(int(round(float(p))) for p in parts[:3])
        a = None
        if len(parts) == 4:
            a = float(parts[3][:-1]) / 100 if parts[3].endswith('%') else float(parts[3])
    name = var_for(rgb)
    fb = '%d %d %d' % rgb
    if a is None:
        return 'rgb(var(%s, %s))' % (name, fb)
    a_txt = ('%.3f' % a).rstrip('0').rstrip('.') if a not in (0, 1) else str(int(a))
    return 'rgb(var(%s, %s) / %s)' % (name, fb, a_txt)


MASK_RE = re.compile(r'url\([^)]*\)|<\?.*?\?>', re.S)
stats = {'files': 0, 'colours': 0}


def convert_value(val):
    # keep url(...) and PHP tags untouched
    masks = []

    def mask(m):
        masks.append(m.group(0))
        return '\x00%d\x00' % (len(masks) - 1)
    v = MASK_RE.sub(mask, val)

    def rep(m):
        new = convert_color(m.group(0))
        if new is None:
            return m.group(0)
        stats['colours'] += 1
        return new
    v = COLOR_RE.sub(rep, v)
    return re.sub(r'\x00(\d+)\x00', lambda m: masks[int(m.group(1))], v)


DECL_RE = re.compile(r'(?<![\w-])(--[\w-]+|[a-zA-Z-]+)(\s*:\s*)([^;{}]*?)(?=\s*(?:;|}|$))')
SKIP_PROPS = {'content', 'font-family', 'src', 'grid-template-areas'}


def convert_css(text):
    def rep(m):
        prop = m.group(1).lower()
        if prop in SKIP_PROPS:
            return m.group(0)
        return m.group(1) + m.group(2) + convert_value(m.group(3))
    # only inside declaration blocks: process each {...} body separately (selectors are outside)
    out, depth, start, i = [], 0, 0, 0
    res = []
    buf_start = 0
    pieces = re.split(r'([{}])', text)
    for idx, piece in enumerate(pieces):
        if piece == '{' or piece == '}':
            res.append(piece)
            continue
        prev = pieces[idx - 1] if idx > 0 else ''
        nxt = pieces[idx + 1] if idx + 1 < len(pieces) else ''
        # a body is text after '{' (it may end with '}' or contain nested rules for @media -> handled since nested selectors end with '{')
        if prev == '{' or (prev == '}' and nxt == '}'):
            # inside a block: may still contain a nested selector (text before a '{'); only convert if it isn't followed by '{'
            if nxt == '{':
                # text like "color: red; } .x" can't happen here; it's a nested selector or @media prelude
                res.append(piece)
            else:
                res.append(DECL_RE.sub(rep, piece))
        else:
            res.append(piece)
    return ''.join(res)


def convert_attr(val):
    def rep(m):
        prop = m.group(1).lower()
        if prop in SKIP_PROPS:
            return m.group(0)
        return m.group(1) + m.group(2) + convert_value(m.group(3))
    return DECL_RE.sub(rep, val)


def convert_page(text):
    text = re.sub(r'(<style\b[^>]*>)(.*?)(</style>)', lambda m: m.group(1) + convert_css(m.group(2)) + m.group(3), text, flags=re.S | re.I)
    # style="..." attributes in HTML (not inside PHP code)
    def attr(m):
        if '<?' in m.group(2):
            return m.group(0)
        return m.group(1) + convert_attr(m.group(2)) + m.group(3)
    return re.sub(r'(\sstyle=")([^"]*)(")', attr, text)


changed = []
for f in CSS_FILES:
    p = os.path.join(REPO, f)
    s = open(p, encoding='utf-8', newline='').read()
    n = convert_css(s)
    if n != s:
        changed.append(f)
        if not DRY:
            open(p, 'w', encoding='utf-8', newline='').write(n)
for d, exts in PAGE_DIRS:
    for fn in sorted(os.listdir(os.path.join(REPO, d))):
        if not fn.endswith(exts) or fn in SKIP_PAGES:
            continue
        p = os.path.join(REPO, d, fn)
        s = open(p, encoding='utf-8', newline='').read()
        n = convert_page(s)
        if n != s:
            changed.append(d + '/' + fn)
            if not DRY:
                open(p, 'w', encoding='utf-8', newline='').write(n)

roles_out = {k: {'label': v[0], 'default': v[1], 'group': v[2]} for k, v in ROLES.items()}
by_role = {}
for name, info in VARS.items():
    by_role.setdefault(info['role'] or 'fixed', []).append(name)
print('files changed:', len(changed))
print('colour values converted:', stats['colours'])
print('variables:', len(VARS), {k: len(v) for k, v in by_role.items()})
if not DRY:
    out = {'roles': roles_out, 'vars': dict(sorted(VARS.items()))}
    json.dump(out, open(os.path.join(REPO, 'backend/includes/palette_map.json'), 'w', encoding='utf-8'), indent=1)
