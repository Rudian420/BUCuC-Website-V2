<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Closed during a site lockdown
require_once __DIR__ . '/../includes/auth.php';
site_guard_public(true);

// Load the JSON data
$jsonData = file_get_contents('members.json');
$membersData = json_decode($jsonData, true);

// Get query parameters
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$type = isset($_GET['type']) ? $_GET['type'] : null; // 'panel_members' or 'secretaries'
$department = isset($_GET['department']) ? $_GET['department'] : null;

// Filter data based on parameters
if ($year) {
    $filteredData = [];
    foreach ($membersData['years'] as $yearData) {
        if ($yearData['year'] == $year) {
            if ($type) {
                if (isset($yearData[$type])) {
                    $filteredData = $yearData[$type];
                    if ($department) {
                        $filteredData = array_filter($filteredData, function($member) use ($department) {
                            return isset($member['department']) && $member['department'] === $department;
                        });
                        $filteredData = array_values($filteredData); // Re-index array
                    }
                }
            } else {
                $filteredData = $yearData;
            }
            break;
        }
    }
    
    if (empty($filteredData)) {
        http_response_code(404);
        echo json_encode(['error' => 'No data found for the specified parameters']);
        exit;
    }
    
    echo json_encode($filteredData);
} else {
    // Return all data
    echo json_encode($membersData);
}
?> 