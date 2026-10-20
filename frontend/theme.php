<?php
// The Site Colours page always uses the original colours, so a bad palette can never make the way back unreadable.
define('NO_SITE_PALETTE', true);
require_once '../backend/includes/auth.php';

// Site colour palette: Super Admin, Panel Admin, Secretary Admin (HR). Only colours change; layout,
// fonts and spacing stay as designed. Nothing changes for visitors until "Save palette".
require_admin(['can' => 'theme', 'csrf_redirect' => 'theme.php']);

$message = $_GET['success'] ?? '';
$error = '';

function palette_summary(array $roles)
{
    $bad = count(array_filter(palette_contrast_report($roles), fn($r) => $r['level'] === 'bad'));
    $low = count(array_filter(palette_contrast_report($roles), fn($r) => $r['level'] === 'low'));
    return $bad || $low ? "readability: $bad hard to read, $low may be hard to read" : 'readability OK';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $roles = json_decode((string) ($_POST['roles'] ?? ''), true);
        if (!is_array($roles)) {
            $error = 'The colours could not be read. Try again.';
        } else {
            $roles = palette_clean($roles);
            $main = $_POST['main'] ?? null;
            $manual = !empty($_POST['manual']);
            palette_save($roles, $main, $manual);
            $changed = array_keys(array_diff_assoc($roles, palette_defaults()));
            log_activity('palette_saved', ($main ? "Main colour $main" : 'No main colour') . ($manual ? ', edited by hand' : ', generated')
                . '; ' . count($changed) . ' of ' . count($roles) . ' colours differ from the original; ' . palette_summary($roles), null, null, 'Site colours');
            header('Location: theme.php?success=' . urlencode('The new colours are live on the website and the admin panel.'));
            exit();
        }
    } elseif ($action === 'reset') {
        palette_reset();
        log_activity('palette_reset', 'Back to the original colours', null, null, 'Site colours');
        header('Location: theme.php?success=' . urlencode('The original colours are back.'));
        exit();
    } elseif ($action === 'restore') {
        $hist = palette_history();
        $i = (int) ($_POST['index'] ?? -1);
        if (!isset($hist[$i])) {
            $error = 'That saved palette no longer exists.';
        } else {
            $h = $hist[$i];
            palette_save($h['roles'], $h['main'] ?? null, $h['manual'] ?? false);
            log_activity('palette_restored', 'Palette from ' . date('j M Y, g:i a', strtotime($h['saved_at'])) . ' (saved by ' . admin_shown_name($h['saved_by'] ?? '', $h['saved_by_role'] ?? null, $h['saved_by_id'] ?? null) . '); ' . palette_summary($h['roles']), null, null, 'Site colours');
            header('Location: theme.php?success=' . urlencode('That saved palette is live again.'));
            exit();
        }
    }
}

$current = palette_current();
$defaults = palette_defaults();
$startRoles = $current['roles'] ?? $defaults;
$history = palette_history();
$map = palette_map();
$jsVars = [];
foreach ($map['vars'] as $name => $v) {
    if (!empty($v['role'])) $jsVars[$name] = [$v['role'], $v['rgb'], $v['d']];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Colours - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        /* This page uses fixed colours on purpose (never the chosen palette) */
        body.theme-page { background: #06040f !important; color: #f3f0ff; }
        .wrapx { padding: 2rem 1rem; color: #f3f0ff; max-width: 1240px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .back-btn { text-decoration: none; color: #f3f0ff; padding: .5rem 1rem; border: 1px solid rgba(255, 255, 255, .3); border-radius: 999px; }
        .back-btn:hover { color: #fff; background: rgba(255, 255, 255, .08); }
        .panel { background: #120c2a; border: 1px solid rgba(255, 255, 255, .16); border-radius: 18px; padding: 1.4rem; margin-bottom: 1.25rem; }
        .panel h4 { color: #f3f0ff; font-size: 1.15rem; margin-bottom: .8rem; }
        .note { color: #b9b3dc; font-size: .85rem; }
        .grid2 { display: grid; grid-template-columns: minmax(0, 420px) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
        .role { display: grid; grid-template-columns: 44px 1fr 96px; gap: .6rem; align-items: center; padding: .45rem 0; border-bottom: 1px solid rgba(255, 255, 255, .07); }
        .role input[type=color] { width: 44px; height: 34px; padding: 0; border: 1px solid rgba(255, 255, 255, .3); border-radius: 8px; background: none; cursor: pointer; }
        .role .hex { background: #0b0718; border: 1px solid rgba(255, 255, 255, .25); color: #f3f0ff; border-radius: 8px; padding: .3rem .5rem; font: 13px ui-monospace, Consolas, monospace; width: 96px; }
        .role label { font-size: .9rem; }
        .main-pick { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .main-pick input[type=color] { width: 72px; height: 72px; border: 2px solid rgba(255, 255, 255, .5); border-radius: 50%; padding: 0; cursor: pointer; background: none; }
        .main-pick input[type=color]::-webkit-color-swatch-wrapper { padding: 0; }
        .main-pick input[type=color]::-webkit-color-swatch { border: none; border-radius: 50%; }
        .btn-fixed { border-radius: 999px; font-weight: 700; padding: .55rem 1.2rem; border: 2px solid transparent; }
        .btn-save { background: #5cffb0; color: #06040f; }
        .btn-save:hover { background: #8dffc9; color: #06040f; }
        .btn-reset { background: #ffffff; color: #06040f; border-color: #ffffff; }
        .btn-reset:hover { background: #e9e5ff; color: #06040f; }
        .btn-plain { background: transparent; color: #f3f0ff; border-color: rgba(255, 255, 255, .4); }
        .btn-plain:hover { background: rgba(255, 255, 255, .08); color: #fff; }
        .warn { display: none; padding: .8rem 1rem; border-radius: 12px; background: #3a2405; border: 1px solid #ffb347; color: #ffd9a3; margin-bottom: 1rem; }
        .warn.show { display: block; }
        .bad-box { background: #3d0d1c; border-color: #ff6b8f; color: #ffc4d3; }
        .pairs { width: 100%; font-size: .86rem; border-collapse: collapse; }
        .pairs td { padding: .4rem .3rem; border-bottom: 1px solid rgba(255, 255, 255, .07); vertical-align: middle; }
        .sample { display: inline-block; min-width: 44px; text-align: center; padding: .15rem .45rem; border-radius: 6px; font-weight: 700; border: 1px solid rgba(255, 255, 255, .2); }
        .lvl { font-weight: 700; white-space: nowrap; }
        .lvl.ok { color: #5cffb0; }
        .lvl.low { color: #ffcf7d; }
        .lvl.bad { color: #ff93b4; }
        /* Preview rows: real pages laid out at desktop (1366 px) or phone (390 px) width, scaled to fit */
        .pv-row + .pv-row { margin-top: 1.25rem; }
        .pv-head { display: flex; justify-content: space-between; align-items: center; gap: .75rem; margin-bottom: .5rem; flex-wrap: wrap; }
        .pv-head b { font-size: .95rem; }
        .seg { display: inline-flex; border: 1px solid rgba(255, 255, 255, .35); border-radius: 999px; overflow: hidden; }
        .seg button { background: transparent; color: #f3f0ff; border: 0; padding: .35rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; }
        .seg button.on { background: #f3f0ff; color: #06040f; }
        .seg button:focus-visible { outline: 2px solid #5cffb0; outline-offset: -2px; }
        .pv-stage { position: relative; height: 480px; overflow: hidden; border: 1px solid rgba(255, 255, 255, .25); border-radius: 12px; background: #000; }
        .pv-stage.mobile { background: #1a1530; }
        .pv-stage iframe { position: absolute; top: 0; left: 0; border: 0; transform-origin: 0 0; background: #000; }
        .pv-stage.mobile iframe { border-radius: 18px; box-shadow: 0 0 0 6px #2a2347; }
        .pv-size { font-size: .75rem; color: #b9b3dc; }
        .hist-row { display: flex; align-items: center; gap: .8rem; padding: .55rem 0; border-bottom: 1px solid rgba(255, 255, 255, .07); flex-wrap: wrap; }
        .strip { display: flex; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255, 255, 255, .25); }
        .strip span { width: 18px; height: 26px; display: block; }
        .alert-fixed { padding: .8rem 1rem; border-radius: 12px; margin-bottom: 1rem; }
        .alert-ok { background: #0d3324; border: 1px solid #5cffb0; color: #c6ffe3; }
        .alert-err { background: #3d0d1c; border: 1px solid #ff6b8f; color: #ffc4d3; }
        .status { display: flex; gap: .8rem; align-items: center; flex-wrap: wrap; }
        @media (max-width: 960px) { .grid2 { grid-template-columns: 1fr; } .pv-stage { height: 420px; } }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
</head>

<body class="theme-page">
    <div class="container wrapx">
        <div class="page-header">
            <h2 class="m-0" style="color:#f3f0ff"><i class="fas fa-palette me-2"></i>Site Colours</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
        <?php if ($message): ?><div class="alert-fixed alert-ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-fixed alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="panel status">
            <div style="flex:1;min-width:240px">
                <b>Now on the website:</b>
                <?php if ($current): ?>
                    a custom palette, saved by <?= htmlspecialchars(admin_shown_name($current['saved_by'] ?? '', $current['saved_by_role'] ?? null, $current['saved_by_id'] ?? null)) ?> on <?= date('j M Y, g:i a', strtotime($current['saved_at'])) ?>.
                <?php else: ?>
                    the original colours.
                <?php endif; ?>
                <div class="note">Only colours change. This page always uses the original colours, so you can always get back here and reset.</div>
            </div>
            <form method="POST" action="theme.php" onsubmit="return confirm('Go back to the original colours for everyone?')">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn btn-fixed btn-reset"><i class="fas fa-undo me-2"></i>Reset to original colours</button>
            </form>
        </div>

        <div class="grid2">
            <div>
                <div class="panel">
                    <h4>1. Pick a main colour</h4>
                    <div class="main-pick">
                        <input type="color" id="mainColor" value="<?= htmlspecialchars($current['main'] ?? $defaults['primary']) ?>" aria-label="Main colour">
                        <div>
                            <div class="note">The whole palette is made from this colour: backgrounds, text, buttons, accents, borders and hover colours follow it.</div>
                            <button type="button" class="btn btn-fixed btn-plain btn-sm mt-2" id="generateBtn"><i class="fas fa-magic me-1"></i>Make palette from this colour</button>
                            <div class="note mt-2" id="adjustNote" style="display:none;color:#ffd9a3"><i class="fas fa-info-circle me-1"></i>Your main colour was made a little lighter or darker so text in that colour stays readable on the background.</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <h4>2. Adjust colours (optional)</h4>
                    <div class="warn" id="manualWarn"><i class="fas fa-exclamation-triangle me-2"></i>You changed colours by hand. The result might not look good together: check the preview and the readability list before saving.</div>
                    <div id="roles"></div>
                </div>

                <div class="panel">
                    <h4>3. Readability</h4>
                    <div class="warn bad-box" id="contrastWarn"></div>
                    <table class="pairs" id="pairs"></table>
                    <p class="note mt-2 mb-0">Ratios of 4.5 or more are easy to read; below 3 is hard to read.</p>
                </div>

                <form method="POST" action="theme.php" id="saveForm" class="panel">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="roles" id="fRoles">
                    <input type="hidden" name="main" id="fMain">
                    <input type="hidden" name="manual" id="fManual">
                    <p class="note">Nothing changes for anyone until you save.</p>
                    <button type="submit" class="btn btn-fixed btn-save"><i class="fas fa-save me-2"></i>Save palette</button>
                    <button type="button" class="btn btn-fixed btn-plain ms-2" id="startOver">Start from the original colours</button>
                </form>
            </div>

            <div>
                <div class="panel">
                    <h4>Live preview <span class="note">(only you see this; scroll inside each page)</span></h4>
                    <div class="pv-row" id="rowPublic">
                        <div class="pv-head">
                            <b>Public website</b>
                            <span class="pv-size"></span>
                            <div class="seg" role="group" aria-label="Public website preview size">
                                <button type="button" data-mode="desktop" class="on" aria-pressed="true">Desktop</button>
                                <button type="button" data-mode="mobile" aria-pressed="false">Mobile</button>
                            </div>
                        </div>
                        <div class="pv-stage"><iframe id="pvPublic" src="index.php" sandbox="allow-same-origin allow-scripts" title="Public website preview"></iframe></div>
                    </div>
                    <div class="pv-row" id="rowAdmin">
                        <div class="pv-head">
                            <b>Admin panel</b>
                            <span class="pv-size"></span>
                            <div class="seg" role="group" aria-label="Admin panel preview size">
                                <button type="button" data-mode="desktop" class="on" aria-pressed="true">Desktop</button>
                                <button type="button" data-mode="mobile" aria-pressed="false">Mobile</button>
                            </div>
                        </div>
                        <div class="pv-stage"><iframe id="pvAdmin" src="<?= auth_dashboard_page() ?>" sandbox="allow-same-origin allow-scripts" title="Admin panel preview"></iframe></div>
                    </div>
                </div>

                <div class="panel">
                    <h4>Last <?= PALETTE_HISTORY_SIZE ?> saved palettes</h4>
                    <?php if (!$history): ?>
                        <p class="note m-0">No palettes saved yet.</p>
                    <?php endif; ?>
                    <?php foreach ($history as $i => $h): ?>
                        <div class="hist-row">
                            <div class="strip"><?php foreach ($h['roles'] as $hex): ?><span style="background:<?= htmlspecialchars($hex) ?>"></span><?php endforeach; ?></div>
                            <div style="flex:1;min-width:180px"><?= date('j M Y, g:i a', strtotime($h['saved_at'])) ?> <span class="note">· <?= htmlspecialchars(admin_shown_name($h['saved_by'] ?? '', $h['saved_by_role'] ?? null, $h['saved_by_id'] ?? null)) ?><?= !empty($h['manual']) ? ' · edited by hand' : '' ?><?= $current && $current['roles'] === $h['roles'] ? ' · <b style="color:#5cffb0">live now</b>' : '' ?></span></div>
                            <button type="button" class="btn btn-fixed btn-plain btn-sm" data-try="<?= $i ?>">Preview</button>
                            <form method="POST" action="theme.php" onsubmit="return confirm('Make this palette live for everyone?')">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="index" value="<?= $i ?>">
                                <button type="submit" class="btn btn-fixed btn-plain btn-sm">Restore</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        const ROLES = <?= json_encode(array_map(fn($r) => ['label' => $r['label'], 'default' => $r['default']], $map['roles'])) ?>;
        const VARS = <?= json_encode($jsVars) ?>;            // name: [role, original rgb, [dh, ds, dl]]
        const PAIRS = <?= json_encode(PALETTE_PAIRS) ?>;
        const ROTATE = <?= json_encode(PALETTE_ROTATE) ?>;
        const HISTORY = <?= json_encode(array_map(fn($h) => ['roles' => $h['roles'], 'main' => $h['main'] ?? null], $history)) ?>;
        let roles = <?= json_encode($startRoles) ?>;
        let main = <?= json_encode($current['main'] ?? $defaults['primary']) ?>;
        let manual = <?= !empty($current['manual']) ? 'true' : 'false' ?>;

        // ---- colour maths (same formulas as backend/includes/palette.php)
        const hexRgb = h => { h = h.replace('#', ''); if (h.length === 3) h = h.split('').map(c => c + c).join(''); return [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16)); };
        const rgbHex = rgb => '#' + rgb.map(c => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0')).join('');
        function rgbHsl([r, g, b]) {
            r /= 255; g /= 255; b /= 255;
            const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
            if (max === min) return [0, 0, l];
            const d = max - min, s = l <= 0.5 ? d / (max + min) : d / (2 - max - min);
            let h = max === r ? (g - b) / d : max === g ? 2 + (b - r) / d : 4 + (r - g) / d;
            return [((h * 60) % 360 + 360) % 360, s, l];
        }
        function hslRgb(h, s, l) {
            h = (((h % 360) + 360) % 360) / 360; s = Math.max(0, Math.min(1, s)); l = Math.max(0, Math.min(1, l));
            if (s === 0) return [l * 255, l * 255, l * 255];
            const q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
            const f = t => { t = ((t % 1) + 1) % 1; return t < 1 / 6 ? p + (q - p) * 6 * t : t < 1 / 2 ? q : t < 2 / 3 ? p + (q - p) * (2 / 3 - t) * 6 : p; };
            return [f(h + 1 / 3) * 255, f(h) * 255, f(h - 1 / 3) * 255];
        }
        function contrast(a, b) {
            const lum = hex => { const c = hexRgb(hex).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
            const x = lum(a), y = lum(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        }
        const lumRgb = rgb => { const c = rgb.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
        function matchLum(h, s, target) {
            let lo = 0, hi = 1;
            for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (lumRgb(hslRgb(h, s, mid)) < target) lo = mid; else hi = mid; }
            return hslRgb(h, s, (lo + hi) / 2);
        }
        const chroma = rgb => (Math.max(...rgb) - Math.min(...rgb)) / 255;
        function rehue(rgb, newHue) {
            const s0 = rgbHsl(rgb)[1], y = lumRgb(rgb), c0 = chroma(rgb);
            let out = matchLum(newHue, s0, y);
            if (chroma(out) > c0 * 1.1) {
                let lo = 0, hi = s0;
                for (let i = 0; i < 25; i++) { const mid = (lo + hi) / 2; if (chroma(matchLum(newHue, mid, y)) > c0 * 1.1) hi = mid; else lo = mid; }
                out = matchLum(newHue, lo, y);
            }
            return out;
        }
        // Same rules as palette_generate() in palette.php: new hue, original brightness; main colour as picked unless unreadable
        let mainAdjusted = false;
        function generate(mainHex) {
            const m = hexRgb(mainHex);
            const dh = rgbHsl(m)[0] - rgbHsl(hexRgb(ROLES.primary.default))[0];
            const out = {};
            for (const [k, r] of Object.entries(ROLES)) {
                let rgb = hexRgb(r.default);
                if (ROTATE.includes(k) && k !== 'primary') rgb = rehue(rgb, rgbHsl(rgb)[0] + dh);
                out[k] = rgbHex(rgb);
            }
            const [h, s, l] = rgbHsl(m);
            const bg = out.bg, darkBg = lumRgb(hexRgb(bg)) < 0.18;
            let pick = m;
            mainAdjusted = false;
            if (contrast(rgbHex(pick), bg) < 4.5) {
                let lo = l, hi = darkBg ? 1 : 0;
                for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (contrast(rgbHex(hslRgb(h, s, mid)), bg) >= 4.5) hi = mid; else lo = mid; }
                pick = hslRgb(h, s, hi);
                mainAdjusted = true;
            }
            out.primary = rgbHex(pick);
            return out;
        }
        function cssVars(rs) {
            const out = {};
            for (const [name, [role, rgb, d]] of Object.entries(VARS)) {
                if (rs[role].toLowerCase() === ROLES[role].default.toLowerCase()) { out[name] = rgb.join(' '); continue; }
                if (name === '--c-' + role) { out[name] = hexRgb(rs[role]).join(' '); continue; }
                const [h, s, l] = rgbHsl(hexRgb(rs[role]));
                out[name] = hslRgb(h + d[0], s + d[1], l + d[2]).map(Math.round).join(' ');
            }
            return out;
        }

        // ---- page
        const rolesBox = document.getElementById('roles');
        for (const [k, r] of Object.entries(ROLES)) {
            const row = document.createElement('div');
            row.className = 'role';
            row.innerHTML = `<input type="color" data-k="${k}" aria-label="${r.label}"><label>${r.label}</label><input class="hex" data-k="${k}" maxlength="7" aria-label="${r.label} hex">`;
            rolesBox.appendChild(row);
        }
        function paint() {
            document.querySelectorAll('#roles input').forEach(i => { if (document.activeElement !== i) i.value = roles[i.dataset.k]; });
            document.getElementById('manualWarn').classList.toggle('show', manual);
            document.getElementById('adjustNote').style.display = mainAdjusted && !manual ? 'block' : 'none';
            // readability
            let bad = 0, low = 0;
            document.getElementById('pairs').innerHTML = PAIRS.map(([label, fg, bg]) => {
                const r = contrast(roles[fg], roles[bg]);
                const lvl = r >= 4.5 ? 'ok' : r >= 3 ? 'low' : 'bad';
                if (lvl === 'bad') bad++; if (lvl === 'low') low++;
                return `<tr><td><span class="sample" style="color:${roles[fg]};background:${roles[bg]}">Aa</span></td><td>${label}</td><td class="lvl ${lvl}">${r.toFixed(1)} · ${lvl === 'ok' ? 'Easy to read' : lvl === 'low' ? 'May be hard to read' : 'Hard to read'}</td></tr>`;
            }).join('');
            const cw = document.getElementById('contrastWarn');
            cw.classList.toggle('show', bad + low > 0);
            cw.innerHTML = bad ? `<i class="fas fa-exclamation-circle me-2"></i><b>${bad} text colour${bad > 1 ? 's are' : ' is'} hard to read</b> on ${bad > 1 ? 'their' : 'its'} background${low ? `, and ${low} more may be hard to read` : ''}. Visitors may not be able to read parts of the site.`
                : `<i class="fas fa-exclamation-triangle me-2"></i>${low} text colour${low > 1 ? 's' : ''} may be hard to read. Check the list below.`;
            preview();
        }
        function applyTo(frame) {
            try {
                const doc = frame.contentDocument;
                if (!doc || !doc.documentElement) return;
                const v = cssVars(roles);
                for (const [k, val] of Object.entries(v)) doc.documentElement.style.setProperty(k, val);
                frame.contentWindow.dispatchEvent(new Event('resize'));   // the animated sky redraws with the new colours
            } catch (e) { /* not loaded yet */ }
        }
        function preview() { ['pvPublic', 'pvAdmin'].forEach(id => applyTo(document.getElementById(id))); }
        ['pvPublic', 'pvAdmin'].forEach(id => document.getElementById(id).addEventListener('load', e => applyTo(e.target)));

        // Desktop / Mobile per row: the page is laid out at the real width (so its own phone layout
        // kicks in) and scaled down to fit the row. Each row has its own switch.
        const SIZES = { desktop: [1366, 900], mobile: [390, 844] };
        function fitRow(row) {
            const stage = row.querySelector('.pv-stage'), frame = row.querySelector('iframe');
            const mode = row.dataset.mode || 'desktop';
            const [w] = SIZES[mode];
            const pad = mode === 'mobile' ? 16 : 0;
            const scale = Math.min(1, (stage.clientWidth - pad * 2) / w);
            frame.style.width = w + 'px';
            frame.style.height = Math.round((stage.clientHeight - pad * 2) / scale) + 'px';
            frame.style.transform = `scale(${scale})`;
            frame.style.left = (mode === 'mobile' ? Math.max(pad, (stage.clientWidth - w * scale) / 2) : 0) + 'px';
            frame.style.top = pad + 'px';
            stage.classList.toggle('mobile', mode === 'mobile');
            row.querySelector('.pv-size').textContent = `${w} px wide, shown at ${Math.round(scale * 100)}%`;
        }
        document.querySelectorAll('.pv-row').forEach(row => {
            row.dataset.mode = 'desktop';
            row.querySelectorAll('.seg button').forEach(b => b.addEventListener('click', () => {
                row.dataset.mode = b.dataset.mode;
                row.querySelectorAll('.seg button').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                fitRow(row);
            }));
            fitRow(row);
        });
        window.addEventListener('resize', () => document.querySelectorAll('.pv-row').forEach(fitRow));

        document.getElementById('mainColor').addEventListener('input', e => { main = e.target.value; roles = generate(main); manual = false; paint(); });
        document.getElementById('generateBtn').addEventListener('click', () => { main = document.getElementById('mainColor').value; roles = generate(main); manual = false; paint(); });
        rolesBox.addEventListener('input', e => {
            const k = e.target.dataset.k; let v = e.target.value.trim();
            if (e.target.classList.contains('hex')) { if (!/^#?[0-9a-fA-F]{6}$/.test(v)) return; if (v[0] !== '#') v = '#' + v; }
            roles[k] = v.toLowerCase(); manual = true; paint();
        });
        document.getElementById('startOver').addEventListener('click', () => {
            roles = Object.fromEntries(Object.entries(ROLES).map(([k, r]) => [k, r.default]));
            main = ROLES.primary.default; manual = false;
            document.getElementById('mainColor').value = main; paint();
        });
        document.querySelectorAll('[data-try]').forEach(b => b.addEventListener('click', () => {
            const h = HISTORY[+b.dataset.try]; roles = { ...h.roles }; main = h.main || ROLES.primary.default; manual = false;
            document.getElementById('mainColor').value = main; paint();
        }));
        document.getElementById('saveForm').addEventListener('submit', e => {
            const bad = PAIRS.filter(([, fg, bg]) => contrast(roles[fg], roles[bg]) < 3).length;
            const msg = (bad ? `${bad} text colour${bad > 1 ? 's are' : ' is'} hard to read with these colours.\n\n` : '') + 'Make these colours live for everyone (website and admin panel)?';
            if (!confirm(msg)) { e.preventDefault(); return; }
            document.getElementById('fRoles').value = JSON.stringify(roles);
            document.getElementById('fMain').value = main;
            document.getElementById('fManual').value = manual ? '1' : '';
        });
        paint();
    </script>
</body>

</html>
