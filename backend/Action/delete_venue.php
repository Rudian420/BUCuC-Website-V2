<?php
require_once '../includes/auth.php';
header('Content-Type: application/json');

// Admins who can give final approval; POSTs must carry the X-CSRF-Token header
require_admin(['can' => 'final_approve', 'json' => true]);

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}


// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$venue_id = $input['venue_id'] ?? 0;

if (empty($venue_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing venue ID']);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->createConnection();
    
    // Delete venue from database
    $stmt = $pdo->prepare("DELETE FROM venuinfo WHERE venue_id = ?");
    $result = $stmt->execute([$venue_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        log_activity('venue_deleted', "Venue #$venue_id");
        echo json_encode(['success' => true, 'message' => 'Venue information deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Venue not found or already deleted']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => friendly_error($e, 'Deleting the venue')]);
}
?>
