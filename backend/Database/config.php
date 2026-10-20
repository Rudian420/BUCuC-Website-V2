<?php
// Database settings. The real values live in the git-ignored config.local.php next to this file
// (upload it by hand to the server), so no password is ever committed:
//   <?php
//   define('DB_HOST', 'localhost');
//   define('DB_NAME', 'u106340611_bucuc');
//   define('DB_USER', 'u106340611_bucuc_2026');
//   define('DB_PASS', 'the database password from hPanel');
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'u106340611_bucuc');
defined('DB_USER') || define('DB_USER', 'u106340611_bucuc_2026');
defined('DB_PASS') || define('DB_PASS', '');   // no password here on purpose: see config.local.php
