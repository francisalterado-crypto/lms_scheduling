<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_role(['program_chair']);
$_SESSION['flash'] = 'Student registration approvals are handled by your dean.';
header('Location: dashboard.php');
exit;
