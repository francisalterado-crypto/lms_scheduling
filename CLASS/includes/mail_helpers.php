<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Generate a random temporary password (alphanumeric, URL-safe subset).
 */
function generate_temp_password(int $length = 14): string
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/**
 * @return array{0:int,1:string} [code, full reply]
 */
function smtp_read_response($fp): array
{
    $reply = '';
    while ($line = @fgets($fp, 8192)) {
        $reply .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($reply, 0, 3);

    return [$code, $reply];
}

/**
 * Resolve login URL for credential emails (config override, else APP_BASE_URL + /login.php).
 */
function mail_login_page_url(): string
{
    if (defined('MAIL_LOGIN_URL') && trim((string) MAIL_LOGIN_URL) !== '') {
        return rtrim((string) MAIL_LOGIN_URL, '/');
    }
    $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';

    return $base !== '' ? $base . '/login.php' : '/login.php';
}

/**
 * SMTP dot-stuffing for DATA payload.
 */
function smtp_escape_data_body(string $body): string
{
    $normBody = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $normBody);
    $escaped = [];
    foreach ($lines as $line) {
        if (isset($line[0]) && $line[0] === '.') {
            $escaped[] = '.' . $line;
        } else {
            $escaped[] = $line;
        }
    }

    return implode("\r\n", $escaped);
}

/**
 * @return array{subject: string, role_title: string, plain_intro: string, html_intro: string}
 */
function account_credentials_role_parts(string $role): array
{
    $role = strtolower(trim($role));
    $map = [
        'dean' => [
            'subject' => 'Your Dean account — WPU SABLAe Portal',
            'role_title' => 'Dean',
            'intro' => 'An administrator created or updated your Dean account for WPU SABLAe Portal.',
        ],
        'program_chair' => [
            'subject' => 'Your Program Chair account — WPU SABLAe Portal',
            'role_title' => 'Program Chair',
            'intro' => 'An administrator created or updated your Program Chair account for WPU SABLAe Portal.',
        ],
        'faculty' => [
            'subject' => 'Your Faculty account — WPU SABLAe Portal',
            'role_title' => 'Faculty',
            'intro' => 'An administrator created or updated your Faculty account for WPU SABLAe Portal.',
        ],
        'gened' => [
            'subject' => 'Your General Education account — WPU SABLAe Portal',
            'role_title' => 'General Education',
            'intro' => 'An administrator created or updated your General Education account for WPU SABLAe Portal.',
        ],
        'admin' => [
            'subject' => 'Your Administrator account — WPU SABLAe Portal',
            'role_title' => 'Administrator',
            'intro' => 'An administrator created or updated your Administrator account for WPU SABLAe Portal.',
        ],
        'student' => [
            'subject' => 'Your Student account — WPU SABLAe Portal',
            'role_title' => 'Student',
            'intro' => 'An administrator created or updated your student account for WPU SABLAe Portal.',
        ],
    ];
    if (isset($map[$role])) {
        $m = $map[$role];

        return [
            'subject' => $m['subject'],
            'role_title' => $m['role_title'],
            'plain_intro' => $m['intro'],
            'html_intro' => $m['intro'],
        ];
    }

    return [
        'subject' => 'Your WPU SABLAe Portal account',
        'role_title' => ucfirst(str_replace('_', ' ', $role)),
        'plain_intro' => 'An administrator created or updated your account for WPU SABLAe Portal.',
        'html_intro' => 'An administrator created or updated your account for WPU SABLAe Portal.',
    ];
}

/**
 * Build plain-text + HTML bodies for new-account credential emails.
 *
 * @return array{0:string,1:string} [plain, html]
 */
function build_account_credentials_bodies(
    string $fullName,
    string $username,
    string $plainPassword,
    string $role
): array {
    $parts = account_credentials_role_parts($role);
    $loginUrl = mail_login_page_url();
    $fn = $fullName;
    $roleKey = strtolower(trim($role));
    $studentPlainExtra = $roleKey === 'student'
        ? "\r\nAfter you sign in, open My Classes and enter each instructor’s class join code.\r\n"
        : '';
    $plain =
        'Hello ' . $fn . ",\r\n\r\n"
        . $parts['plain_intro'] . "\r\n\r\n"
        . 'Sign in: ' . $loginUrl . "\r\n"
        . 'Username: ' . $username . "\r\n"
        . 'Temporary password: ' . $plainPassword . "\r\n\r\n"
        . "Please sign in and change your password under Settings as soon as possible."
        . $studentPlainExtra . "\r\n\r\n"
        . "— WPU SABLAe Portal\r\n";

    $e = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $html =
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#212529;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f6f9;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;">'
        . '<tr><td style="padding:28px 28px 8px 28px;font-size:18px;font-weight:600;color:#0d6efd;">WPU SABLAe Portal</td></tr>'
        . '<tr><td style="padding:8px 28px 16px 28px;">Hello ' . $e($fn) . ',</td></tr>'
        . '<tr><td style="padding:0 28px 20px 28px;">' . $e($parts['html_intro']) . '</td></tr>'
        . '<tr><td style="padding:0 28px 24px 28px;" align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:6px;background:#0d6efd;">'
        . '<a href="' . $e($loginUrl) . '" style="display:inline-block;padding:12px 28px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">Sign in</a>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:0 28px 8px 28px;"><strong>Username</strong><br><span style="font-family:Consolas,monospace;">' . $e($username) . '</span></td></tr>'
        . '<tr><td style="padding:0 28px 24px 28px;"><strong>Temporary password</strong><br><span style="font-family:Consolas,monospace;">' . $e($plainPassword) . '</span></td></tr>'
        . '<tr><td style="padding:16px 28px 28px 28px;border-top:1px solid #e9ecef;color:#6c757d;font-size:13px;">'
        . 'Please sign in and change your password under <strong>Settings</strong> as soon as possible.'
        . ($roleKey === 'student'
            ? '<br><br>After you sign in, open <strong>My Classes</strong> and enter each instructor’s class join code.'
            : '')
        . '</td></tr></table></td></tr></table></body></html>';

    return [$plain, $html];
}

/**
 * Send email over SMTP (AUTH LOGIN + optional STARTTLS on port 587).
 * Pass $bodyHtml for multipart/alternative (plain + HTML).
 */
function smtp_send_mail(string $to, string $subject, string $body, ?string $bodyHtml = null): bool
{
    $host = defined('MAIL_SMTP_HOST') ? (string) MAIL_SMTP_HOST : '';
    $port = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
    $user = defined('MAIL_SMTP_USER') ? (string) MAIL_SMTP_USER : '';
    $pass = defined('MAIL_SMTP_PASS') ? (string) MAIL_SMTP_PASS : '';
    $from = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'WPU SABLAe Portal';
    $useTls = defined('MAIL_SMTP_TLS') && MAIL_SMTP_TLS;

    if ($host === '') {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        25,
        STREAM_CLIENT_CONNECT
    );
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 20);

    $write = static function ($fp, string $cmd): void {
        fwrite($fp, $cmd . "\r\n");
    };

    [$code, $greet] = smtp_read_response($fp);
    if ($code !== 220) {
        fclose($fp);

        return false;
    }

    $ehloName = gethostname() ?: 'localhost';
    $write($fp, 'EHLO ' . $ehloName);
    [$code] = smtp_read_response($fp);
    if ($code !== 250) {
        fclose($fp);

        return false;
    }

    if ($useTls && $port === 587) {
        $write($fp, 'STARTTLS');
        [$code] = smtp_read_response($fp);
        if ($code !== 220) {
            fclose($fp);

            return false;
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$cryptoOk) {
            fclose($fp);

            return false;
        }
        $ehloName = gethostname() ?: 'localhost';
        $write($fp, 'EHLO ' . $ehloName);
        [$code] = smtp_read_response($fp);
        if ($code !== 250) {
            fclose($fp);

            return false;
        }
    }

    if ($user !== '' && $pass !== '') {
        $write($fp, 'AUTH LOGIN');
        [$code] = smtp_read_response($fp);
        if ($code !== 334) {
            fclose($fp);

            return false;
        }
        $write($fp, base64_encode($user));
        [$code] = smtp_read_response($fp);
        if ($code !== 334) {
            fclose($fp);

            return false;
        }
        $write($fp, base64_encode($pass));
        [$code] = smtp_read_response($fp);
        if ($code !== 235) {
            fclose($fp);

            return false;
        }
    }

    $write($fp, 'MAIL FROM:<' . $from . '>');
    [$code] = smtp_read_response($fp);
    if ($code !== 250) {
        fclose($fp);

        return false;
    }

    $write($fp, 'RCPT TO:<' . $to . '>');
    [$code] = smtp_read_response($fp);
    if ($code !== 250 && $code !== 251) {
        fclose($fp);

        return false;
    }

    $write($fp, 'DATA');
    [$code] = smtp_read_response($fp);
    if ($code !== 354) {
        fclose($fp);

        return false;
    }

    $subjLine = preg_match('/[^\x20-\x7E]/', $subject)
        ? '=?UTF-8?B?' . base64_encode($subject) . '?='
        : $subject;

    $mimeHeaders = "MIME-Version: 1.0\r\n";
    if ($bodyHtml !== null && $bodyHtml !== '') {
        $boundary = 'b_' . bin2hex(random_bytes(16));
        $mimeHeaders .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
        $inner =
            '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $bodyHtml . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $bodyOut = smtp_escape_data_body($inner);
    } else {
        $mimeHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $bodyOut = smtp_escape_data_body($body);
    }

    $payload =
        'From: ' . $fromName . ' <' . $from . ">\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $subjLine . "\r\n"
        . $mimeHeaders
        . 'Date: ' . date('r') . "\r\n"
        . "\r\n"
        . $bodyOut . "\r\n.\r\n";

    fwrite($fp, $payload);
    [$code] = smtp_read_response($fp);
    if ($code !== 250) {
        fclose($fp);

        return false;
    }

    $write($fp, 'QUIT');
    fclose($fp);

    return true;
}

/**
 * Send email: authenticated SMTP if configured, else PHP mail() with optional Windows SMTP ini.
 * Optional $bodyHtml sends multipart/alternative (plain + HTML).
 */
function send_system_mail(string $to, string $subject, string $body, ?string $bodyHtml = null): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return false;
    }

    $smtpHost = defined('MAIL_SMTP_HOST') ? trim((string) MAIL_SMTP_HOST) : '';
    $smtpUser = defined('MAIL_SMTP_USER') ? trim((string) MAIL_SMTP_USER) : '';

    if ($smtpHost !== '' && $smtpUser !== '') {
        return smtp_send_mail($to, $subject, $body, $bodyHtml);
    }

    $fromAddr = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'WPU SABLAe Portal';

    if ($smtpHost !== '') {
        ini_set('SMTP', $smtpHost);
        ini_set('smtp_port', (string) (defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 25));
    }
    if (defined('MAIL_SENDMAIL_FROM') && MAIL_SENDMAIL_FROM !== '') {
        ini_set('sendmail_from', MAIL_SENDMAIL_FROM);
    }

    $subjectLine = $subject;
    if (preg_match('/[^\x20-\x7E]/', $subject)) {
        $subjectLine = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    if ($bodyHtml !== null && $bodyHtml !== '') {
        $boundary = 'b_' . bin2hex(random_bytes(16));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $fromName . ' <' . $fromAddr . '>',
            'Reply-To: ' . $fromAddr,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message =
            '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $bodyHtml . "\r\n\r\n"
            . '--' . $boundary . "--";

        return @mail($to, $subjectLine, $message, implode("\r\n", $headers));
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromAddr . '>',
        'Reply-To: ' . $fromAddr,
    ];

    return @mail($to, $subjectLine, $body, implode("\r\n", $headers));
}

/**
 * Email account credentials after an administrator creates or resets the account (role-specific template).
 *
 * @param string $role One of: dean, program_chair, faculty, gened, admin, student (see account_credentials_role_parts).
 */
function send_account_credentials_mail(
    string $toEmail,
    string $fullName,
    string $username,
    string $plainPassword,
    string $role = 'dean'
): bool {
    [$plain, $html] = build_account_credentials_bodies($fullName, $username, $plainPassword, $role);
    $parts = account_credentials_role_parts($role);

    return send_system_mail($toEmail, $parts['subject'], $plain, $html);
}

/**
 * @deprecated Use send_account_credentials_mail(..., 'dean') for explicit role.
 */
function send_dean_credentials_mail(string $toEmail, string $fullName, string $username, string $plainPassword): bool
{
    return send_account_credentials_mail($toEmail, $fullName, $username, $plainPassword, 'dean');
}
