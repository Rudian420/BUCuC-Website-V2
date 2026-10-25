<?php
/**
 * Data Retention Configuration for BUCuC (Phase 5).
 *
 * Defines retention limits for personal data.
 * Unreviewed applicants are automatically purged after UNREVIEWED_APPLICANT_RETENTION_MONTHS.
 * Override this value in backend/config/config.local.php as needed:
 *   define('UNREVIEWED_APPLICANT_RETENTION_MONTHS', 3);
 */

defined('UNREVIEWED_APPLICANT_RETENTION_MONTHS') || define('UNREVIEWED_APPLICANT_RETENTION_MONTHS', 6);
