<?php
/**
 * Handover notes per tenure (every admin). See backend/includes/handover.php for the rules.
 *   ?doc=ID            a tenure's document (default: the current tenure)
 *   ?doc=ID&edit=KEY   edit one section          ?doc=ID&history=KEY   its saved versions
 *   ?q=TEXT            search every tenure's notes
 */
require_once '../backend/includes/auth.php';
require_admin(['csrf_redirect' => 'handover.php']);
if (!can_handover()) {
    // Handover Notes are for the Super Admin and the Panel Admin only (HANDOVER_ROLES in roles.php)
    auth_deny(false, 403, "You don't have access to the Handover Notes.", auth_frontend_url(auth_dashboard_page()) . '?error=' . urlencode("You don't have access to the Handover Notes."));
}
require_once '../backend/includes/handover.php';

$conn = db();
handover_ensure($conn);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$message = $_GET['success'] ?? '';
$error = '';
$docs = handover_docs($conn);
$docId = (int) ($_GET['doc'] ?? ($_POST['doc'] ?? 0));
$doc = null;
foreach ($docs as $d) if ((int) $d['id'] === $docId) $doc = $d;
if (!$doc) foreach ($docs as $d) if ($d['is_current']) $doc = $d;
if (!$doc && $docs) $doc = $docs[0];
$role = role_normalize($_SESSION['admin_role'] ?? null);
$canDates = in_array($role, ['super_admin', 'panel_admin'], true);
$label = fn($d) => 'Tenure ' . $d['tenure_label'];
$back = function ($msg, $anchor = '') use (&$doc) {
    header('Location: handover.php?doc=' . (int) $doc['id'] . '&success=' . urlencode($msg) . $anchor);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $doc) {
    $action = $_POST['action'] ?? '';
    $key = (string) ($_POST['key'] ?? '');
    try {
        if ($action === 'save_section') {
            $sections = handover_sections($conn, $doc['id']);
            $cur = $sections[$key] ?? null;
            if (!handover_can_edit($doc, $key)) {
                $error = handover_editable($doc) ? "You can't edit this section." : (auth_is_super() ? 'These notes are read-only: the next tenure is already current. Unlock them first.' : 'These notes are read-only: the next tenure is already current.');
            } elseif ((int) ($_POST['base'] ?? 0) !== (int) ($cur['version'] ?? 0)) {
                $error = 'Someone else saved this section while you were editing it. Your text is still below: compare it with the saved version (History), then save again.';
            } else {
                [$content, $err] = handover_clean($key, handover_is_table($key) ? ($_POST['rows'] ?? []) : ($_POST['text'] ?? ''), is_array($cur['content'] ?? null) ? $cur['content'] : []);
                if ($err) $error = $err;
                elseif ($cur && $content == $cur['content']) $back('Nothing changed.', '#' . str_replace(':', '-', $key));
                elseif (!$cur && handover_empty($content)) $back('Nothing to save.', '#' . str_replace(':', '-', $key));
                else {
                    handover_save($conn, $doc['id'], $key, $content);
                    $size = is_array($content) ? count($content) . ' row(s)' : mb_strlen($content) . ' characters';
                    log_activity('handover_edited', handover_section_title($key) . ", now $size", null, null, 'Handover ' . $doc['tenure_label']);
                    $back('"' . handover_section_title($key) . '" saved.', '#' . str_replace(':', '-', $key));
                }
            }
        } elseif ($action === 'task_done') {
            // The next panel ticks off pending tasks, also on an older (read-only) document
            $sections = handover_sections($conn, $doc['id']);
            $rows = $sections['pending']['content'] ?? [];
            $rid = (string) ($_POST['row'] ?? '');
            $done = !empty($_POST['done']);
            $hit = null;
            foreach ($rows as &$r) if (($r['id'] ?? '') === $rid) { $r['done'] = $done; $r['done_role'] = $done ? $role : null; $r['done_at'] = $done ? date('Y-m-d H:i:s') : null; $hit = $r; }
            unset($r);
            if (!$hit) $error = 'That task is no longer in the list.';
            else {
                handover_save($conn, $doc['id'], 'pending', $rows, ($done ? 'Done: ' : 'Not done: ') . mb_substr($hit['task'], 0, 100));
                log_activity('handover_task_done', ($done ? 'Marked done: ' : 'Marked not done: ') . $hit['task'], null, null, 'Handover ' . $doc['tenure_label']);
                $back($done ? 'Task marked done.' : 'Task marked not done.', '#pending');
            }
        } elseif ($action === 'restore') {
            $v = $conn->prepare("SELECT * FROM handover_versions WHERE id = ? AND doc_id = ?");
            $v->execute([(int) ($_POST['version'] ?? 0), $doc['id']]);
            $ver = $v->fetch();
            if (!$ver || !handover_can_edit($doc, $ver['section_key'])) $error = "That version can't be restored here.";
            else {
                $key = $ver['section_key'];
                handover_save($conn, $doc['id'], $key, handover_decode($key, $ver['content']), 'Restored the version of ' . date('j M Y, g:i a', strtotime($ver['saved_at'])));
                log_activity('handover_restored', handover_section_title($key) . ': restored the version of ' . date('j M Y, g:i a', strtotime($ver['saved_at'])) . ' by ' . handover_author($ver['saved_by_role']), null, null, 'Handover ' . $doc['tenure_label']);
                $back('Older version restored. The version it replaced is kept in the history too.', '#' . str_replace(':', '-', $key));
            }
        } elseif ($action === 'lock' || $action === 'unlock') {
            if (!auth_is_super()) $error = "You can't unlock old handover notes.";
            elseif ($doc['is_current']) $error = "The current tenure's notes are always editable.";
            else {
                $conn->prepare("UPDATE handover_docs SET unlocked = ? WHERE id = ?")->execute([$action === 'unlock' ? 1 : 0, $doc['id']]);
                log_activity($action === 'unlock' ? 'handover_unlocked' : 'handover_locked', $action === 'unlock' ? 'Old notes unlocked for editing' : 'Locked again (read-only)', null, null, 'Handover ' . $doc['tenure_label']);
                $back($action === 'unlock' ? 'Unlocked: these notes can be edited until you lock them again.' : 'Locked: these notes are read-only again.');
            }
        } elseif ($action === 'set_end') {
            $end = (string) ($_POST['ends_on'] ?? '');
            $d = DateTime::createFromFormat('Y-m-d', $end);
            if (!$canDates) $error = "You can't set the end date.";
            elseif (!$doc['is_current']) $error = 'The end date is set on the current tenure.';
            elseif ($end !== '' && (!$d || $d->format('Y-m-d') !== $end)) $error = 'Enter a valid date.';
            else {
                $conn->prepare("UPDATE handover_docs SET ends_on = ?, reminder_sent_at = IF(ends_on <=> ?, reminder_sent_at, NULL) WHERE id = ?")->execute([$end ?: null, $end ?: null, $doc['id']]);
                log_activity('handover_end_date_set', $end ? 'Tenure ends ' . date('j M Y', strtotime($end)) . '; reminder e-mail ' . HANDOVER_REMINDER_DAYS . ' days before' : 'End date cleared (no reminder)', null, null, 'Handover ' . $doc['tenure_label']);
                $back($end ? 'End date saved. All admins get a reminder e-mail about ' . HANDOVER_REMINDER_DAYS . ' days before ' . date('j M Y', strtotime($end)) . '.' : 'End date cleared.');
            }
        }
    } catch (\Throwable $e) {
        error_log('handover.php: ' . $e->getMessage());
        $error = 'Saving failed because of a database problem. Nothing was changed; please try again.';
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? handover_search($conn, $q) : [];
$sections = $doc ? handover_sections($conn, $doc['id']) : [];
$editKey = (string) ($_GET['edit'] ?? ($error && ($_POST['action'] ?? '') === 'save_section' ? ($_POST['key'] ?? '') : ''));
if ($editKey !== '' && (!$doc || !handover_can_edit($doc, $editKey))) $editKey = '';
$histKey = (string) ($_GET['history'] ?? '');
if ($histKey !== '' && !handover_can_see($histKey)) $histKey = '';
$empty = $doc ? handover_empty_sections($conn, $doc) : [];
$editable = $doc && handover_editable($doc);

/** The content box of a section (view mode). */
function render_view($key, $content, $doc, $h)
{
    if (handover_empty($content)) return '<p class="note fst-italic m-0">Nothing written yet.</p>';
    if (!handover_is_table($key)) return '<div class="hv-text">' . nl2br($h($content)) . '</div>';
    $cols = HANDOVER_SECTIONS[$key]['cols'];
    $out = ($key === 'contacts' && count($content) > 4 ? '<input type="search" class="form-control form-control-sm mb-2 tfilter" placeholder="Search contacts" aria-label="Search contacts">' : '')
        . '<div class="scroll-x"><table class="tbl"><thead><tr>' . ($key === 'pending' ? '<th style="width:1%">Done</th>' : '');
    foreach ($cols as $c) $out .= '<th>' . $h($c) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($content as $r) {
        $out .= '<tr' . (!empty($r['done']) ? ' class="done"' : '') . ' data-q="' . $h(mb_strtolower(implode(' ', array_map('strval', $r)))) . '">';
        if ($key === 'pending') {
            $out .= '<td><form method="POST" action="handover.php?doc=' . (int) $doc['id'] . '" class="m-0"><input type="hidden" name="action" value="task_done"><input type="hidden" name="doc" value="' . (int) $doc['id'] . '"><input type="hidden" name="row" value="' . $h($r['id'] ?? '') . '">'
                . '<input type="hidden" name="done" value="' . (empty($r['done']) ? '1' : '') . '"><button type="submit" class="tick' . (!empty($r['done']) ? ' on' : '') . '" title="' . (empty($r['done']) ? 'Mark done' : 'Mark not done') . '" aria-label="' . (empty($r['done']) ? 'Mark done' : 'Mark not done') . ': ' . $h($r['task']) . '"><i class="fas fa-check"></i></button></form></td>';
        }
        foreach ($cols as $c => $_) {
            $v = (string) ($r[$c] ?? '');
            if ($key === 'links' && $c === 'url' && preg_match('~^https?://~i', $v)) $cell = '<a href="' . $h($v) . '" target="_blank" rel="noopener noreferrer">' . $h($v) . '</a>';
            else $cell = nl2br($h($v));
            if ($key === 'pending' && $c === 'notes' && !empty($r['done'])) $cell .= '<br><small class="note">Done by ' . $h(handover_author($r['done_role'] ?? null)) . ', ' . $h(date('j M Y', strtotime($r['done_at']))) . '</small>';
            $out .= '<td>' . $cell . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handover Notes | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/attendance.css?v=<?= filemtime(__DIR__ . '/css/attendance.css') ?>">
    <style>
        .chips { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .chips a { padding: .45rem 1rem; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: inherit; text-decoration: none; font-weight: 600; font-size: .88rem; }
        .chips a.on { background: linear-gradient(100deg, rgb(var(--c-accent2, 92 255 176)), rgb(var(--c-accent, 45 226 230)) 50%, rgb(var(--c-primary, 139 92 255))); color: rgb(var(--c-surface-0a0620, 10 6 32)); border-color: transparent; }
        .chips a small { margin-left: .3rem; font-weight: 700; color: rgb(var(--c-highlight, 255 207 125)); }
        .chips a.on small { color: rgb(var(--c-paperAccent-3b1a66, 59 26 102)); }
        .sec { scroll-margin-top: 16px; }
        .sec-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; flex-wrap: wrap; }
        .sec-head h4 { margin: 0; }
        .prompt { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .88rem; margin: .3rem 0 .8rem; }
        .meta { font-size: .78rem; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .hv-text { white-space: normal; line-height: 1.65; overflow-wrap: anywhere; }
        .warnbox { border-radius: 12px; padding: .65rem .9rem; margin-bottom: .8rem; background: rgb(var(--c-danger, 255 147 180) / 0.12); border: 1px solid rgb(var(--c-danger, 255 147 180) / 0.5); color: rgb(var(--c-danger, 255 147 180)); font-size: .9rem; font-weight: 600; }
        .tbl tr.done td { opacity: .6; }
        .tbl tr.done td:nth-child(2) { text-decoration: line-through; }
        .tick { width: 34px; height: 34px; border-radius: 10px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); background: transparent; color: transparent; }
        .tick.on { background: rgb(var(--c-accent2, 92 255 176)); color: rgb(var(--c-bg, 6 4 15)); border-color: transparent; }
        .tick:hover { color: rgb(var(--c-accent2, 92 255 176)); }
        .tick.on:hover { color: rgb(var(--c-bg, 6 4 15)); }
        .edit-rows .erow { display: grid; gap: .4rem; padding: .5rem; border-radius: 12px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); margin-bottom: .5rem; }
        @media (min-width: 800px) { .edit-rows .erow { grid-template-columns: repeat(var(--cols), minmax(0, 1fr)); } }
        .edit-rows textarea { min-height: 38px; }
        .role-box { border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); border-radius: 14px; padding: 1rem; margin-bottom: .8rem; }
        .ver { border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); padding: .6rem 0; }
        .ver summary { cursor: pointer; }
        .ver pre { white-space: pre-wrap; font: inherit; font-size: .88rem; margin: .5rem 0; padding: .6rem; border-radius: 10px; background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); max-height: 320px; overflow: auto; }
        .result { padding: .7rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); }
        .result mark { background: rgb(var(--c-highlight, 255 207 125) / 0.35); color: inherit; padding: 0 2px; border-radius: 3px; }
        .emptylist { border-radius: 12px; padding: .7rem .9rem; background: rgb(var(--c-highlight, 255 207 125) / 0.1); border: 1px solid rgb(var(--c-highlight, 255 207 125) / 0.35); font-size: .9rem; }
        .toc { display: flex; flex-wrap: wrap; gap: .35rem .8rem; font-size: .85rem; }
        .toc a { color: inherit; display: inline-flex; align-items: center; min-height: 32px; }
        .toc a.empty { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-handshake me-2"></i>Handover Notes</h2>
            <div class="links">
                <form method="GET" action="handover.php" class="d-flex gap-2 m-0" role="search">
                    <input type="search" name="q" class="form-control" placeholder="Search all tenures' notes" value="<?= $h($q) ?>" aria-label="Search all handover notes">
                    <button class="btn btn-outline-light" type="submit" aria-label="Search"><i class="fas fa-search"></i></button>
                </form>
                <a href="<?= $h(auth_dashboard_page()) ?>" class="back-btn"><i class="fas fa-arrow-left"></i>Dashboard</a>
            </div>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

        <?php if ($q !== ''): ?>
            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h4 class="m-0">Search: "<?= $h($q) ?>"</h4><a href="handover.php?doc=<?= (int) ($doc['id'] ?? 0) ?>" class="btn btn-sm btn-secondary">Close search</a></div>
                <p class="note"><?= count($results) ?> section<?= count($results) === 1 ? '' : 's' ?> found in all tenures<?= mb_strlen($q) < 2 ? ' (type at least 2 characters)' : '' ?>.</p>
                <?php foreach ($results as $r):
                    $snip = $h($r['snippet']);
                    $snip = preg_replace('/' . preg_quote($h($q), '/') . '/iu', '<mark>$0</mark>', $snip); ?>
                    <div class="result"><a href="handover.php?doc=<?= (int) $r['doc']['id'] ?>#<?= $h(str_replace(':', '-', $r['key'])) ?>"><b><?= $h($label($r['doc'])) ?> · <?= $h($r['title']) ?></b></a><br><span class="note"><?= nl2br($snip) ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$doc): ?>
            <div class="panel"><p class="m-0">There are no tenures yet. Create one in Website Media → Panels &amp; Tenures; its handover document appears here.</p></div>
        <?php else: ?>
            <div class="chips">
                <?php foreach ($docs as $d): ?><a href="handover.php?doc=<?= (int) $d['id'] ?>" class="<?= (int) $d['id'] === (int) $doc['id'] ? 'on' : '' ?>"><?= $h($d['tenure_label']) ?><?= $d['is_current'] ? '<small>current</small>' : '' ?><?= $d['tenure_id'] ? '' : '<small>tenure removed</small>' ?></a><?php endforeach; ?>
            </div>

            <div class="panel">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3 class="m-0"><?= $h($label($doc)) ?></h3>
                        <div class="mt-1">
                            <?php if ($doc['is_current']): ?><span class="chip now">Current tenure · editable</span>
                            <?php elseif ($doc['unlocked']): ?><span class="chip late">Unlocked · editable</span>
                            <?php else: ?><span class="chip done"><i class="fas fa-lock me-1"></i>Read-only (a newer tenure is current)</span><?php endif; ?>
                            <?php if (!$doc['tenure_id']): ?><span class="chip done">Tenure removed; notes kept</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="handover_print.php?doc=<?= (int) $doc['id'] ?>" target="_blank" class="btn btn-outline-light"><i class="fas fa-file-pdf me-1"></i>PDF</a>
                        <?php if (auth_is_super() && !$doc['is_current']): ?>
                            <form method="POST" action="handover.php?doc=<?= (int) $doc['id'] ?>" class="m-0"><input type="hidden" name="doc" value="<?= (int) $doc['id'] ?>">
                                <input type="hidden" name="action" value="<?= $doc['unlocked'] ? 'lock' : 'unlock' ?>">
                                <button type="submit" class="btn btn-outline-warning"><i class="fas <?= $doc['unlocked'] ? 'fa-lock' : 'fa-lock-open' ?> me-1"></i><?= $doc['unlocked'] ? 'Lock again' : 'Unlock for editing' ?></button></form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($doc['is_current']): ?>
                    <div class="mt-3">
                        <?php if ($canDates): ?>
                            <form method="POST" action="handover.php?doc=<?= (int) $doc['id'] ?>" class="d-flex gap-2 flex-wrap align-items-center m-0">
                                <input type="hidden" name="action" value="set_end"><input type="hidden" name="doc" value="<?= (int) $doc['id'] ?>">
                                <label class="note" for="ends_on">This tenure ends on</label>
                                <input type="date" class="form-control w-auto" id="ends_on" name="ends_on" value="<?= $h($doc['ends_on']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-light">Save</button>
                                <span class="note"><?= $doc['ends_on'] ? ($doc['reminder_sent_at'] ? 'Reminder sent ' . date('j M Y', strtotime($doc['reminder_sent_at'])) . '.' : 'All admins get a reminder e-mail on ' . date('j M Y', strtotime($doc['ends_on']) - HANDOVER_REMINDER_DAYS * 86400) . '.') : 'Set it to send all admins a reminder about a month before.' ?></span>
                            </form>
                        <?php elseif ($doc['ends_on']): ?>
                            <span class="note">This tenure ends on <?= date('j F Y', strtotime($doc['ends_on'])) ?>.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($empty): ?>
                    <div class="emptylist mt-3"><b><?= count($empty) ?> section<?= count($empty) === 1 ? ' is' : 's are' ?> still empty:</b> <?= $h(implode(', ', $empty)) ?>.</div>
                <?php endif; ?>
                <div class="toc mt-3"><?php foreach (HANDOVER_SECTIONS as $k => $def): if (!handover_can_see($k)) continue; ?><a href="#<?= $k ?>" class="<?= isset($empty[$k]) ? 'empty' : '' ?>"><?= $h($def['title']) ?></a><?php endforeach; ?></div>
            </div>

            <?php foreach (HANDOVER_SECTIONS as $key => $def):
                if (!handover_can_see($key)) continue;
                $keys = $def['type'] === 'roles' ? array_keys(handover_role_keys()) : [$key]; ?>
                <div class="panel sec" id="<?= $key ?>">
                    <div class="sec-head"><h4><i class="fas <?= $def['icon'] ?> me-2"></i><?= $h($def['title']) ?></h4></div>
                    <p class="prompt"><?= $h($def['prompt']) ?></p>
                    <?php if (!empty($def['warning'])): ?><div class="warnbox" role="note"><i class="fas fa-shield-halved me-1"></i><?= $h($def['warning']) ?></div><?php endif; ?>
                    <?php foreach ($keys as $k):
                        $s = $sections[$k] ?? null;
                        $anchor = str_replace(':', '-', $k); ?>
                        <div class="<?= $def['type'] === 'roles' ? 'role-box' : '' ?>" id="<?= $def['type'] === 'roles' ? $h($anchor) : '' ?>">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div>
                                    <?php if ($def['type'] === 'roles'): ?><b><?= $h(role_label(substr($k, 5))) ?></b><?php endif; ?>
                                    <span class="meta"><?= $s ? 'Last edited by ' . $h(handover_author($s['role'])) . ' · ' . date('j M Y, g:i a', strtotime($s['at'])) : '' ?></span>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if ($editKey !== $k && handover_can_edit($doc, $k)): ?><a class="btn btn-sm btn-outline-light" href="handover.php?doc=<?= (int) $doc['id'] ?>&edit=<?= $h(urlencode($k)) ?>#<?= $h($anchor) ?>"><i class="fas fa-pen me-1"></i>Edit</a><?php endif; ?>
                                    <?php if ($s): ?><a class="btn btn-sm btn-outline-light" href="handover.php?doc=<?= (int) $doc['id'] ?>&history=<?= $h(urlencode($k)) ?>#<?= $h($anchor) ?>"><i class="fas fa-clock-rotate-left me-1"></i>History</a><?php endif; ?>
                                </div>
                            </div>

                            <?php if ($editKey === $k):
                                $postedHere = $error && ($_POST['key'] ?? '') === $k; ?>
                                <form method="POST" action="handover.php?doc=<?= (int) $doc['id'] ?>#<?= $h($anchor) ?>">
                                    <input type="hidden" name="action" value="save_section"><input type="hidden" name="doc" value="<?= (int) $doc['id'] ?>">
                                    <input type="hidden" name="key" value="<?= $h($k) ?>"><input type="hidden" name="base" value="<?= (int) ($postedHere ? ($_POST['base'] ?? 0) : ($s['version'] ?? 0)) ?>">
                                    <?php if (!handover_is_table($k)): ?>
                                        <textarea name="text" class="form-control" rows="12" maxlength="<?= HANDOVER_TEXT_MAX ?>" aria-label="<?= $h(handover_section_title($k)) ?>"><?= $h($postedHere ? ($_POST['text'] ?? '') : ($s['content'] ?? '')) ?></textarea>
                                    <?php else:
                                        $rows = $postedHere ? array_values((array) ($_POST['rows'] ?? [])) : ($s['content'] ?? []);
                                        $cols = $def['cols'];
                                        $rows = array_merge($rows, array_fill(0, max(2, 3 - count($rows)), [])); ?>
                                        <div class="edit-rows" style="--cols: <?= count($cols) ?>">
                                            <?php foreach ($rows as $i => $r): ?>
                                                <div class="erow">
                                                    <?php if ($k === 'pending'): ?><input type="hidden" name="rows[<?= $i ?>][id]" value="<?= $h($r['id'] ?? '') ?>"><?php endif; ?>
                                                    <?php foreach ($cols as $c => $cl): ?>
                                                        <textarea class="form-control form-control-sm" rows="1" name="rows[<?= $i ?>][<?= $c ?>]" placeholder="<?= $h($cl) ?>" aria-label="<?= $h($cl) ?>" maxlength="<?= HANDOVER_CELL_MAX ?>"><?= $h($r[$c] ?? '') ?></textarea>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-light mb-2 addrow"><i class="fas fa-plus me-1"></i>Add a row</button>
                                        <p class="note">To remove a row, empty all its boxes. Rows keep the order shown.</p>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save</button>
                                    <a href="handover.php?doc=<?= (int) $doc['id'] ?>#<?= $h($anchor) ?>" class="btn btn-secondary ms-2">Cancel</a>
                                    <span class="note ms-2">Every save is kept in the history.</span>
                                </form>
                            <?php else: ?>
                                <?= render_view($k, $s['content'] ?? (handover_is_table($k) ? [] : ''), $doc, $h) ?>
                            <?php endif; ?>

                            <?php if ($histKey === $k):
                                $vers = handover_versions($conn, $doc['id'], $k); ?>
                                <div class="mt-3">
                                    <h5 class="mb-1">History (<?= count($vers) ?> version<?= count($vers) === 1 ? '' : 's' ?>)</h5>
                                    <?php foreach ($vers as $i => $v): ?>
                                        <details class="ver">
                                            <summary><?= date('j M Y, g:i a', strtotime($v['saved_at'])) ?> · <?= $h(handover_author($v['saved_by_role'])) ?><?= $v['note'] ? ' · ' . $h($v['note']) : '' ?><?= $i === 0 ? ' <span class="chip now">current</span>' : '' ?></summary>
                                            <pre><?= $h(handover_plain($k, handover_decode($k, $v['content'])) ?: '(empty)') ?></pre>
                                            <?php if ($i > 0 && handover_can_edit($doc, $k)): ?>
                                                <form method="POST" action="handover.php?doc=<?= (int) $doc['id'] ?>" onsubmit="return confirm('Restore this version? The current text stays in the history.')">
                                                    <input type="hidden" name="action" value="restore"><input type="hidden" name="doc" value="<?= (int) $doc['id'] ?>"><input type="hidden" name="version" value="<?= (int) $v['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-rotate-left me-1"></i>Restore this version</button>
                                                </form>
                                            <?php endif; ?>
                                        </details>
                                    <?php endforeach; ?>
                                    <a href="handover.php?doc=<?= (int) $doc['id'] ?>#<?= $h($anchor) ?>" class="btn btn-sm btn-secondary mt-2">Close history</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // "Add a row" in table sections: copy the last row with empty boxes and the next index
        document.querySelectorAll('.addrow').forEach(b => b.addEventListener('click', () => {
            const box = b.previousElementSibling, rows = box.querySelectorAll('.erow');
            const last = rows[rows.length - 1], n = rows.length;
            const copy = last.cloneNode(true);
            copy.querySelectorAll('textarea, input').forEach(el => { el.value = ''; el.name = el.name.replace(/rows\[\d+\]/, `rows[${n}]`); });
            box.appendChild(copy);
            copy.querySelector('textarea').focus();
        }));
        // contacts search inside the table
        document.querySelectorAll('.tfilter').forEach(f => f.addEventListener('input', () => {
            const q = f.value.trim().toLowerCase();
            f.nextElementSibling.querySelectorAll('tbody tr').forEach(tr => tr.hidden = q !== '' && !tr.dataset.q.includes(q));
        }));
        // let a long text box grow with its content
        document.querySelectorAll('.edit-rows textarea').forEach(t => { const fit = () => { t.style.height = 'auto'; t.style.height = t.scrollHeight + 2 + 'px'; }; t.addEventListener('input', fit); fit(); });
    </script>
</body>

</html>
