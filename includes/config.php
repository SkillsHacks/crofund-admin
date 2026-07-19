<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'crofundo_donations');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('COMPANY_NAME', 'CroFunding Support');
define('COMPANY_LOGO', '/assets/images/logo.svg');
define('SITE_VERSION', '1.0.0');
define('BASE_URL', 'https://admin.crofund.org/');
define('ADMIN_URL', BASE_URL . '/admin');

define('SESSION_LIFETIME', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 128);
define('TWO_FACTOR_CODE_TTL', 600);
define('TWO_FACTOR_MAX_ATTEMPTS', 5);
define('TWO_FACTOR_RESEND_COOLDOWN', 60);


define('UPLOAD_MAX_SIZE', 5242880);
define('ALLOWED_FILE_TYPES', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf'
]);

define('ERROR_LOG_FILE', __DIR__ . '/../logs/error.log');
if (!is_dir(dirname(ERROR_LOG_FILE))) {
    mkdir(dirname(ERROR_LOG_FILE), 0755, true);
}
ini_set('error_log', ERROR_LOG_FILE);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!function_exists('secure_random_bytes')) {
    function secure_random_bytes(int $length): string {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && strlen($bytes) === $length) {
                return $bytes;
            }
        }

        throw new Exception('A secure random byte generator is not available on this server.');
    }
}

// Site Configuration
define('SITE_URL', 'http://localhost/crofund-admin');  // Update this with your actual site URL

// Only define if not already defined
if (!defined('COMPANY_NAME')) {
    define('COMPANY_NAME', 'CroFund');
}

// Email Configuration (if not already present)
define('SMTP_HOST', 'mail.crofund.org');
define('SMTP_USER', 'no-reply@crofund.org');
define('SMTP_PASS', 'Uk2+R6Jn?[CHXhX]');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'no-reply@crofund.org');
define('SMTP_FROM_NAME', 'CroFund Support');



// Email Rate Limiting (to avoid exceeding provider limits)
define('EMAIL_MAX_PER_HOUR', 20);
define('EMAIL_WORKER_DEFAULT_BATCH_SIZE', 20);
define('EMAIL_MAX_RETRIES', 5);
define('EMAIL_BASE_RETRY_DELAY_SECONDS', 300);
define('EMAIL_STALE_PROCESSING_MINUTES', 30);
define('EMAIL_DEDUPLICATION_WINDOW_HOURS', 24);



// Social Media Links
if (!defined('SOCIAL_FACEBOOK')) {
    define('SOCIAL_FACEBOOK', 'https://facebook.com/your-page');
    define('SOCIAL_TWITTER', 'https://x.com/CroFunding1?t=T1lGpFRl_OiDVJ5wAnzONQ&s=09');
    define('SOCIAL_INSTAGRAM', 'https://www.instagram.com/crofunding?igsh=eWF3OTR3cmNhaHI=');
    define('SOCIAL_LINKEDIN', 'https://linkedin.com/company/your-company');
}
