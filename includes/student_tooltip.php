<?php
declare(strict_types=1);

/**
 * Cursor tooltips near the pointer (see assets/js/app_tooltips.js).
 * Active for admin, dean, gened, faculty, and student roles in the app shell.
 */
function app_cursor_tooltips_active(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $r = (string) ($_SESSION['role'] ?? '');

    return in_array($r, ['super_admin', 'admin', 'dean', 'gened', 'faculty', 'student'], true);
}

function app_tooltip_attr(string $text): string
{
    if (!app_cursor_tooltips_active()) {
        return '';
    }

    return ' data-app-tooltip="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '"';
}

/** @deprecated Use app_tooltip_attr */
function student_tooltip_attr(string $text): string
{
    return app_tooltip_attr($text);
}
