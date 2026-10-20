<?php
/**
 * Wipes pending_applications, shortlisted_members AND members (approved) for a new
 * recruitment season. Super Admin only. Every row is archived to
 * backend/logs/cleared_applications.log before deletion.
 */
require_once '../includes/auth.php';
header('Content-Type: application/json');

// Super Admin and Panel Admin; POSTs must carry the X-CSRF-Token header
require_admin(['can' => 'manage_site', 'json' => true]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}


$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$confirmation = $input['confirmation'] ?? '';

if ($action !== 'clear_all_applications' || $confirmation !== 'CLEAR ALL APPLICATIONS') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action or confirmation text. Please type "CLEAR ALL APPLICATIONS" exactly.'
    ]);
    exit();
}

$tables = ['pending_applications', 'shortlisted_members', 'members'];

try {
    $database = new Database();
    $pdo = $database->createConnection();

    $archive = [];
    $counts = [];
    foreach ($tables as $table) {
        $archive[$table] = $pdo->query("SELECT * FROM $table ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $counts[$table] = count($archive[$table]);
    }
    $totalRecords = array_sum($counts);

    if ($totalRecords == 0) {
        echo json_encode(['success' => false, 'message' => 'No applications to clear. All tables are already empty.']);
        exit();
    }

    // Archive first so nothing is ever silently lost
    $logEntry = json_encode([
        'cleared_at'    => date('Y-m-d H:i:s'),
        'cleared_by'    => $_SESSION['username'] ?? 'unknown',
        'records_count' => $counts,
        'records'       => $archive,
    ]) . PHP_EOL;
    if (file_put_contents(__DIR__ . '/../logs/cleared_applications.log', $logEntry, FILE_APPEND | LOCK_EX) === false) {
        throw new Exception('Could not write the archive log (backend/logs). Nothing was deleted.');
    }

    $pdo->beginTransaction();
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM $table");
    }
    $pdo->commit();

    // ALTER TABLE commits implicitly in MySQL, so it must run after the transaction
    foreach ($tables as $table) {
        $pdo->exec("ALTER TABLE $table AUTO_INCREMENT = 1");
    }

    log_activity('cleared_all_applications', "Pending {$counts['pending_applications']}, shortlisted {$counts['shortlisted_members']}, approved {$counts['members']} (archived)");

    echo json_encode([
        'success' => true,
        'message' => "Successfully cleared {$totalRecords} record(s) (Pending: {$counts['pending_applications']}, Shortlisted: {$counts['shortlisted_members']}, Approved: {$counts['members']}). Records archived to server log.",
        'records_deleted' => $totalRecords
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
