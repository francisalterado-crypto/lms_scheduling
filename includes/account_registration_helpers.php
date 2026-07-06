<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail_helpers.php';

/**
 * Ensure users.must_change_password exists (lazy migration for installs that skipped upgrade_roles).
 */
function ensure_must_change_password_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!db_column_exists('users', 'must_change_password')) {
        try {
            db()->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Column may have been added concurrently; ignore duplicate-column errors.
        }
    }
}

function mark_new_account_requires_password_change(int $userId): void
{
    if ($userId < 1) {
        return;
    }
    ensure_must_change_password_column();
    if (!db_column_exists('users', 'must_change_password')) {
        return;
    }
    db()->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?')->execute([$userId]);
}

function user_must_change_password(int $userId): bool
{
    if ($userId < 1 || !db_column_exists('users', 'must_change_password')) {
        return false;
    }
    $st = db()->prepare('SELECT must_change_password FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);

    return (int) $st->fetchColumn() === 1;
}

function clear_must_change_password(int $userId): void
{
    if ($userId < 1 || !db_column_exists('users', 'must_change_password')) {
        return;
    }
    db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?')->execute([$userId]);
}

/**
 * Resolve password for a new account. Auto-generates when a valid email is provided and no password was entered.
 *
 * @return array{plain: string, hash: string}
 */
function prepare_new_account_password(string $manualPassword, bool $hasValidEmail): array
{
    if ($hasValidEmail) {
        $plain = ($manualPassword !== '' && strlen($manualPassword) >= 8)
            ? $manualPassword
            : generate_temp_password();
    } elseif ($manualPassword === '' || strlen($manualPassword) < 8) {
        throw new RuntimeException(
            'Password is required and must be at least 8 characters when no email address is provided.'
        );
    } else {
        $plain = $manualPassword;
    }

    return [
        'plain' => $plain,
        'hash' => password_hash($plain, PASSWORD_DEFAULT),
    ];
}

function log_registration_email_failure(int $userId, string $username, string $role, string $reason): void
{
    error_log(sprintf(
        '[CLASS] Registration credentials email failed for user #%d (%s, role=%s): %s',
        $userId,
        $username,
        $role,
        $reason
    ));
}

/**
 * Send exactly one credentials email after successful account creation. Never logs the plain password.
 *
 * @return bool|null true = sent, false = failed, null = no valid email (skipped)
 */
function notify_new_account_credentials(
    int $userId,
    string $toEmail,
    string $fullName,
    string $username,
    string $plainPassword,
    string $role
): ?bool {
    mark_new_account_requires_password_change($userId);

    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    try {
        $sent = send_account_credentials_mail(
            $toEmail,
            $fullName,
            $username,
            $plainPassword,
            $role,
            $toEmail
        );
        if (!$sent) {
            log_registration_email_failure($userId, $username, $role, 'mail transport returned false');
        }

        return $sent;
    } catch (Throwable $e) {
        log_registration_email_failure($userId, $username, $role, $e->getMessage());

        return false;
    }
}

/**
 * Flash message after new account registration (never includes the password).
 */
function registration_email_flash_message(?bool $mailSent, string $email = '', string $entityLabel = 'Account'): string
{
    if ($mailSent === null) {
        return $entityLabel . ' created successfully.';
    }
    if ($mailSent) {
        return $entityLabel . ' created successfully. Login credentials sent to ' . $email . '.';
    }

    return 'The account has been created successfully, but the email notification could not be sent.';
}

/**
 * Flash message after password reset / credential re-send (never includes the password).
 */
function credentials_email_flash_message(?bool $mailSent, string $email, string $contextUpdated): string
{
    if ($mailSent === null) {
        return $contextUpdated . '.';
    }
    if ($mailSent) {
        return $contextUpdated . '. Login credentials sent to ' . $email . '.';
    }

    return $contextUpdated . ', but the email notification could not be sent.';
}

function auth_enforce_password_change_if_required(): void
{
    if (empty($_SESSION['must_change_password'])) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['settings.php', 'logout.php'];
    if (!in_array($script, $allowed, true)) {
        header('Location: settings.php?force_password=1');
        exit;
    }
}
