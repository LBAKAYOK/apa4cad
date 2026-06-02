<?php
/**
 * APA4CAD - Déconnexion admin
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[ADMIN_SESSION_TIME_KEY]);
session_regenerate_id(true);

header('Location: login.php?logged_out=1');
exit;
