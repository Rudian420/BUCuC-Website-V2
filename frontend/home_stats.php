<?php
require_once '../backend/includes/auth.php';

// Homepage stats: Super Admin, Panel Admin and Secretary Admin (HR).
// All four numbers and the semester label are typed in here; nothing is calculated from the database.
require_admin(['can' => 'home_stats', 'csrf_redirect' => 'home_stats.php']);

$message = $_GET['success'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = home_stats_settings();
    $new = ['semester' => mb_substr(trim((string) ($_POST['semester'] ?? '')), 0, 40)];
    $changes = [];
    foreach (HOME_STATS as $key => $def) {
        $raw = trim((string) ($_POST[$key]['value'] ?? ''));
        if (!preg_match('/^\d{1,7}$/', $raw)) {
            $error = "\"{$def['label']}\" must be a whole number.";
            break;
        }
        $value = (int) $raw;
        $visible = !empty($_POST[$key]['visible']);
        $new[$key] = ['value' => $value, 'visible' => $visible];
        if ($before[$key]['value'] !== $value) $changes[] = "{$def['label']}: " . number_format($value) . ' (was ' . number_format($before[$key]['value']) . ')';
        if ($before[$key]['visible'] !== $visible) $changes[] = $def['label'] . ($visible ? ' shown' : ' hidden');
    }
    if (!$error) {
        if ($before['semester'] !== $new['semester']) $changes[] = 'Semester label: "' . $new['semester'] . '" (was "' . $before['semester'] . '")';
        setting_set('home_stats', json_encode($new, JSON_UNESCAPED_UNICODE));
        if ($changes) log_activity('home_stats_changed', implode('; ', $changes), null, null, 'Homepage stats');
        header('Location: home_stats.php?success=' . urlencode($changes ? 'Homepage stats saved.' : 'Nothing changed.'));
        exit();
    }
}

$settings = home_stats_settings();
if ($error) {
    // Show what was typed, not the saved values, so nothing has to be re-entered
    $settings['semester'] = (string) ($_POST['semester'] ?? '');
    foreach (HOME_STATS as $key => $def) {
        $settings[$key] = ['value' => trim((string) ($_POST[$key]['value'] ?? '')), 'visible' => !empty($_POST[$key]['visible'])];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homepage Stats - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .wrapx { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); max-width: 820px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.25rem; }
        .stat-row { display: grid; grid-template-columns: 1.3fr 1fr auto; gap: 1rem; align-items: center; padding: .9rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); }
        .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .82rem; }
        .form-control { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-check-input { width: 1.2em; height: 1.2em; }
        @media (max-width: 600px) { .stat-row { grid-template-columns: 1fr auto; } .stat-row > div:first-child { grid-column: 1 / -1; } }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-chart-bar me-2"></i>Homepage Stats</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST" action="home_stats.php" class="panel">
            <p class="note mb-2">These numbers show in the About section of the homepage, exactly as typed here. Untick "Show" to hide one.</p>
            <div class="stat-row">
                <div><b>Semester label</b><span class="note d-block">Shown after "Applicants" and "Rejections", e.g. Summer 2026. Leave blank for no label.</span></div>
                <div><input type="text" name="semester" class="form-control" maxlength="40" placeholder="Summer 2026" value="<?= htmlspecialchars($settings['semester']) ?>"></div>
                <div></div>
            </div>
            <?php foreach (HOME_STATS as $key => $def): ?>
                <div class="stat-row">
                    <div><b><?= htmlspecialchars($def['label']) ?><?= !empty($def['semester']) ? ' · <span class="note">semester label</span>' : '' ?></b></div>
                    <div><input type="number" min="0" max="9999999" step="1" required name="<?= $key ?>[value]" class="form-control" value="<?= htmlspecialchars((string) $settings[$key]['value']) ?>" aria-label="<?= htmlspecialchars($def['label']) ?>"></div>
                    <label class="d-flex align-items-center gap-2"><input type="checkbox" class="form-check-input m-0" name="<?= $key ?>[visible]" value="1" <?= $settings[$key]['visible'] ? 'checked' : '' ?>> Show</label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-success mt-3"><i class="fas fa-save me-2"></i>Save</button>
        </form>
    </div>
</body>

</html>
