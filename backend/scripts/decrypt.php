<?php
/**
 * Standalone Backup Decryption CLI Utility for BUCuC.
 *
 * Completely self-contained: runs on any plain PHP CLI environment with ext-openssl.
 * Requires ZERO framework, database, or external files.
 *
 * Usage:
 *   php backend/scripts/decrypt.php <archive.enc> <private_key_file> [passphrase] [output_file]
 *   php backend/scripts/decrypt.php --in <archive.enc> --key <private_key_file> [--passphrase <passphrase>] [--out <output_file>]
 *
 * Examples:
 *   php backend/scripts/decrypt.php backup_2026-10-23.sql.gz.enc backup_private.key
 *   php backend/scripts/decrypt.php --in backup_2026-10-23.sql.gz.enc --key backup_private.key --passphrase "MySecret"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

/**
 * Big-endian counter addition for 16-byte IV in AES-CTR mode.
 */
function bucenc01_add_ctr_counter(string $iv, int $blocks): string
{
    $high = substr($iv, 0, 8);
    $low = substr($iv, 8, 8);
    $lowInt = unpack('J', $low)[1];
    $newLow = $lowInt + $blocks;
    return $high . pack('J', $newLow);
}

/**
 * Standalone decryption for BUCENC01 envelope and OpenSSL CMS/PKCS7 fallback.
 */
function bucenc01_standalone_decrypt(string $encFile, string $destDecryptedFile, string $privKeyContent, string $passphrase = ''): bool
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
                $curIv = bucenc01_add_ctr_counter($curIv, $blocks);
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
    if ($pk = openssl_pkey_get_private($privKeyContent, $passphrase)) {
        $ok = @openssl_cms_decrypt($encFile, $destDecryptedFile, null, $pk);
        if (!$ok) {
            $ok = @openssl_pkcs7_decrypt($encFile, $destDecryptedFile, null, $pk);
        }
        if ($ok && file_exists($destDecryptedFile) && is_file($destDecryptedFile)) {
            return true;
        }
    }

    return false;
}

$encFile = '';
$keyFile = '';
$passphrase = '';
$outFile = '';

// Support both named flags (--in, --key, --passphrase, --out) and positional arguments
$positional = [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--in' && isset($argv[$i + 1])) {
        $encFile = $argv[++$i];
    } elseif ($arg === '--key' && isset($argv[$i + 1])) {
        $keyFile = $argv[++$i];
    } elseif ($arg === '--passphrase' && isset($argv[$i + 1])) {
        $passphrase = $argv[++$i];
    } elseif ($arg === '--out' && isset($argv[$i + 1])) {
        $outFile = $argv[++$i];
    } elseif (strpos($arg, '--in=') === 0) {
        $encFile = substr($arg, 5);
    } elseif (strpos($arg, '--key=') === 0) {
        $keyFile = substr($arg, 6);
    } elseif (strpos($arg, '--passphrase=') === 0) {
        $passphrase = substr($arg, 13);
    } elseif (strpos($arg, '--out=') === 0) {
        $outFile = substr($arg, 6);
    } elseif (strpos($arg, '-') !== 0) {
        $positional[] = $arg;
    }
}

if (empty($encFile) && isset($positional[0])) {
    $encFile = $positional[0];
}
if (empty($keyFile) && isset($positional[1])) {
    $keyFile = $positional[1];
}
if (empty($passphrase) && isset($positional[2])) {
    $passphrase = $positional[2];
}
if (empty($outFile) && isset($positional[3])) {
    $outFile = $positional[3];
}

if (empty($encFile) || empty($keyFile)) {
    echo "Usage:\n";
    echo "  php backend/scripts/decrypt.php <archive.enc> <private_key_file> [passphrase] [output_file]\n";
    echo "  php backend/scripts/decrypt.php --in=<archive.enc> --key=<private_key_file> [--passphrase=<pass>] [--out=<output_file>]\n";
    exit(1);
}

if (!is_file($encFile)) {
    fwrite(STDERR, "Error: Encrypted archive not found: $encFile\n");
    exit(1);
}

if (!is_file($keyFile)) {
    fwrite(STDERR, "Error: Private key file not found: $keyFile\n");
    exit(1);
}

// Default output filename: strip .enc extension
if (empty($outFile)) {
    if (preg_match('/^(.*)\.enc$/i', $encFile, $m)) {
        $outFile = $m[1];
    } else {
        $outFile = $encFile . '.decrypted';
    }
}

$keyContent = file_get_contents($keyFile);

// If passphrase not provided and key appears encrypted, prompt if interactive
if (empty($passphrase) && strpos($keyContent, 'ENCRYPTED') !== false) {
    if (function_exists('readline')) {
        $passphrase = readline("Enter private key passphrase: ");
    } else {
        echo "Enter private key passphrase: ";
        $passphrase = trim(fgets(STDIN));
    }
}

echo "Decrypting $encFile -> $outFile...\n";

try {
    $ok = bucenc01_standalone_decrypt($encFile, $outFile, $keyContent, $passphrase);
    if ($ok && file_exists($outFile) && is_file($outFile)) {
        $sizeBytes = filesize($outFile);
        $sizeMb = round($sizeBytes / (1024 * 1024), 2);
        echo "Decryption successful: $outFile ($sizeBytes bytes / $sizeMb MB)\n";
        exit(0);
    } else {
        fwrite(STDERR, "Decryption failed. Please check the private key and passphrase.\n");
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Decryption failed with exception: " . $e->getMessage() . "\n");
    exit(1);
}
