<?php
/**
 * Backup library functions for BUCuC website.
 * Handles database streaming export, uploads archiving, encryption, health checks, and off-site dispatch.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/../config/backup.php';

/**
 * Streams complete database dump into .sql.gz file.
 */
function backup_database(PDO $pdo, string $destFileGz): int
{
    $gz = gzopen($destFileGz, 'wb9');
    if (!$gz) {
        throw new RuntimeException("Could not open gzip file for writing: $destFileGz");
    }

    $header = "-- BUCuC Database Backup\n"
        . "-- Date: " . date('Y-m-d H:i:s') . "\n"
        . "SET foreign_key_checks = 0;\n"
        . "SET NAMES utf8mb4;\n"
        . "SET time_zone = '+06:00';\n\n";
    gzwrite($gz, $header);

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        gzwrite($gz, "-- --------------------------------------------------------\n");
        gzwrite($gz, "-- Table structure for `$table`\n");
        gzwrite($gz, "-- --------------------------------------------------------\n");
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n");

        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'] ?? '';
        gzwrite($gz, $createSql . ";\n\n");

        // Dump rows in chunks
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count > 0) {
            gzwrite($gz, "-- Dumping data for `$table` ($count rows)\n");
            $chunkSize = 300;
            $offset = 0;

            while ($offset < $count) {
                $rows = $pdo->query("SELECT * FROM `$table` LIMIT $chunkSize OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) break;

                $cols = array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", array_keys($rows[0]));
                $colList = implode(', ', $cols);

                $valClauses = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $pdo->quote($val);
                        }
                    }
                    $valClauses[] = "(" . implode(', ', $vals) . ")";
                }

                gzwrite($gz, "INSERT INTO `$table` ($colList) VALUES\n  " . implode(",\n  ", $valClauses) . ";\n");
                $offset += count($rows);
            }
            gzwrite($gz, "\n");
        }
    }

    gzwrite($gz, "SET foreign_key_checks = 1;\n-- Backup complete\n");
    gzclose($gz);

    return file_exists($destFileGz) ? (int) filesize($destFileGz) : 0;
}

/**
 * Archives media uploads and external site files into a zip file.
 * Includes:
 *   - frontend/images/ (all photos, logos, carousel, webp companions)
 *   - frontend/video/ (hero videos, uploaded mp4s)
 *   - backend/Api/members.json (panels & tenures JSON export)
 *   - backend/Database/schema_marker.txt (if present)
 *   - backend/config/recovery.token (if present)
 * Deliberately excludes:
 *   - backend/backups/ (never recursively zip existing backups)
 *   - backend/cache/ (transient runtime cache)
 *   - backend/logs/ (runtime error/access logs)
 *   - backend/config/*.local.php (server-specific secret passwords/keys)
 *   - backend/Database/config.local.php (server-specific DB password)
 *   - .git/ and tests/runtime/
 */
function backup_files(string $destZip): int
{
    if (!class_exists('ZipArchive')) {
        error_log("ZipArchive class not available; skipping files zip");
        return 0;
    }

    $zip = new ZipArchive();
    if ($zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Could not create zip archive: $destZip");
    }

    $baseDir = dirname(__DIR__, 2);

    // 1. frontend/images
    $imagesDir = $baseDir . '/frontend/images';
    if (is_dir($imagesDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($imagesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = 'frontend/images/' . substr($filePath, strlen($imagesDir) + 1);
                $zip->addFile($filePath, str_replace('\\', '/', $relativePath));
            }
        }
    }

    // 2. frontend/video
    $videoDir = $baseDir . '/frontend/video';
    if (is_dir($videoDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($videoDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = 'frontend/video/' . substr($filePath, strlen($videoDir) + 1);
                $zip->addFile($filePath, str_replace('\\', '/', $relativePath));
            }
        }
    }

    // 3. backend/Api/members.json
    $membersJson = $baseDir . '/backend/Api/members.json';
    if (is_file($membersJson)) {
        $zip->addFile($membersJson, 'backend/Api/members.json');
    }

    // 4. Custom marker if present
    $marker = $baseDir . '/backend/Database/schema_marker.txt';
    if (is_file($marker)) {
        $zip->addFile($marker, 'backend/Database/schema_marker.txt');
    }

    $zip->close();
    return file_exists($destZip) ? (int) filesize($destZip) : 0;
}

/**
 * Heartbeat ping (healthchecks.io style) helper.
 * On success, pings $url. On failure, pings $url/fail.
 */
function backup_ping_heartbeat(?string $url, bool $success = true): void
{
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return;
    $target = $success ? $url : rtrim($url, '/') . '/fail';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'user_agent' => 'BUCuC-Backup-Heartbeat/1.0',
        ]
    ]);
    @file_get_contents($target, false, $ctx);
}

/**
 * Big-endian counter addition for 16-byte IV in AES-CTR mode.
 */
function backup_add_ctr_counter(string $iv, int $blocks): string
{
    $high = substr($iv, 0, 8);
    $low = substr($iv, 8, 8);
    $lowInt = unpack('J', $low)[1];
    $newLow = $lowInt + $blocks;
    return $high . pack('J', $newLow);
}

/**
 * Encrypts a backup file before it leaves the server.
 * Supports:
 *   - Pure-PHP Asymmetric Hybrid Envelope (BUCENC01): RSA-OAEP session key + streaming AES-256-CTR + HMAC-SHA256
 *     (Whole-process physical RAM stays flat at ~20 MB on 150+ MB files).
 *   - age CLI (if BACKUP_AGE_RECIPIENT is set)
 *   - gpg CLI (if BACKUP_GPG_RECIPIENT is set)
 * Note: Symmetric passphrase mode is deprecated and unsupported.
 */
function backup_encrypt_file(string $sourceFile, string $destEncFile, ?string $certOverride = null): bool
{
    if (!is_file($sourceFile)) return false;

    // 1. Pure-PHP Asymmetric Hybrid Envelope (BUCENC01) via X.509 Certificate
    $cert = $certOverride ?: (defined('BACKUP_ENCRYPTION_CERT') ? BACKUP_ENCRYPTION_CERT : '');
    if (!empty($cert)) {
        $certContent = is_file($cert) ? @file_get_contents($cert) : $cert;
        if (!$certContent || !@openssl_x509_read($certContent)) {
            throw new RuntimeException("Invalid or unreadable X.509 certificate for encryption");
        }
        $pubKey = openssl_pkey_get_public($certContent);
        if (!$pubKey) {
            throw new RuntimeException("Failed to extract public key from certificate");
        }

        $sessionKey = random_bytes(32);
        $iv = random_bytes(16);

        if (!openssl_public_encrypt($sessionKey, $encSessionKey, $pubKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException("Failed to encrypt session key with RSA-OAEP: " . openssl_error_string());
        }

        $encKeyRaw = hash('sha256', $sessionKey . 'enc', true);
        $macKeyRaw = hash('sha256', $sessionKey . 'mac', true);
        $headerMacRaw = hash('sha256', $sessionKey . 'header_mac', true);

        $fpIn = fopen($sourceFile, 'rb');
        $fpOut = fopen($destEncFile, 'wb');
        if (!$fpIn || !$fpOut) {
            if ($fpIn) fclose($fpIn);
            if ($fpOut) fclose($fpOut);
            @unlink($destEncFile);
            throw new RuntimeException("Cannot open file stream for streaming encryption");
        }

        $sourceSize = filesize($sourceFile);

        // Header: Magic 'BUCENC01' (8) + KeyLen (4 uint32 BE) + EncKey + PayloadLen (8 uint64 BE) + IV (16) + HeaderHMAC (32)
        $headerBody = "BUCENC01" . pack('N', strlen($encSessionKey)) . $encSessionKey . pack('J', $sourceSize) . $iv;
        $headerMac = hash_hmac('sha256', $headerBody, $headerMacRaw, true);

        fwrite($fpOut, $headerBody);
        fwrite($fpOut, $headerMac);

        $hmacCtx = hash_init('sha256', HASH_HMAC, $macKeyRaw);
        $curIv = $iv;
        $chunkSize = 65536;

        while (!feof($fpIn)) {
            $chunk = fread($fpIn, $chunkSize);
            if ($chunk === false || $chunk === '') break;
            $c = openssl_encrypt($chunk, 'aes-256-ctr', $encKeyRaw, OPENSSL_RAW_DATA, $curIv);
            if ($c === false) {
                fclose($fpIn);
                fclose($fpOut);
                @unlink($destEncFile);
                throw new RuntimeException("Chunk encryption failed: " . openssl_error_string());
            }
            $blocks = (int) ceil(strlen($chunk) / 16);
            $curIv = backup_add_ctr_counter($curIv, $blocks);
            hash_update($hmacCtx, $c);
            fwrite($fpOut, $c);
        }

        // Trailer: 32 bytes HMAC-SHA256
        $mac = hash_final($hmacCtx, true);
        fwrite($fpOut, $mac);

        fclose($fpIn);
        fclose($fpOut);

        if (file_exists($destEncFile) && filesize($destEncFile) > 0) {
            return true;
        }
        throw new RuntimeException("Streaming encryption failed to write destination file $destEncFile");
    }

    // 2. age CLI
    if (defined('BACKUP_AGE_RECIPIENT') && BACKUP_AGE_RECIPIENT !== '') {
        $recipient = escapeshellarg(BACKUP_AGE_RECIPIENT);
        $src = escapeshellarg($sourceFile);
        $dst = escapeshellarg($destEncFile);
        $cmd = "age -r $recipient -o $dst $src 2>&1";
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($destEncFile) && filesize($destEncFile) > 0) {
            return true;
        }
        throw new RuntimeException("age encryption failed (code $code): " . implode("\n", $out));
    }

    // 3. gpg CLI
    if (defined('BACKUP_GPG_RECIPIENT') && BACKUP_GPG_RECIPIENT !== '') {
        $recipient = escapeshellarg(BACKUP_GPG_RECIPIENT);
        $src = escapeshellarg($sourceFile);
        $dst = escapeshellarg($destEncFile);
        $cmd = "gpg --batch --yes --encrypt --recipient $recipient -o $dst $src 2>&1";
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($destEncFile) && filesize($destEncFile) > 0) {
            return true;
        }
        throw new RuntimeException("gpg encryption failed (code $code): " . implode("\n", $out));
    }

    // 4. Deprecated Passphrase Mode (Unsupported)
    if (defined('BACKUP_ENCRYPTION_PASSPHRASE') && BACKUP_ENCRYPTION_PASSPHRASE !== '') {
        throw new RuntimeException("Symmetric passphrase encryption is unsupported and deprecated. Please configure BACKUP_ENCRYPTION_CERT for asymmetric encryption, or use age / gpg.");
    }

    return false;
}

/**
 * Decrypts an encrypted backup file using the matching private key.
 * Supports:
 *   - Pure-PHP BUCENC01 streaming envelope (with optional private key passphrase)
 *   - Legacy OpenSSL CMS / PKCS7 fallback
 *   - age CLI
 *   - gpg CLI
 */
function backup_decrypt_file(string $encFile, string $destDecryptedFile, string $privateKey, ?string $cert = null, string $passphrase = ''): bool
{
    if (!is_file($encFile)) return false;

    // 1. Pure-PHP BUCENC01 Streaming Envelope
    $fpIn = @fopen($encFile, 'rb');
    if ($fpIn) {
        $magic = fread($fpIn, 8);
        if ($magic === 'BUCENC01') {
            $keyLenBuf = fread($fpIn, 4);
            if (strlen($keyLenBuf) < 4) {
                fclose($fpIn);
                throw new RuntimeException("Encrypted archive is truncated or corrupted");
            }
            $keyLen = unpack('N', $keyLenBuf)[1];
            if ($keyLen < 64 || $keyLen > 1024) {
                fclose($fpIn);
                throw new RuntimeException("Invalid key length in archive header");
            }
            $encSessionKey = fread($fpIn, $keyLen);
            $payloadLenBuf = fread($fpIn, 8);
            $iv = fread($fpIn, 16);
            $headerMac = fread($fpIn, 32);
            if (strlen($encSessionKey) < $keyLen || strlen($payloadLenBuf) < 8 || strlen($iv) < 16 || strlen($headerMac) < 32) {
                fclose($fpIn);
                throw new RuntimeException("Encrypted archive header is truncated");
            }

            $payloadLen = unpack('J', $payloadLenBuf)[1];

            $privKeyContent = is_file($privateKey) ? @file_get_contents($privateKey) : $privateKey;
            $privKey = openssl_pkey_get_private($privKeyContent, $passphrase);
            if (!$privKey) {
                fclose($fpIn);
                throw new RuntimeException("Invalid private key or incorrect passphrase");
            }

            if (!openssl_private_decrypt($encSessionKey, $sessionKey, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                fclose($fpIn);
                throw new RuntimeException("Failed to decrypt session key with RSA-OAEP: " . openssl_error_string());
            }

            $encKeyRaw = hash('sha256', $sessionKey . 'enc', true);
            $macKeyRaw = hash('sha256', $sessionKey . 'mac', true);
            $headerMacRaw = hash('sha256', $sessionKey . 'header_mac', true);

            // Verify header HMAC
            $headerBody = "BUCENC01" . $keyLenBuf . $encSessionKey . $payloadLenBuf . $iv;
            $expectedHeaderMac = hash_hmac('sha256', $headerBody, $headerMacRaw, true);
            if (!hash_equals($expectedHeaderMac, $headerMac)) {
                fclose($fpIn);
                throw new RuntimeException("Header HMAC integrity check failed: archive header was tampered with or corrupted");
            }

            $fileSize = filesize($encFile);
            $headerLen = 8 + 4 + $keyLen + 8 + 16 + 32;
            $trailerLen = 32;
            $expectedTotalSize = $headerLen + $payloadLen + $trailerLen;

            if ($fileSize < $expectedTotalSize) {
                fclose($fpIn);
                throw new RuntimeException("Encrypted archive is truncated or corrupted");
            }

            // Write to a temporary file until HMAC is verified
            $destTmp = $destDecryptedFile . '.tmp.' . bin2hex(random_bytes(8));
            $fpOut = fopen($destTmp, 'wb');
            if (!$fpOut) {
                fclose($fpIn);
                return false;
            }

            $hmacCtx = hash_init('sha256', HASH_HMAC, $macKeyRaw);
            $curIv = $iv;
            $chunkSize = 65536;
            $bytesRemaining = $payloadLen;

            while ($bytesRemaining > 0 && !feof($fpIn)) {
                $readSize = min($chunkSize, $bytesRemaining);
                $c = fread($fpIn, $readSize);
                if ($c === false || $c === '') break;
                $bytesRemaining -= strlen($c);
                hash_update($hmacCtx, $c);

                $chunk = openssl_decrypt($c, 'aes-256-ctr', $encKeyRaw, OPENSSL_RAW_DATA, $curIv);
                $blocks = (int) ceil(strlen($chunk) / 16);
                $curIv = backup_add_ctr_counter($curIv, $blocks);
                fwrite($fpOut, $chunk);
            }

            if ($bytesRemaining > 0) {
                fclose($fpIn);
                fclose($fpOut);
                @unlink($destTmp);
                throw new RuntimeException("Encrypted archive is truncated in ciphertext");
            }

            $expectedMac = hash_final($hmacCtx, true);
            $actualMac = fread($fpIn, 32);

            // Check for extra trailing bytes
            $extra = fread($fpIn, 1024);
            $hasExtraBytes = ($extra !== '' && $extra !== false) || ($fileSize > $expectedTotalSize);

            fclose($fpIn);
            fclose($fpOut);

            if ($hasExtraBytes) {
                @unlink($destTmp);
                throw new RuntimeException("Encrypted archive contains extra trailing bytes after trailer");
            }

            if (!hash_equals($expectedMac, $actualMac)) {
                @unlink($destTmp);
                throw new RuntimeException("HMAC integrity check failed: backup archive payload has been tampered with or corrupted");
            }

            if (!@rename($destTmp, $destDecryptedFile)) {
                @unlink($destTmp);
                throw new RuntimeException("Failed to atomically move decrypted output to destination");
            }

            return file_exists($destDecryptedFile) && is_file($destDecryptedFile);
        }
        fclose($fpIn);
    }

    // 2. Fallback to OpenSSL CMS / PKCS7 (Legacy .enc files)
    $privKeyContent = is_file($privateKey) ? @file_get_contents($privateKey) : $privateKey;
    if ($privKeyContent && ($pk = openssl_pkey_get_private($privKeyContent, $passphrase))) {
        $certContent = $cert ? (is_file($cert) ? @file_get_contents($cert) : $cert) : null;
        $ok = @openssl_cms_decrypt($encFile, $destDecryptedFile, $certContent, $pk);
        if (!$ok) {
            $ok = @openssl_pkcs7_decrypt($encFile, $destDecryptedFile, $certContent, $pk);
        }
        if ($ok && file_exists($destDecryptedFile) && filesize($destDecryptedFile) > 0) {
            return true;
        }
    }

    // 3. age CLI
    if (is_file($privateKey)) {
        $src = escapeshellarg($encFile);
        $dst = escapeshellarg($destDecryptedFile);
        $key = escapeshellarg($privateKey);
        $cmd = "age --decrypt -i $key -o $dst $src 2>&1";
        @exec($cmd, $out, $code);
        if ($code === 0 && file_exists($destDecryptedFile) && filesize($destDecryptedFile) > 0) {
            return true;
        }
    }

    // 4. gpg CLI
    $src = escapeshellarg($encFile);
    $dst = escapeshellarg($destDecryptedFile);
    $cmd = "gpg --batch --yes --decrypt -o $dst $src 2>&1";
    @exec($cmd, $out, $code);
    if ($code === 0 && file_exists($destDecryptedFile) && filesize($destDecryptedFile) > 0) {
        return true;
    }

    return false;
}

/**
 * Prunes backups older than $days in the backup directory.
 */
function backup_prune(string $backupDir, int $days): array
{
    $pruned = [];
    if (!is_dir($backupDir)) return $pruned;

    $cutoff = time() - ($days * 86400);
    $files = scandir($backupDir);

    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
        $full = $backupDir . '/' . $f;
        if (is_file($full)) {
            $mtime = filemtime($full);
            if ($mtime < $cutoff && preg_match('/^(backup_|files_)/', $f)) {
                if (@unlink($full)) {
                    $pruned[] = $f;
                }
            }
        }
    }

    return $pruned;
}

/**
 * Checks that the latest backup exists, is <= 26 hours old, and >= 1 KB.
 * Verifies both database dumps (backup_*.sql.gz[.enc]) and media archives (files_*.zip[.enc]).
 * If not, triggers an alert email to the Super Admin.
 */
function backup_health_check(string $backupDir, bool $bypassAlertRateLimit = false): array
{
    $latestDbFile = null;
    $latestDbTime = 0;
    $latestDbSize = 0;

    $latestFilesFile = null;
    $latestFilesTime = 0;
    $latestFilesSize = 0;

    if (is_dir($backupDir)) {
        foreach (scandir($backupDir) as $f) {
            $full = $backupDir . '/' . $f;
            if (!is_file($full)) continue;

            if (preg_match('/^backup_.*\.sql\.gz(\.enc)?$/', $f)) {
                $mtime = filemtime($full);
                if ($mtime > $latestDbTime) {
                    $latestDbTime = $mtime;
                    $latestDbFile = $f;
                    $latestDbSize = (int) filesize($full);
                }
            } elseif (preg_match('/^files_.*\.zip(\.enc)?$/', $f)) {
                $mtime = filemtime($full);
                if ($mtime > $latestFilesTime) {
                    $latestFilesTime = $mtime;
                    $latestFilesFile = $f;
                    $latestFilesSize = (int) filesize($full);
                }
            }
        }
    }

    $now = time();
    $maxAge = 26 * 3600; // 26 hours
    $healthy = true;
    $issues = [];

    // Verify database backup
    if (!$latestDbFile) {
        $healthy = false;
        $issues[] = "No database backup (backup_*.sql.gz[.enc]) found in ($backupDir).";
    } else {
        $ageHours = round(($now - $latestDbTime) / 3600, 1);
        if (($now - $latestDbTime) > $maxAge) {
            $healthy = false;
            $issues[] = "Latest database backup ($latestDbFile) is $ageHours hours old (expected < 26 hours).";
        }
        if ($latestDbSize < BACKUP_MIN_SIZE) {
            $healthy = false;
            $issues[] = "Latest database backup ($latestDbFile) is only $latestDbSize bytes (minimum required: " . BACKUP_MIN_SIZE . " bytes).";
        }
    }

    // Verify files archive if one exists
    if ($latestFilesFile) {
        $filesAgeHours = round(($now - $latestFilesTime) / 3600, 1);
        if (($now - $latestFilesTime) > $maxAge) {
            $healthy = false;
            $issues[] = "Latest files archive ($latestFilesFile) is $filesAgeHours hours old (expected < 26 hours).";
        }
        if ($latestFilesSize < BACKUP_MIN_SIZE) {
            $healthy = false;
            $issues[] = "Latest files archive ($latestFilesFile) is only $latestFilesSize bytes (minimum required: " . BACKUP_MIN_SIZE . " bytes).";
        }
    }

    // Security check: alert if BACKUP_DIR is inside web root on live server
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
    $realBackup = realpath($backupDir) ?: $backupDir;
    if ($docRoot && strpos($realBackup, $docRoot) === 0 && !$bypassAlertRateLimit) {
        $secMsg = "BACKUP SECURITY WARNING: Backup directory ($realBackup) is located inside public web root ($docRoot).\n\n"
            . "While protected by .htaccess, please move backups outside public_html on Hostinger (e.g. /home/<user>/backups) "
            . "by setting BACKUP_DIR in backend/config/backup.local.php.";
        site_alert_super_admin('Backup Security Alert', $secMsg, false);
    }

    if (!$healthy) {
        $msg = "DAILY BACKUP ALERT: The backup check found the following issue(s):\n\n"
            . implode("\n", $issues) . "\n\n"
            . "Please verify server cron and database connectivity.";
        site_alert_super_admin('Daily Backup Check Failed', $msg, $bypassAlertRateLimit);
    }

    return [
        'healthy'         => $healthy,
        'latestFile'      => $latestDbFile ?: $latestFilesFile,
        'latestSize'      => $latestDbFile ? $latestDbSize : $latestFilesSize,
        'latestTime'      => $latestDbTime ? date('Y-m-d H:i:s', $latestDbTime) : null,
        'latestDbFile'    => $latestDbFile,
        'latestDbSize'    => $latestDbSize,
        'latestFilesFile' => $latestFilesFile,
        'latestFilesSize' => $latestFilesSize,
        'issues'          => $issues,
    ];
}

/**
 * Uploads a file to an S3-compatible bucket (Backblaze B2 / Cloudflare R2 / AWS S3)
 * using pure PHP over HTTPS via AWS Signature Version 4. Zero external libraries required.
 * Verifies uploaded size via authenticated S3 HEAD request and handles slow transfer timeouts.
 */
function backup_upload_s3(string $filePath): bool
{
    if (!is_file($filePath)) return false;

    $endpoint  = defined('BACKUP_S3_ENDPOINT') ? rtrim(BACKUP_S3_ENDPOINT, '/') : '';
    $bucket    = defined('BACKUP_S3_BUCKET') ? BACKUP_S3_BUCKET : '';
    $region    = defined('BACKUP_S3_REGION') ? BACKUP_S3_REGION : 'auto';
    $accessKey = defined('BACKUP_S3_KEY_ID') ? BACKUP_S3_KEY_ID : '';
    $secretKey = defined('BACKUP_S3_SECRET_KEY') ? BACKUP_S3_SECRET_KEY : '';
    $prefix    = defined('BACKUP_S3_PREFIX') ? trim(BACKUP_S3_PREFIX, '/') . '/' : '';

    if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
        error_log("backup_upload_s3: Missing required S3 credentials in backup.local.php");
        return false;
    }

    $fileName = basename($filePath);
    $objectKey = $prefix . $fileName;

    $parsed = parse_url($endpoint);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';
    if (isset($parsed['port']) && $parsed['port']) {
        $host .= ':' . $parsed['port'];
    }
    $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';

    $requestUri = $basePath . '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($objectKey));
    $fullUrl = $scheme . '://' . $host . $requestUri;

    $fileSize = (int) filesize($filePath);
    $contentHash = hash_file('sha256', $filePath);

    // Timeout calculation for slow connections (e.g. 2 Mbit/s = ~250 KB/s + buffer)
    $defaultTimeout = max(300, (int) ceil($fileSize / (250 * 1024)) + 120);
    $timeout = defined('BACKUP_S3_TIMEOUT') ? (int) BACKUP_S3_TIMEOUT : $defaultTimeout;

    $now = time();
    $amzDate = gmdate('Ymd\THis\Z', $now);
    $dateStamp = gmdate('Ymd', $now);

    $canonicalHeaders = "host:" . $host . "\n"
                      . "x-amz-content-sha256:" . $contentHash . "\n"
                      . "x-amz-date:" . $amzDate . "\n";
    $signedHeaders = "host;x-amz-content-sha256;x-amz-date";

    $canonicalRequest = "PUT\n"
                      . $requestUri . "\n"
                      . "\n"
                      . $canonicalHeaders . "\n"
                      . $signedHeaders . "\n"
                      . $contentHash;

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "$dateStamp/$region/s3/aws4_request";
    $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

    $kSecret = 'AWS4' . $secretKey;
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "$algorithm Credential=$accessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

    $httpHeaders = [
        "Host: $host",
        "x-amz-date: $amzDate",
        "x-amz-content-sha256: $contentHash",
        "Authorization: $authorization",
        "Content-Length: $fileSize",
        "Content-Type: application/octet-stream"
    ];

    $fp = fopen($filePath, 'rb');
    if (!$fp) return false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_PUT => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $fileSize,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => !defined('BACKUP_S3_SKIP_SSL') || !BACKUP_S3_SKIP_SSL,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($resp === false || $curlErrNo !== 0) {
        if ($curlErrNo === 28 /* CURLE_OPERATION_TIMEDOUT */ || strpos(strtolower($curlErr), 'timed out') !== false) {
            $timeoutMsg = "Offsite S3 upload timed out for $fileName after $timeout seconds (size: " . round($fileSize / 1024 / 1024, 2) . " MB).\n"
                . "Estimated upload speed fell below required threshold. The upload will be retried on the next scheduled run.\n"
                . "Error details: $curlErr";
            error_log("backup_upload_s3 timeout: $timeoutMsg");
            site_alert_super_admin('BUCuC Backup Upload Timeout', $timeoutMsg, true);
            return false;
        }
        $errGeneral = "Offsite S3 upload failed for $fileName: $curlErr (code $curlErrNo)";
        error_log("backup_upload_s3: $errGeneral");
        site_alert_super_admin('BUCuC Backup Upload Failure', $errGeneral, true);
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $failMsg = "Offsite S3 upload rejected for $fileName (HTTP $httpCode): " . substr((string) $resp, 0, 300);
        error_log("backup_upload_s3: $failMsg");
        site_alert_super_admin('BUCuC Backup Upload Failure', $failMsg, true);
        return false;
    }

    // Step 2: S3 HEAD verification to confirm uploaded file size
    $headNow = time();
    $headAmzDate = gmdate('Ymd\THis\Z', $headNow);
    $headDateStamp = gmdate('Ymd', $headNow);
    $headEmptyHash = hash('sha256', '');

    $headCanonicalHeaders = "host:" . $host . "\n"
                          . "x-amz-content-sha256:" . $headEmptyHash . "\n"
                          . "x-amz-date:" . $headAmzDate . "\n";
    $headSignedHeaders = "host;x-amz-content-sha256;x-amz-date";

    $headCanonicalRequest = "HEAD\n"
                          . $requestUri . "\n"
                          . "\n"
                          . $headCanonicalHeaders . "\n"
                          . $headSignedHeaders . "\n"
                          . $headEmptyHash;

    $headScope = "$headDateStamp/$region/s3/aws4_request";
    $headStringToSign = "$algorithm\n$headAmzDate\n$headScope\n" . hash('sha256', $headCanonicalRequest);

    $headKDate = hash_hmac('sha256', $headDateStamp, $kSecret, true);
    $headKRegion = hash_hmac('sha256', $region, $headKDate, true);
    $headKService = hash_hmac('sha256', 's3', $headKRegion, true);
    $headKSigning = hash_hmac('sha256', 'aws4_request', $headKService, true);
    $headSignature = hash_hmac('sha256', $headStringToSign, $headKSigning);

    $headAuth = "$algorithm Credential=$accessKey/$headScope, SignedHeaders=$headSignedHeaders, Signature=$headSignature";

    $headHeaders = [
        "Host: $host",
        "x-amz-date: $headAmzDate",
        "x-amz-content-sha256: $headEmptyHash",
        "Authorization: $headAuth",
    ];

    $chHead = curl_init();
    curl_setopt_array($chHead, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => !defined('BACKUP_S3_SKIP_SSL') || !BACKUP_S3_SKIP_SSL,
    ]);

    $headResp = curl_exec($chHead);
    $headCode = curl_getinfo($chHead, CURLINFO_HTTP_CODE);
    $remoteSize = (int) curl_getinfo($chHead, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($chHead);

    if ($remoteSize <= 0 && is_string($headResp)) {
        if (preg_match('/content-length:\s*(\d+)/i', $headResp, $m)) {
            $remoteSize = (int) $m[1];
        }
    }

    if ($headCode !== 200) {
        $failMsg = "S3 HEAD size verification failed for $fileName (HTTP $headCode).";
        error_log("backup_upload_s3: $failMsg");
        site_alert_super_admin('Backup S3 Verification Failure', $failMsg, true);
        return false;
    }

    if ($remoteSize !== $fileSize) {
        $mismatchMsg = "S3 upload size mismatch for $fileName: local size is $fileSize bytes, remote size is $remoteSize bytes.";
        error_log("backup_upload_s3: $mismatchMsg");
        site_alert_super_admin('Backup S3 Size Mismatch', $mismatchMsg, true);
        return false;
    }

    return true;
}

/**
 * Offsite upload dispatcher.
 * Supports:
 *   - 's3', 'r2', 'b2' (pure-PHP HTTPS using AWS SigV4, recommended for Hostinger)
 *   - 'rclone' (unverified on Hostinger shared hosting)
 *   - 'scp' (unverified on Hostinger shared hosting)
 * Refuses to upload any unencrypted backup off-site.
 */
function backup_upload_offsite(string $filePath): bool
{
    $method = BACKUP_OFFSITE_METHOD;
    $target = BACKUP_OFFSITE_TARGET;

    if ($method === 'none') {
        return false;
    }

    // Security check: Refuse to upload unencrypted backup files offsite
    if (!preg_match('/\.enc$/', $filePath)) {
        $secMsg = "SECURITY VIOLATION: Refusing to upload unencrypted backup file ($filePath) off-site.\n"
            . "Offsite destination ($method) requires end-to-end encryption to protect sensitive data.";
        error_log($secMsg);
        site_alert_super_admin('Unencrypted Backup Upload Refused', $secMsg, true);
        return false;
    }

    if ($method === 's3' || $method === 'r2' || $method === 'b2') {
        return backup_upload_s3($filePath);
    }

    if ($method === 'rclone') {
        if (empty($target)) return false;
        $cmd = 'rclone copy ' . escapeshellarg($filePath) . ' ' . escapeshellarg($target);
        exec($cmd, $out, $ret);
        return $ret === 0;
    }

    if ($method === 'scp') {
        if (empty($target)) return false;
        $keyOpt = BACKUP_OFFSITE_KEY ? '-i ' . escapeshellarg(BACKUP_OFFSITE_KEY) . ' ' : '';
        $cmd = 'scp ' . $keyOpt . escapeshellarg($filePath) . ' ' . escapeshellarg($target);
        exec($cmd, $out, $ret);
        return $ret === 0;
    }

    return false;
}
