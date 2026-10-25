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
        'action' => 'add',
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

    $secret = defined('MEMBER_SHEET_SECRET') ? MEMBER_SHEET_SECRET : '';
    if ($secret === '') {
        error_log("Warning: MEMBER_SHEET_SECRET is not configured; Google Sheets add operation may fail authentication.");
    }
    $sheetData['secret'] = $secret;

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
        site_alert_super_admin('Google Sheets Connection Error', "Failed to connect to Google Sheets: " . $error);
        return array(
            'success' => false,
            'message' => 'Failed to connect to Google Sheets: ' . $error
        );
    }

    if ($httpCode !== 200) {
        site_alert_super_admin('Google Sheets HTTP Error', "Google Sheets returned HTTP code: " . $httpCode);
        return array(
            'success' => false,
            'message' => 'Google Sheets API returned HTTP code: ' . $httpCode . '. Response: ' . substr((string) $response, 0, 200)
        );
    }

    $responseData = json_decode($response, true);

    if (is_array($responseData)) {
        if (!empty($responseData['success'])) {
            return array(
                'success' => true,
                'message' => 'Successfully added to Google Sheets'
            );
        }
        $errMsg = $responseData['error'] ?? ($responseData['message'] ?? 'Apps Script reported success=false');
        site_alert_super_admin('Google Sheets Integration Error', "Google Sheets add member failed: " . $errMsg);
        return array(
            'success' => false,
            'message' => 'Google Sheets error: ' . $errMsg
        );
    }

    // Response is not JSON
    site_alert_super_admin('Google Sheets Format Error', "Google Sheets did not return JSON success flag");
    return array(
        'success' => false,
        'message' => 'Google Sheets invalid response format: ' . substr((string) $response, 0, 100)
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

    // Never write the shared secret to any log file
    if (is_array($data) && isset($data['secret'])) {
        $data['secret'] = '[REDACTED]';
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
