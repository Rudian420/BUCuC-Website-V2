<?php
/**
 * A tenure's handover document as a print-ready page; the browser saves it as PDF ("Save as PDF"
 * in the print dialog). Done in the browser rather than with a PHP PDF library because browsers lay
 * out Bangla text correctly and no extra library is needed. Only sections this admin may see
 * (Contacts needs handover_contacts). Every export is logged.
 */
require_once '../backend/includes/auth.php';
require_admin();
require_once '../backend/includes/handover.php';

$conn = db();
$doc = handover_doc($conn, $_GET['doc'] ?? 0);
if (!$doc) {
    header('Location: handover.php');
    exit();
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$sections = handover_sections($conn, $doc['id']);
log_activity('handover_exported', 'Printed / saved as PDF' . (can('handover_contacts') ? '' : ' (without Contacts)'), null, null, 'Handover ' . $doc['tenure_label']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>BUCuC Handover Notes - Tenure <?= $h($doc['tenure_label']) ?></title>
    <style>
        /* Print document: plain black on white, no site palette */
        body { margin: 0; background: #fff; color: #111; font: 11pt/1.55 "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", Arial, sans-serif; }
        .page { max-width: 820px; margin: 0 auto; padding: 32px 28px; }
        .bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; background: #f1eefc; border-radius: 10px; margin-bottom: 24px; font-size: 10.5pt; }
        .bar button { padding: 8px 16px; border: 0; border-radius: 999px; background: #5b34e6; color: #fff; font-weight: 700; cursor: pointer; }
        header { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; display: flex; gap: 14px; align-items: center; }
        header img { width: 48px; height: 48px; object-fit: contain; }
        h1 { font-size: 19pt; margin: 0; }
        .sub { color: #555; font-size: 10pt; }
        h2 { font-size: 13.5pt; margin: 22px 0 4px; padding-bottom: 3px; border-bottom: 1px solid #bbb; break-after: avoid; }
        h3 { font-size: 11.5pt; margin: 14px 0 4px; }
        .meta { color: #666; font-size: 9pt; margin-bottom: 6px; }
        .empty { color: #888; font-style: italic; }
        .text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .warn { border: 1px solid #c00; color: #a00; padding: 6px 10px; border-radius: 6px; font-size: 9.5pt; margin: 4px 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 4px; }
        th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #f1f1f1; }
        tr { break-inside: avoid; }
        .done td { color: #777; }
        footer { margin-top: 28px; color: #777; font-size: 8.5pt; border-top: 1px solid #ccc; padding-top: 6px; }
        @media print {
            .bar { display: none; }
            .page { padding: 0; max-width: none; }
            @page { margin: 16mm 14mm; }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="bar"><span>Choose <b>Save as PDF</b> as the printer to get a PDF file.</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
        <header>
            <img src="images/logopng.png" alt="">
            <div>
                <h1>Handover Notes · Tenure <?= $h($doc['tenure_label']) ?></h1>
                <div class="sub">BRAC University Cultural Club · exported <?= date('j F Y, g:i a') ?> (Dhaka)<?= $doc['ends_on'] ? ' · tenure ends ' . date('j F Y', strtotime($doc['ends_on'])) : '' ?></div>
            </div>
        </header>

        <?php foreach (HANDOVER_SECTIONS as $key => $def):
            if (!handover_can_see($key)) continue;
            $keys = $def['type'] === 'roles' ? array_keys(handover_role_keys()) : [$key]; ?>
            <h2><?= $h($def['title']) ?></h2>
            <?php if (!empty($def['warning'])): ?><div class="warn"><?= $h($def['warning']) ?></div><?php endif; ?>
            <?php foreach ($keys as $k):
                $s = $sections[$k] ?? null;
                $content = $s['content'] ?? (handover_is_table($k) ? [] : ''); ?>
                <?php if ($def['type'] === 'roles'): ?><h3><?= $h(role_label(substr($k, 5))) ?></h3><?php endif; ?>
                <?php if ($s): ?><div class="meta">Last edited by <?= $h(handover_author($s['role'])) ?>, <?= date('j M Y', strtotime($s['at'])) ?></div><?php endif; ?>
                <?php if (handover_empty($content)): ?>
                    <p class="empty">Nothing written.</p>
                <?php elseif (!handover_is_table($k)): ?>
                    <div class="text"><?= $h($content) ?></div>
                <?php else: ?>
                    <table>
                        <thead><tr><?php if ($k === 'pending'): ?><th>Done</th><?php endif; ?><?php foreach ($def['cols'] as $cl): ?><th><?= $h($cl) ?></th><?php endforeach; ?></tr></thead>
                        <tbody><?php foreach ($content as $r): ?>
                            <tr class="<?= !empty($r['done']) ? 'done' : '' ?>"><?php if ($k === 'pending'): ?><td><?= !empty($r['done']) ? '✓ ' . $h(date('j M Y', strtotime($r['done_at']))) : '' ?></td><?php endif; ?>
                                <?php foreach ($def['cols'] as $c => $_): ?><td><?= nl2br($h($r[$c] ?? '')) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?></tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <footer>Handover notes are internal to the BUCuC panel. They never contain passwords.<?= can('handover_contacts') ? '' : ' Contacts are not included in this copy.' ?></footer>
    </div>
    <script>
        // open the print dialog once the page (and the logo) has loaded
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>

</html>
