<?php
/**
 * APA4CAD - Garde d'accès aux pages admin
 *
 * À inclure en TOUT PREMIER dans chaque page du dossier admin/
 * (sauf login.php et logout.php).
 *
 * Si l'admin n'est pas connecté → redirige vers login.php
 * Si la session a expiré → redirige vers login.php avec un message
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_config.php';
require_once __DIR__ . '/../config_fuseki.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification 1 : présence du flag d'auth
if (empty($_SESSION[ADMIN_SESSION_KEY])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
    header('Location: login.php?redirect=' . $redirect);
    exit;
}

// Vérification 2 : timeout de session
$loginTime = $_SESSION[ADMIN_SESSION_TIME_KEY] ?? 0;
if (time() - $loginTime > ADMIN_SESSION_LIFETIME) {
    // Session expirée : on déconnecte et on renvoie au login avec un message
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[ADMIN_SESSION_TIME_KEY]);
    header('Location: login.php?expired=1');
    exit;
}

// Rafraîchir le timer à chaque requête (sliding session)
$_SESSION[ADMIN_SESSION_TIME_KEY] = time();
