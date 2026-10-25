<?php
/**
 * Google Sheets Configuration
 * Configure your Google Apps Script Web App settings here
 */

// Google Apps Script Web App URL
defined('GOOGLE_SHEETS_WEBAPP_URL') || define('GOOGLE_SHEETS_WEBAPP_URL', 'https://script.google.com/macros/s/AKfycbyPKki88NP8D_geycHZpzNgwiC-U0l-y9--sfJg1bQ2AOW7hjcuy7MNYZU-VTPg9Hxr/exec');

// Timeout for Google Sheets API requests (in seconds)
defined('GOOGLE_SHEETS_TIMEOUT') || define('GOOGLE_SHEETS_TIMEOUT', 30);

// Whether to enable detailed logging
defined('GOOGLE_SHEETS_LOGGING') || define('GOOGLE_SHEETS_LOGGING', true);

// Column mappings - adjust these if your Google Sheet columns are different
$GOOGLE_SHEETS_COLUMN_MAPPING = array(
    'Name' => 'full_name',
    'ID' => 'university_id',
    'G-Suite' => 'gsuite_email', // Will fallback to 'email' if gsuite_email is empty
    'Position' => 'department',  // Using department as position - you can change this
    'Facebook' => 'facebook_url',
    'Phone' => 'phone',
    'FirstP' => 'firstPriority',
    'SecondP' => 'secondPriority'
);

?>
