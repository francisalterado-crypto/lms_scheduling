<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_role(['admin']);
header('Location: conflicts.php');
exit;
