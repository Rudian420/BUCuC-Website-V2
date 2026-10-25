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
    <title>Site Colours | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css" />
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
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
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

    <script type="application/json" id="theme-config-data"><?= json_encode([
        'roles' => array_map(fn($r) => ['label' => $r['label'], 'default' => $r['default']], $map['roles']),
        'vars' => $jsVars,
        'pairs' => PALETTE_PAIRS,
        'rotate' => PALETTE_ROTATE,
        'history' => array_map(fn($h) => ['roles' => $h['roles'], 'main' => $h['main'] ?? null], $history),
        'startRoles' => $startRoles,
        'main' => $current['main'] ?? $defaults['primary'],
        'manual' => !empty($current['manual']),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script src="js/theme-preview.js?v=<?= filemtime(__DIR__ . '/js/theme-preview.js') ?>"></script>
</body>

</html>
