<?php

/**
 * Google Sheets Integration
 * Sends a newly accepted member to the Google Apps Script web app configured in
 * backend/config/google_sheets_config.php. Included by frontend/handle_application.php.
 */

require_once __DIR__ . '/../config/google_sheets_config.php';
require_once __DIR__ . '/../includes/site.php';   // sheet_safe()

/**
 * Send member data to Google Sheets
 * @param array $member - Member data from database
 * @return array - Result with success status and message
 */
function sendToGoogleSheets($member) {
    if (!function_exists('curl_init')) {
        return array('success' => false, 'message' => 'PHP cURL extension is not enabled');
    }

    // Required columns: "Name", "ID", "G-Suite", "Position", "Facebook", "Phone", "FirstP", "SecondP"
    $sheetData = array(
        'Name' => $member['full_name'],
        'ID' => $member['university_id'],
        'G-Suite' => $member['gsuite_email'] ?: $member['email'],
        'Position' => $member['department'],
        'Facebook' => $member['facebook_url'] ?: '',
        'Phone' => $member['phone'],
        'FirstP' => $member['firstPriority'],
        'SecondP' => $member['secondPriority']
    );
    $sheetData = array_map('sheet_safe', $sheetData);   // applicant text is never run as a formula

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => GOOGLE_SHEETS_WEBAPP_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($sheetData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => GOOGLE_SHEETS_TIMEOUT,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'User-Agent: BUCUC-Application-System/1.0'
        )
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return array(
            'success' => false,
            'message' => 'Failed to connect to Google Sheets: ' . $error
        );
    }

    if ($httpCode !== 200) {
        return array(
            'success' => false,
            'message' => 'Google Sheets API returned HTTP code: ' . $httpCode . '. Response: ' . substr((string) $response, 0, 200)
        );
    }

    $responseData = json_decode($response, true);

    if ($responseData && isset($responseData['success'])) {
        if ($responseData['success']) {
            return array(
                'success' => true,
                'message' => 'Successfully added to Google Sheets'
            );
        }
        return array(
            'success' => false,
            'message' => 'Google Sheets error: ' . ($responseData['message'] ?? 'Unknown error')
        );
    }

    if ($responseData && isset($responseData['ok']) && $responseData['ok'] === false) {
        return array(
            'success' => false,
            'message' => 'Google Sheets error: ' . ($responseData['error'] ?? 'Unknown error')
        );
    }

    // Some Apps Script deployments answer with plain text; a 200 means the row was written
    return array(
        'success' => true,
        'message' => 'Successfully sent to Google Sheets (Response: ' . substr((string) $response, 0, 100) . ')'
    );
}

/**
 * Append a JSON line to backend/logs/google_sheets.log (when logging is enabled)
 * @param string $action - The action being performed
 * @param array $data - Data being sent
 * @param array $result - Result from the operation
 */
function logGoogleSheetsOperation($action, $data, $result) {
    if (!GOOGLE_SHEETS_LOGGING) {
        return;
    }

    $logEntry = array(
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'data' => $data,
        'result' => $result
    );

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logDir . '/google_sheets.log', json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
