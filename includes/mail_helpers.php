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
 * Absolute URL for the organization logo in HTML emails.
 */
function mail_logo_url(): string
{
    if (defined('MAIL_LOGO_URL') && trim((string) MAIL_LOGO_URL) !== '') {
        return rtrim((string) MAIL_LOGO_URL, '/');
    }
    $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';

    return $base !== '' ? $base . '/assets/logo.png' : '/assets/logo.png';
}

function mail_organization_name(): string
{
    if (defined('MAIL_ORGANIZATION_NAME') && trim((string) MAIL_ORGANIZATION_NAME) !== '') {
        return trim((string) MAIL_ORGANIZATION_NAME);
    }

    return defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : 'WPU SABLAe Portal';
}

function mail_support_contact(): string
{
    if (defined('MAIL_SUPPORT_CONTACT') && trim((string) MAIL_SUPPORT_CONTACT) !== '') {
        return trim((string) MAIL_SUPPORT_CONTACT);
    }

    return 'Please contact your system administrator or IT help desk for assistance.';
}

function mail_credentials_subject(): string
{
    return 'Account Registration Successful – Your Login Credentials';
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
 * @return array{role_title: string, plain_intro: string, html_intro: string}
 */
function account_credentials_role_parts(string $role): array
{
    $role = strtolower(trim($role));
    $map = [
        'super_admin' => 'Super Administrator',
        'dean' => 'Dean',
        'program_chair' => 'Program Chair',
        'faculty' => 'Faculty',
        'gened' => 'General Education',
        'admin' => 'Administrator',
        'student' => 'Student',
        'student_registration' => 'Student',
    ];
    $roleTitle = $map[$role] ?? ucfirst(str_replace('_', ' ', $role));

    if ($role === 'student_registration') {
        $intro = 'Your registration has been approved and your account has been successfully created.';
    } else {
        $intro = 'Your account has been successfully created.';
    }

    return [
        'role_title' => $roleTitle,
        'plain_intro' => $intro,
        'html_intro' => $intro,
    ];
}

function mail_format_registration_datetime(?DateTimeInterface $registeredAt = null): string
{
    $dt = $registeredAt ?? new DateTimeImmutable('now');

    return $dt->format('F j, Y \a\t g:i A');
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
    string $role,
    string $registeredEmail = '',
    ?DateTimeInterface $registeredAt = null
): array {
    $parts = account_credentials_role_parts($role);
    $loginUrl = mail_login_page_url();
    $logoUrl = mail_logo_url();
    $orgName = mail_organization_name();
    $support = mail_support_contact();
    $registeredOn = mail_format_registration_datetime($registeredAt);
    $roleKey = strtolower(trim($role));
    $isStudentCredentials = in_array($roleKey, ['student', 'student_registration'], true);
    $emailLine = $registeredEmail !== '' ? $registeredEmail : '—';

    $studentPlainExtra = $isStudentCredentials
        ? "\r\nAfter you sign in, open My Classes and enter each instructor's class join code.\r\n"
        : '';

    $plain =
        'Hello ' . $fullName . ",\r\n\r\n"
        . $parts['plain_intro'] . "\r\n\r\n"
        . "You can now log in using the following credentials:\r\n\r\n"
        . 'Username: ' . $username . "\r\n"
        . 'Temporary Password: ' . $plainPassword . "\r\n"
        . 'Registered email: ' . $emailLine . "\r\n"
        . 'Account type: ' . $parts['role_title'] . "\r\n"
        . 'Registered on: ' . $registeredOn . "\r\n\r\n"
        . 'Login Page: ' . $loginUrl . "\r\n\r\n"
        . "For security purposes, please change your password immediately after your first login.\r\n\r\n"
        . 'Technical support: ' . $support . $studentPlainExtra . "\r\n\r\n"
        . "Thank you,\r\nSystem Administrator\r\n" . $orgName . "\r\n\r\n"
        . "This is an automated email. Please do not reply.\r\n";

    $e = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $studentHtmlExtra = $isStudentCredentials
        ? '<p style="margin:16px 0 0 0;color:#495057;font-size:14px;">After you sign in, open <strong>My Classes</strong> and enter each instructor&rsquo;s class join code.</p>'
        : '';

    $html =
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $e(mail_credentials_subject()) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#212529;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f6f9;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">'
        . '<tr><td style="padding:28px 28px 16px 28px;text-align:center;border-bottom:1px solid #e9ecef;">'
        . '<img src="' . $e($logoUrl) . '" alt="' . $e($orgName) . '" width="120" height="72" style="display:block;margin:0 auto 12px auto;max-width:120px;max-height:72px;width:auto;height:auto;object-fit:contain;">'
        . '<div style="font-size:20px;font-weight:700;color:#0d6efd;">' . $e($orgName) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 28px 8px 28px;">Hello <strong>' . $e($fullName) . '</strong>,</td></tr>'
        . '<tr><td style="padding:0 28px 20px 28px;color:#495057;">' . $e($parts['html_intro']) . '</td></tr>'
        . '<tr><td style="padding:0 28px 24px 28px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8f9fa;border:1px solid #dee2e6;border-left:4px solid #0d6efd;border-radius:8px;">'
        . '<tr><td style="padding:20px 22px;">'
        . '<div style="font-size:13px;font-weight:700;color:#0d6efd;text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px;">Your login credentials</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:14px;">'
        . '<tr><td style="padding:6px 0;color:#6c757d;width:38%;">Username</td><td style="padding:6px 0;font-family:Consolas,Monaco,monospace;font-weight:600;">' . $e($username) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d;">Temporary Password</td><td style="padding:6px 0;font-family:Consolas,Monaco,monospace;font-weight:600;">' . $e($plainPassword) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d;">Registered email</td><td style="padding:6px 0;">' . $e($emailLine) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d;">Account type</td><td style="padding:6px 0;">' . $e($parts['role_title']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d;">Registered on</td><td style="padding:6px 0;">' . $e($registeredOn) . '</td></tr>'
        . '</table></td></tr></table></td></tr>'
        . '<tr><td style="padding:0 28px 24px 28px;" align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:6px;background:#0d6efd;">'
        . '<a href="' . $e($loginUrl) . '" style="display:inline-block;padding:12px 28px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">Sign in</a>'
        . '</td></tr></table>'
        . '<p style="margin:12px 0 0 0;font-size:13px;color:#6c757d;word-break:break-all;">' . $e($loginUrl) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px 24px 28px;color:#495057;font-size:14px;">'
        . '<strong>Important:</strong> For security purposes, please change your password immediately after your first login.'
        . $studentHtmlExtra
        . '<p style="margin:16px 0 0 0;"><strong>Technical support:</strong> ' . $e($support) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 28px;border-top:1px solid #e9ecef;color:#6c757d;font-size:13px;">'
        . 'Thank you,<br><strong>System Administrator</strong><br>' . $e($orgName)
        . '<p style="margin:16px 0 0 0;font-size:12px;color:#adb5bd;">This is an automated email. Please do not reply.</p>'
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
 * Email account credentials after a new account is registered (role-specific content, unified subject).
 *
 * @param string $role One of: super_admin, dean, program_chair, faculty, gened, admin, student, student_registration
 */
function send_account_credentials_mail(
    string $toEmail,
    string $fullName,
    string $username,
    string $plainPassword,
    string $role = 'dean',
    string $registeredEmail = ''
): bool {
    $registeredEmail = trim($registeredEmail) !== '' ? trim($registeredEmail) : trim($toEmail);
    [$plain, $html] = build_account_credentials_bodies(
        $fullName,
        $username,
        $plainPassword,
        $role,
        $registeredEmail
    );

    return send_system_mail($toEmail, mail_credentials_subject(), $plain, $html);
}

/**
 * @deprecated Use send_account_credentials_mail(..., 'dean') for explicit role.
 */
function send_dean_credentials_mail(string $toEmail, string $fullName, string $username, string $plainPassword): bool
{
    return send_account_credentials_mail($toEmail, $fullName, $username, $plainPassword, 'dean');
}
