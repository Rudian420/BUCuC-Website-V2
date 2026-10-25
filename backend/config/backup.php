<?php
/**
 * Backup configuration settings for BUCuC website.
 *
 * Defaults:
 *   - Backups stored in backend/backups (protected by .htaccess)
 *   - On Hostinger, store backups outside the web root (e.g. /home/u123456789/backups)
 *     by setting BACKUP_DIR in backend/config/backup.local.php.
 *   - 14 days retention.
 *   - Offsite methods: 'none', 'rclone', 'scp'.
 *
 * Local overrides and private credentials belong in backend/config/backup.local.php
 * (which is git-ignored). Never commit backup credentials or paths to git.
 */

if (file_exists(__DIR__ . '/backup.local.php')) {
    require_once __DIR__ . '/backup.local.php';
}

// Directory where backups are written.
// Recommended for Hostinger: set in backup.local.php to a path OUTSIDE public_html
defined('BACKUP_DIR') || define('BACKUP_DIR', dirname(__DIR__) . '/backups');

// Number of days to keep backup files before pruning
defined('BACKUP_RETENTION_DAYS') || define('BACKUP_RETENTION_DAYS', 14);

// Minimum valid backup size in bytes (1 KB)
defined('BACKUP_MIN_SIZE') || define('BACKUP_MIN_SIZE', 1024);

// Offsite backup destination method: 'none', 's3', 'r2', 'b2', 'rclone', 'scp', 'manual'
// Note: rclone and scp on Hostinger shared hosting are unverified; pure-PHP 's3' (Cloudflare R2 or Backblaze B2) is recommended.
defined('BACKUP_OFFSITE_METHOD') || define('BACKUP_OFFSITE_METHOD', 'none');

// Target string for offsite tool (e.g. 'remote:bucuc-backups' for rclone or 'user@host:/backups' for scp)
defined('BACKUP_OFFSITE_TARGET') || define('BACKUP_OFFSITE_TARGET', '');

// Optional SSH key path for scp
defined('BACKUP_OFFSITE_KEY') || define('BACKUP_OFFSITE_KEY', '');

// Pure-PHP S3-Compatible Offsite Settings (Backblaze B2 / Cloudflare R2 / AWS S3)
// Cloudflare R2: https://<account_id>.r2.cloudflarestorage.com (region: 'auto')
// Backblaze B2:  https://s3.<region>.backblazeb2.com (e.g. region: 'us-east-005')
defined('BACKUP_S3_ENDPOINT') || define('BACKUP_S3_ENDPOINT', '');
defined('BACKUP_S3_BUCKET') || define('BACKUP_S3_BUCKET', '');
defined('BACKUP_S3_REGION') || define('BACKUP_S3_REGION', 'auto');
defined('BACKUP_S3_KEY_ID') || define('BACKUP_S3_KEY_ID', '');
defined('BACKUP_S3_SECRET_KEY') || define('BACKUP_S3_SECRET_KEY', '');
defined('BACKUP_S3_PREFIX') || define('BACKUP_S3_PREFIX', 'backups/');

// Encryption before off-site transfer: 'none', 'openssl' (default with cert), 'age', 'gpg'
// Certificate encryption (asymmetric public key on server, private key kept locally) is the default.
defined('BACKUP_ENCRYPTION_METHOD') || define('BACKUP_ENCRYPTION_METHOD', 'none');

// OpenSSL public certificate file or PEM string (server holds PUBLIC key only; user keeps private key locally)
defined('BACKUP_ENCRYPTION_CERT') || define('BACKUP_ENCRYPTION_CERT', '');

// Symmetric passphrase mode is deprecated and unsupported (use BACKUP_ENCRYPTION_CERT instead)
defined('BACKUP_ENCRYPTION_PASSPHRASE') || define('BACKUP_ENCRYPTION_PASSPHRASE', '');

// Age recipient public key (e.g. age1...) if using age CLI
defined('BACKUP_AGE_RECIPIENT') || define('BACKUP_AGE_RECIPIENT', '');

// GPG recipient key ID / email if using gpg CLI
defined('BACKUP_GPG_RECIPIENT') || define('BACKUP_GPG_RECIPIENT', '');

// Optional heartbeat ping URLs (e.g. healthchecks.io)
defined('BACKUP_HEARTBEAT_URL') || define('BACKUP_HEARTBEAT_URL', '');
defined('NOTICE_CRON_HEARTBEAT_URL') || define('NOTICE_CRON_HEARTBEAT_URL', '');
