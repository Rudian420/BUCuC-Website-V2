<?php
/**
 * Excel export of one application list.
 *   ?status=Pending (default) -> pending_applications
 *   ?status=Shortlisted        -> shortlisted_members
 *   ?status=Accepted           -> members (approved members)
 */
require_once '../includes/auth.php';
require_admin(['inject' => false]);
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$lists = [
    'Pending'     => ['table' => 'pending_applications', 'label' => 'Pending',     'page' => 'pending_applications.php'],
    'Shortlisted' => ['table' => 'shortlisted_members',  'label' => 'Shortlisted', 'page' => 'shortlisted_members.php'],
    'Accepted'    => ['table' => 'members',              'label' => 'Accepted',    'page' => 'approved_members.php'],
];
$status = $_GET['status'] ?? 'Pending';
if (!isset($lists[$status])) {
    $status = 'Pending';
}
$list = $lists[$status];

// Approved members are a member list: needs view_members. Pending / shortlisted: anyone who shortlists.
require_can($status === 'Accepted' ? 'view_members' : 'shortlist');

try {
    $database = new Database();
    $pdo = $database->createConnection();

    $members = $pdo->query("SELECT * FROM {$list['table']} ORDER BY created_at DESC")->fetchAll();

    if (empty($members)) {
        header("Location: ../../frontend/{$list['page']}?error=" . urlencode("There are no {$list['label']} records to export."));
        exit();
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($list['label']);

    $spreadsheet->getProperties()
        ->setCreator('BRAC University Cultural Club')
        ->setLastModifiedBy('Admin')
        ->setTitle($list['label'] . ' Members Export')
        ->setSubject('Member Applications Data')
        ->setDescription('Export of ' . $list['label'] . ' records from the database')
        ->setKeywords('members, applications, brac, cultural club')
        ->setCategory('Member Data');

    $headers = ['Full Name', 'University ID', 'Email', 'G-Suite Email', 'Department', 'Phone', 'Semester',
        'Gender', 'Date of Birth', 'Facebook URL', 'First Priority', 'Second Priority', 'Status',
        'Application Date', 'Last Updated'];
    $lastCol = 'O';

    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E86AB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(25);

    $row = 2;
    foreach ($members as $member) {
        $createdDate = date('Y-m-d H:i:s', strtotime($member['created_at']));
        $updatedDate = !empty($member['updated_at']) ? date('Y-m-d H:i:s', strtotime($member['updated_at'])) : $createdDate;

        // Every cell is written as plain text: names, links etc. starting with "=" are never formulas,
        // and IDs / phone numbers keep their leading zeros
        $values = [
            $member['full_name'],
            $member['university_id'],
            $member['email'],
            $member['gsuite_email'],
            $member['department'],
            $member['phone'],
            $member['semester'],
            $member['gender'],
            $member['date_of_birth'] ?? '',
            $member['facebook_url'],
            $member['firstPriority'],
            $member['secondPriority'],
            $list['label'],
            $createdDate,
            $updatedDate,
        ];
        foreach ($values as $i => $v) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $row, (string) $v, DataType::TYPE_STRING);
        }
        $row++;
    }

    $lastRow = $row - 1;
    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
    ]);

    foreach (range('A', $lastCol) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $summaryRow = $lastRow + 3;
    $sheet->setCellValue('A' . $summaryRow, 'Export Summary:');
    $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);
    $sheet->setCellValue('A' . ($summaryRow + 1), 'List: ' . $list['label']);
    $sheet->setCellValue('A' . ($summaryRow + 2), 'Total Records: ' . count($members));
    $sheet->setCellValue('A' . ($summaryRow + 3), 'Export Date: ' . date('Y-m-d H:i:s'));
    // The file can be passed on, so the Super Admin is never named in it
    $sheet->setCellValue('A' . ($summaryRow + 4), 'Exported By: ' . (auth_is_super() ? 'an admin' : ($_SESSION['admin_name'] ?? 'an admin')));

    $filename = $list['label'] . '_Members_' . date('Y-m-d_H-i-s') . '.xlsx';

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    log_activity('exported_excel', "{$list['label']} list, " . count($members) . ' row(s)');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');

    error_log("Excel export completed: " . count($members) . " {$list['label']} records exported by " . ($_SESSION['admin_name'] ?? 'Admin'));
} catch (\Throwable $e) {
    error_log("Excel export error: " . $e->getMessage());
    header("Location: ../../frontend/{$list['page']}?error=" . urlencode(friendly_error($e, 'Making the Excel file')));
    exit();
}
