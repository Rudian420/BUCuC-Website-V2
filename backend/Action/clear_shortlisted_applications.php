<?php
/**
 * Wipes shortlisted_members. Super Admin only. Rows are archived to
 * backend/logs/cleared_shortlisted.log before deletion.
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


$input        = json_decode(file_get_contents('php://input'), true);
$action       = $input['action']       ?? '';
$confirmation = $input['confirmation'] ?? '';

if ($action !== 'clear_shortlisted_applications' || $confirmation !== 'CLEAR ALL SHORTLISTED') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action or confirmation text. Please type "CLEAR ALL SHORTLISTED" exactly.'
    ]);
    exit();
}

try {
    $database = new Database();
    $pdo      = $database->createConnection();

    $allRows = $pdo->query("SELECT * FROM shortlisted_members ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (count($allRows) === 0) {
        echo json_encode(['success' => false, 'message' => 'No shortlisted applications to clear.']);
        exit();
    }

    // Archive to JSON log before deleting — no data is ever silently lost
    $logEntry = json_encode([
        'cleared_at'     => date('Y-m-d H:i:s'),
        'cleared_by'     => $_SESSION['username'] ?? 'unknown',
        'records_count'  => count($allRows),
        'records'        => $allRows,
    ]) . PHP_EOL;
    if (file_put_contents(__DIR__ . '/../logs/cleared_shortlisted.log', $logEntry, FILE_APPEND | LOCK_EX) === false) {
        throw new Exception('Could not write the archive log (backend/logs). Nothing was deleted.');
    }

    $deleteStmt = $pdo->prepare("DELETE FROM shortlisted_members");
    $deleteStmt->execute();
    $deletedRows = $deleteStmt->rowCount();
    log_activity('cleared_shortlist', "$deletedRows shortlisted application(s) (archived)");

    echo json_encode([
        'success'         => true,
        'message'         => "Successfully cleared {$deletedRows} shortlisted application(s). Records archived to server log.",
        'records_deleted' => $deletedRows,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
