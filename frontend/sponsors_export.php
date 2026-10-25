<?php
/**
 * Per-ad report for the sponsor (Excel): summary and one row per day. Super Admin only; 404 for
 * everyone else. Text is written as text (no formulas from names or links); numbers as numbers.
 */
require_once '../backend/includes/auth.php';
require_once '../backend/includes/ads.php';
ads_require_super();
require_once '../backend/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$conn = db();
ads_ensure($conn);
$st = $conn->prepare("SELECT * FROM ads WHERE id = ?");
$st->execute([(int) ($_GET['id'] ?? 0)]);
$ad = $st->fetch();
if (!$ad) ads_not_found();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $ad['starts_on'];
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : min($ad['ends_on'], date('Y-m-d'));
if ($from > $to) [$from, $to] = [$to, $from];

$q = $conn->prepare("SELECT day, views, clicks FROM ad_stats WHERE ad_id = ? AND day BETWEEN ? AND ? ORDER BY day");
$q->execute([$ad['id'], $from, $to]);
$byDay = [];
foreach ($q->fetchAll() as $r) $byDay[$r['day']] = [(int) $r['views'], (int) $r['clicks']];

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Report');
$text = fn($cell, $v) => $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
$num = fn($cell, $v) => $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC);

$text('A1', 'Sponsor report: ' . $ad['advertiser']);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$text('A2', 'BRAC University Cultural Club · ' . (AD_SPOTS[$ad['spot']]['label'] ?? $ad['spot']));
$text('A3', 'Campaign: ' . date('j M Y', strtotime($ad['starts_on'])) . ' – ' . date('j M Y', strtotime($ad['ends_on'])) . ' · report period: ' . date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to)) . ' (Dhaka time)');
$text('A4', 'Link: ' . $ad['link']);
$tv = array_sum(array_column($byDay, 0));
$tc = array_sum(array_column($byDay, 1));
$text('A6', 'Views'); $num('B6', $tv);
$text('A7', 'Clicks'); $num('B7', $tc);
$text('A8', 'Click rate (%)');
if ($tv > 0) $num('B8', ads_ctr($tv, $tc)); else $text('B8', '—');
foreach (['A6', 'A7', 'A8'] as $c) $sheet->getStyle($c)->getFont()->setBold(true);

$row = 10;
foreach (['Date', 'Views', 'Clicks', 'Click rate (%)'] as $i => $hd) $text(chr(65 + $i) . $row, $hd);
$sheet->getStyle("A$row:D$row")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A$row:D$row")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2E86AB');
for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
    $day = date('Y-m-d', $d);
    [$v, $c] = $byDay[$day] ?? [0, 0];
    $row++;
    $text("A$row", $day);
    $num("B$row", $v);
    $num("C$row", $c);
    if ($v > 0) $num("D$row", ads_ctr($v, $c));
}
foreach (['A', 'B', 'C', 'D'] as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
$text('A' . ($row + 2), 'Views count page loads by real visitors (bots, link previews and the club\'s own admins are not counted). Clicks count once per visitor per ' . AD_CLICK_DEDUPE_MINUTES . ' minutes.');

log_activity('ad_report_exported', "Report $from to $to: $tv view(s), $tc click(s)", null, null, $ad['advertiser']);
$file = 'Sponsor_Report_' . (preg_replace('/[^A-Za-z0-9]+/', '-', $ad['advertiser']) ?: 'ad') . "_{$from}_{$to}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
exit();
