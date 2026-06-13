<?php
/**
 * Application configuration — copy to config.php and edit for your environment.
 */
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
/** MySQL port (default 3306). Use XAMPP Control Panel → MySQL → Config to confirm. */
define('DB_PORT', 3306);
define('DB_NAME', 'faculty_scheduling');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/** Default admin (used by install.php if no admin exists) */
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');
define('DEFAULT_ADMIN_FULL_NAME', 'System Administrator');
/** Bootstrap account: creates admin users only; change password after first login. */
define('DEFAULT_SUPER_ADMIN_USERNAME', 'superadmin');
define('DEFAULT_SUPER_ADMIN_PASSWORD', 'superadmin123');
define('DEFAULT_SUPER_ADMIN_FULL_NAME', 'Super Administrator');
define('DEFAULT_GENED_USERNAME', 'gened');
define('DEFAULT_GENED_PASSWORD', 'gened123');
define('DEFAULT_GENED_FULL_NAME', 'General Education Admin');

/** GE faculty may message only this dean (user id). Set 0 to resolve by GE_DEAN_NAME_HINT. */
define('GE_DEAN_USER_ID', 0);
/** Dean full-name hint when GE_DEAN_USER_ID is 0 (all tokens must appear in the dean name). */
define('GE_DEAN_NAME_HINT', '');

/** Max messages kept per thread; oldest removed first when exceeded (FIFO). Set 0 for no limit. */
define('MESSAGING_THREAD_MAX_MESSAGES', 10);

define('SESSION_NAME', 'FSS_SESSION');
define('BASE_PATH', dirname(__DIR__));

/*
 * Email (Manage Deans — temporary passwords)
 *
 * APP_BASE_URL: Site root in emails (no trailing slash). Used if MAIL_LOGIN_URL is not set.
 * MAIL_LOGIN_URL: Full URL to login.php (used in credential emails; button + plain-text link).
 * MAIL_ENABLED: Set false to skip sending (accounts still save; temp password may show in flash if send fails).
 * MAIL_FROM_ADDRESS / MAIL_FROM_NAME: Sender shown to recipients.
 *
 * Delivery options:
 * 1) Leave MAIL_SMTP_HOST empty — uses PHP mail() (Unix sendmail, or Windows php.ini SMTP).
 * 2) Set MAIL_SMTP_HOST only — sets ini SMTP/smtp_port for Windows (e.g. Mercury on 127.0.0.1:25).
 * 3) Set MAIL_SMTP_HOST + MAIL_SMTP_USER + MAIL_SMTP_PASS — sends via SMTP AUTH (e.g. smtp.gmail.com:587).
 */
define('APP_BASE_URL', 'http://localhost/CLASS');
/** Full login page URL for credential emails (Sign in button). */
define('MAIL_LOGIN_URL', 'http://localhost/CLASS/login.php');
define('MAIL_ENABLED', false);
define('MAIL_FROM_ADDRESS', 'noreply@example.com');
define('MAIL_FROM_NAME', 'WPU SABLAe Portal');
/** Empty = default PHP mail(). Google Workspace / Gmail: smtp.gmail.com:587 + app password (no spaces). */
define('MAIL_SMTP_HOST', '');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', '');
define('MAIL_SMTP_PASS', '');
/** Use STARTTLS after EHLO (typical for port 587). */
define('MAIL_SMTP_TLS', true);
/** Windows mail(): optional envelope sender (some hosts require it). */
define('MAIL_SENDMAIL_FROM', '');
define('TIME_MIN', '06:00:00');
define('TIME_MAX', '22:00:00');
define('MIN_CLASS_MINUTES', 30);
/** Minimum minutes between class periods on the same day; 0 disables the spacing rule. */
define('MIN_GAP_MINUTES', 0);
define('MAX_CLASS_BLOCK_HOURS', 9);
define('MAX_CONSECUTIVE_HOURS', 3);

/*
 * Wellness companion (student_wellness.php) — Groq cloud
 *
 * 1. Create free key: https://console.groq.com/keys
 * 2. Paste below (starts with gsk_) OR set server env GROQ_API_KEY
 * 3. Reload EduTools → Wellness check-in
 */
define('WELLNESS_AI_ENABLED', false);
define('WELLNESS_AI_PROVIDER', 'groq');
define('WELLNESS_AI_API_KEY', (static function (): string {
    $env = getenv('GROQ_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return '';
})());
define('WELLNESS_AI_BASE_URL', 'https://api.groq.com/openai/v1');
/** Fast: llama-3.1-8b-instant | Best quality: llama-3.3-70b-versatile */
define('WELLNESS_AI_MODEL', 'llama-3.3-70b-versatile');
define('WELLNESS_OLLAMA_URL', 'http://127.0.0.1:11434');
define('WELLNESS_OLLAMA_MODEL', 'llama3.2');
