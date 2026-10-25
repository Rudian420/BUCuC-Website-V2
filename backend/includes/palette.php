<?php
/**
 * Site colour palette (Admin → Site Colours). Loaded by auth.php.
 *
 * Every colour in the site's CSS is a variable with the original colour as its fallback, e.g.
 *   rgb(var(--c-primary, 139 92 255) / 0.8)
 * so with no saved palette nothing is injected and the site looks exactly as designed.
 * palette_map.json lists every variable: its palette role (or none = fixed grey/white/black) and its
 * HSL offset from that role's default colour. A palette is just the 13 role colours; every other
 * shade is worked out from its role. The saved palette is added to every page as
 *   <style id="site-palette">:root{--c-primary: 120 80 200; …}</style>
 * except on the Site Colours page itself (NO_SITE_PALETTE), which always uses the original colours.
 */

const PALETTE_HISTORY_SIZE = 5;
// Roles that follow the main colour when a palette is generated (warnings and gold keep their meaning)
const PALETTE_ROTATE = ['bg', 'surface', 'text', 'muted', 'primary', 'accent', 'accent2', 'accent3', 'paper', 'paperText', 'paperAccent'];
// Text / background pairs whose readability is checked: [label, text role, background role]
const PALETTE_PAIRS = [
    ['Main text on the page background', 'text', 'bg'],
    ['Secondary text on the page background', 'muted', 'bg'],
    ['Main text on panels and cards', 'text', 'surface'],
    ['Secondary text on panels and cards', 'muted', 'surface'],
    ['Main colour (links, highlights) on the background', 'primary', 'bg'],
    ['Teal accent text on the background', 'accent', 'bg'],
    ['Green accent text on the background', 'accent2', 'bg'],
    ['Gold highlight text on the background', 'highlight', 'bg'],
    ['Warning / error text on the background', 'danger', 'bg'],
    ['Dark button text on the green button colour', 'bg', 'accent2'],
    ['Dark button text on the teal button colour', 'bg', 'accent'],
    ['White band text on the white band', 'paperText', 'paper'],
    ['White band accent on the white band', 'paperAccent', 'paper'],
];

function palette_map()
{
    static $map = null;
    if ($map === null) {
        $map = json_decode((string) @file_get_contents(__DIR__ . '/palette_map.json'), true) ?: ['roles' => [], 'vars' => []];
    }
    return $map;
}

function palette_defaults()
{
    return array_map(fn($r) => $r['default'], palette_map()['roles']);
}

/* ------------------------------------------------------------------ colour maths */

function palette_hex_rgb($hex)
{
    $h = ltrim((string) $hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return null;
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function palette_rgb_hex(array $rgb)
{
    return sprintf('#%02x%02x%02x', ...array_map(fn($c) => max(0, min(255, (int) round($c))), $rgb));
}

/** RGB 0–255 → [h 0–360, s 0–1, l 0–1] (same formulas as Python's colorsys.rgb_to_hls). */
function palette_rgb_hsl(array $rgb)
{
    [$r, $g, $b] = array_map(fn($c) => $c / 255, $rgb);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max == $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l <= 0.5 ? $d / ($max + $min) : $d / (2 - $max - $min);
    if ($max == $r) $h = (($g - $b) / $d);
    elseif ($max == $g) $h = 2 + ($b - $r) / $d;
    else $h = 4 + ($r - $g) / $d;
    $h = fmod($h * 60 + 360, 360);
    return [$h, $s, $l];
}

function palette_hsl_rgb($h, $s, $l)
{
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    $s = max(0, min(1, $s));
    $l = max(0, min(1, $l));
    if ($s == 0) return [$l * 255, $l * 255, $l * 255];
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function ($t) use ($p, $q) {
        $t = fmod($t + 1, 1);
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };
    return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
}

/** WCAG contrast ratio between two hex colours (1–21). */
function palette_contrast($a, $b)
{
    $lum = function ($hex) {
        $c = array_map(function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }, palette_hex_rgb($hex) ?? [0, 0, 0]);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $x = $lum($a);
    $y = $lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/* ------------------------------------------------------------------ palettes */

/** Cleans a submitted role → hex list; unknown roles are dropped, missing ones get the default. */
function palette_clean(array $roles)
{
    $out = [];
    foreach (palette_defaults() as $key => $def) {
        $rgb = palette_hex_rgb($roles[$key] ?? '');
        $out[$key] = $rgb ? palette_rgb_hex($rgb) : $def;
    }
    return $out;
}

/** Relative luminance (WCAG) of an RGB colour. */
function palette_luminance(array $rgb)
{
    $c = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }, $rgb);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** Colourfulness (chroma, 0–1) of an RGB colour. */
function palette_chroma(array $rgb)
{
    return (max($rgb) - min($rgb)) / 255;
}

/**
 * New hue, same brightness and no more colourful than the original shade (so near-whites and
 * near-blacks stay near-neutral, accents stay vivid).
 */
function palette_rehue(array $rgb, $newHue)
{
    [, $s] = palette_rgb_hsl($rgb);
    $y = palette_luminance($rgb);
    $c0 = palette_chroma($rgb);
    $out = palette_match_luminance($newHue, $s, $y);
    if (palette_chroma($out) > $c0 * 1.1) {
        $lo = 0.0;
        $hi = $s;
        for ($i = 0; $i < 25; $i++) {
            $mid = ($lo + $hi) / 2;
            if (palette_chroma(palette_match_luminance($newHue, $mid, $y)) > $c0 * 1.1) $hi = $mid; else $lo = $mid;
        }
        $out = palette_match_luminance($newHue, $lo, $y);
    }
    return $out;
}

/** The colour with hue h and saturation s whose luminance is closest to $target (lightness found by bisection). */
function palette_match_luminance($h, $s, $target)
{
    $lo = 0.0;
    $hi = 1.0;
    for ($i = 0; $i < 30; $i++) {
        $mid = ($lo + $hi) / 2;
        if (palette_luminance(palette_hsl_rgb($h, $s, $mid)) < $target) $lo = $mid; else $hi = $mid;
    }
    return palette_hsl_rgb($h, $s, ($lo + $hi) / 2);
}

/**
 * A full palette from one main colour. Every role takes the new hue but keeps the brightness
 * (luminance) and at most the colourfulness it has in the original design, so text stays as
 * readable as before and neutrals stay neutral. The main colour
 * itself is used as picked unless it would be hard to read on the background (contrast < 4.5);
 * then only its lightness is moved until it is readable.
 */
function palette_generate($mainHex)
{
    $defaults = palette_defaults();
    $main = palette_hex_rgb($mainHex) ?? palette_hex_rgb($defaults['primary']);
    $dh = palette_rgb_hsl($main)[0] - palette_rgb_hsl(palette_hex_rgb($defaults['primary']))[0];
    $out = [];
    foreach ($defaults as $key => $def) {
        $rgb = palette_hex_rgb($def);
        if (in_array($key, PALETTE_ROTATE, true) && $key !== 'primary') {
            $rgb = palette_rehue($rgb, palette_rgb_hsl($rgb)[0] + $dh);
        }
        $out[$key] = palette_rgb_hex($rgb);
    }
    // Main colour: as picked, unless unreadable on the new background
    [$h, $s, $l] = palette_rgb_hsl($main);
    $bg = $out['bg'];
    $darkBg = palette_luminance(palette_hex_rgb($bg)) < 0.18;
    $pick = $main;
    if (palette_contrast(palette_rgb_hex($pick), $bg) < 4.5) {
        $lo = $l;
        $hi = $darkBg ? 1.0 : 0.0;
        for ($i = 0; $i < 30; $i++) {
            $mid = ($lo + $hi) / 2;
            if (palette_contrast(palette_rgb_hex(palette_hsl_rgb($h, $s, $mid)), $bg) >= 4.5) $hi = $mid; else $lo = $mid;
        }
        $pick = palette_hsl_rgb($h, $s, $hi);
    }
    $out['primary'] = palette_rgb_hex($pick);
    return $out;
}

/** Every CSS variable for a palette: name => "R G B" (fixed greys are left out: they never change). */
function palette_vars(array $roles)
{
    $roles = palette_clean($roles);
    $defaults = palette_defaults();
    $vars = [];
    foreach (palette_map()['vars'] as $name => $v) {
        if (empty($v['role'])) continue;
        $role = $v['role'];
        if ($roles[$role] === $defaults[$role]) {
            $rgb = $v['rgb'];   // role unchanged: keep the exact original shade
        } else {
            [$h, $s, $l] = palette_rgb_hsl(palette_hex_rgb($roles[$role]));
            [$dh, $ds, $dl] = $v['d'];
            $rgb = $name === '--c-' . $role ? palette_hex_rgb($roles[$role]) : palette_hsl_rgb($h + $dh, $s + $ds, $l + $dl);
        }
        $vars[$name] = implode(' ', array_map(fn($c) => (int) round($c), $rgb));
    }
    return $vars;
}

function palette_css(array $roles)
{
    $out = [];
    foreach (palette_vars($roles) as $name => $val) $out[] = "$name: $val";
    return ':root{' . implode(';', $out) . '}';
}

/** Readability of every checked pair: [label, text hex, bg hex, ratio, level ok|low|bad]. */
function palette_contrast_report(array $roles)
{
    $roles = palette_clean($roles);
    $rows = [];
    foreach (PALETTE_PAIRS as [$label, $fg, $bg]) {
        $ratio = palette_contrast($roles[$fg], $roles[$bg]);
        $rows[] = ['label' => $label, 'fg' => $roles[$fg], 'bg' => $roles[$bg], 'ratio' => round($ratio, 2),
            'level' => $ratio >= 4.5 ? 'ok' : ($ratio >= 3 ? 'low' : 'bad')];
    }
    return $rows;
}

/* ------------------------------------------------------------------ saved palette */

/** The saved palette: ['roles' => [...], 'main' => hex|null, 'manual' => bool, 'saved_at', 'saved_by'] or null (original colours). */
function palette_current()
{
    $p = json_decode((string) setting_get('palette', ''), true);
    return (is_array($p) && !empty($p['roles'])) ? $p : null;
}

function palette_history()
{
    $h = json_decode((string) setting_get('palette_history', ''), true);
    return is_array($h) ? array_slice($h, 0, PALETTE_HISTORY_SIZE) : [];
}

/** Saves a palette as the site palette and keeps it in the last-5 history. */
function palette_save(array $roles, $main, $manual)
{
    $entry = ['roles' => palette_clean($roles), 'main' => $main ? palette_rgb_hex(palette_hex_rgb($main) ?? [0, 0, 0]) : null,
        'manual' => (bool) $manual, 'saved_at' => date('Y-m-d H:i:s'), 'saved_by' => $_SESSION['username'] ?? '',
        'saved_by_role' => $_SESSION['admin_role'] ?? null, 'saved_by_id' => $_SESSION['admin_id'] ?? null];
    setting_set('palette', json_encode($entry));
    setting_set('palette_css', palette_css($entry['roles']));
    $hist = array_values(array_filter(palette_history(), fn($h) => $h['roles'] !== $entry['roles']));
    array_unshift($hist, $entry);
    setting_set('palette_history', json_encode(array_slice($hist, 0, PALETTE_HISTORY_SIZE)));
    return $entry;
}

/** Back to the original colours (the history is kept). */
function palette_reset()
{
    setting_set('palette', '');
    setting_set('palette_css', '');
}

/** Output-buffer callback: adds the saved palette to every HTML page (not to the Site Colours page). */
function palette_inject($html)
{
    if (defined('NO_SITE_PALETTE') || stripos($html, '</head>') === false) return $html;
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    }
    // Bootstrap's stock buttons/alerts read the palette variables (identical to Bootstrap until a palette is saved)
    static $bootstrap = null;
    if ($bootstrap === null) $bootstrap = (string) @file_get_contents(__DIR__ . '/palette_bootstrap.css');
    $css = (string) setting_get('palette_css', '') . $bootstrap;
    if ($css === '') return $html;
    return preg_replace('/<\/head>/i', '<style id="site-palette">' . str_replace('</', '<\/', $css) . "</style>\n</head>", $html, 1);
}
