<?php
/**
 * Attendance → Excel. ?session=ID (one session's list) or ?report=members (&semester=&level=&below=).
 * Text cells are written as plain text (a name starting with "=" never becomes a formula); counts and
 * percentages are numbers. Permission take_attendance.
 */
require_once '../backend/includes/auth.php';
require_admin(['can' => 'take_attendance', 'inject' => false]);
require_once '../backend/includes/attendance.php';
require_once '../backend/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$conn = db();
attendance_ensure($conn);

/** Writes rows; strings as text, ints/floats as numbers. */
function sheet_rows($sheet, array $rows, $startRow)
{
    foreach ($rows as $r => $row) {
        foreach (array_values($row) as $c => $v) {
            $cell = Coordinate::stringFromColumnIndex($c + 1) . ($startRow + $r);
            if (is_int($v) || is_float($v)) $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC);
            elseif ($v === null) continue;
            else $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
        }
    }
}
function finish_sheet($sheet, $cols, $headerRow)
{
    $last = Coordinate::stringFromColumnIndex($cols);
    $sheet->getStyle("A$headerRow:$last$headerRow")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A$headerRow:$last$headerRow")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2E86AB');
    for ($c = 1; $c <= $cols; $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
    $sheet->freezePane('A' . ($headerRow + 1));
}

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();

if (isset($_GET['session'])) {
    $s = att_session($conn, $_GET['session']);
    if (!$s) { header('Location: attendance.php'); exit(); }
    $ended = att_ended($s);
    $roster = att_roster($conn, $s['id']);
    $t = att_totals($roster, $ended);
    $sheet->setTitle('Attendance');
    sheet_rows($sheet, [
        [$s['title']],
        [(ATT_TYPES[$s['type']] ?? '') . ' · ' . att_when($s) . ' · ' . (ATT_AUDIENCES[$s['audience']] ?? '') . ' · ' . att_semester($s['session_date'])],
        ['Present', $t['present'], 'Late', $t['late'], 'Excused', $t['excused'], 'Absent', $t['absent'], 'Expected', $t['expected']],
    ], 1);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $rows = [['Name', 'Student ID', 'Level', 'Status', 'How', 'Marked at']];
    foreach ($roster as $r) {
        $rows[] = [$r['full_name'], $r['university_id'], ATT_LEVELS[$r['position']] ?? $r['position'],
            $r['status'] ? ATT_STATUSES[$r['status']] : ($ended ? 'Absent (not marked)' : 'Not marked'),
            $r['status'] ? ($r['method'] === 'qr' ? 'QR check-in' : 'Admin') : '', $r['marked_at'] ? date('Y-m-d H:i', strtotime($r['marked_at'])) : ''];
    }
    sheet_rows($sheet, $rows, 5);
    finish_sheet($sheet, 6, 5);
    $name = 'Attendance_' . preg_replace('/[^A-Za-z0-9]+/', '-', $s['title']) . '_' . $s['session_date'];
    log_activity('exported_excel', 'Attendance of one session, ' . count($roster) . ' row(s)', null, null, $s['title']);
} else {
    $sem = (string) ($_GET['semester'] ?? '');
    $level = (string) ($_GET['level'] ?? '');
    $below = isset($_GET['below']) && $_GET['below'] !== '' ? max(0, min(100, (int) $_GET['below'])) : null;
    $semStats = att_member_stats($conn, $sem !== '' ? $sem : null);
    $all = att_member_stats($conn);
    $sheet->setTitle('Members');
    sheet_rows($sheet, [['Attendance report: ' . ($sem !== '' ? $sem : 'all time') . ($level !== '' ? ', ' . (ATT_LEVELS[$level] ?? $level) : '') . ($below !== null ? ", below $below%" : '')],
        ['Present and late count as attended; excused sessions are not counted; unmarked at an ended session = absent. Exported ' . date('Y-m-d H:i') . ' (Dhaka).']], 1);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $rows = [['Name', 'Student ID', 'Level', 'Sessions', 'Attended', 'Late', 'Excused', 'Absent', ($sem !== '' ? $sem : 'All time') . ' %', 'Overall %']];
    $n = 0;
    foreach ($conn->query("SELECT id, full_name, university_id, position FROM members ORDER BY full_name")->fetchAll() as $m) {
        if ($level !== '' && $m['position'] !== $level) continue;
        $st = $semStats[(int) $m['id']] ?? null;
        if (!$st && !isset($all[(int) $m['id']])) continue;
        if ($below !== null && (($st['pct'] ?? null) === null || $st['pct'] >= $below)) continue;
        $rows[] = [$m['full_name'], $m['university_id'], ATT_LEVELS[$m['position']] ?? $m['position'], $st['expected'] ?? 0, $st['attended'] ?? 0, $st['late'] ?? 0,
            $st['excused'] ?? 0, $st['absent'] ?? 0, $st['pct'] ?? null, $all[(int) $m['id']]['pct'] ?? null];
        $n++;
    }
    sheet_rows($sheet, $rows, 4);
    finish_sheet($sheet, 10, 4);
    $name = 'Attendance_Report_' . preg_replace('/[^A-Za-z0-9]+/', '-', $sem !== '' ? $sem : 'All-time');
    log_activity('exported_excel', "Attendance report, $n member(s)", null, null, $sem !== '' ? $sem : 'All time');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '_' . date('Y-m-d_H-i') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
exit();
