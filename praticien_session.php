<?php
/**
 * APA4CAD - Helpers de session pour les praticiens
 *
 * Gère le login/logout des praticiens, leur identification en session,
 * et l'accès aux infos du praticien actif (URI, prénom, nom).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const PRATICIEN_SESSION_KEY      = 'apa4cad_praticien_uri';
const PRATICIEN_SESSION_PRENOM   = 'apa4cad_praticien_prenom';
const PRATICIEN_SESSION_NOM      = 'apa4cad_praticien_nom';
const PRATICIEN_SESSION_TIME_KEY = 'apa4cad_praticien_login_time';
const PRATICIEN_SESSION_LIFETIME = 8 * 3600; // 8h

/**
 * Authentifie un praticien dans la session
 */
function loginPraticien(string $uri, string $prenom, string $nom): void {
    $_SESSION[PRATICIEN_SESSION_KEY]      = $uri;
    $_SESSION[PRATICIEN_SESSION_PRENOM]   = $prenom;
    $_SESSION[PRATICIEN_SESSION_NOM]      = $nom;
    $_SESSION[PRATICIEN_SESSION_TIME_KEY] = time();
    session_regenerate_id(true);
}

/**
 * Déconnecte le praticien
 */
function logoutPraticien(): void {
    unset(
        $_SESSION[PRATICIEN_SESSION_KEY],
        $_SESSION[PRATICIEN_SESSION_PRENOM],
        $_SESSION[PRATICIEN_SESSION_NOM],
        $_SESSION[PRATICIEN_SESSION_TIME_KEY]
    );
    session_regenerate_id(true);
}

/**
 * Vérifie si un praticien est connecté (et que sa session n'a pas expiré)
 */
function isPraticienLoggedIn(): bool {
    if (empty($_SESSION[PRATICIEN_SESSION_KEY] ?? '')) {
        return false;
    }
    $loginTime = $_SESSION[PRATICIEN_SESSION_TIME_KEY] ?? 0;
    if (time() - $loginTime > PRATICIEN_SESSION_LIFETIME) {
        logoutPraticien();
        return false;
    }
    // Sliding session : on rafraîchit le timer
    $_SESSION[PRATICIEN_SESSION_TIME_KEY] = time();
    return true;
}

/**
 * Retourne l'URI du praticien connecté (ou null)
 */
function currentPraticienUri(): ?string {
    return $_SESSION[PRATICIEN_SESSION_KEY] ?? null;
}

/**
 * Retourne le nom complet "Prénom Nom" du praticien connecté
 */
function currentPraticienName(): string {
    $p = $_SESSION[PRATICIEN_SESSION_PRENOM] ?? '';
    $n = $_SESSION[PRATICIEN_SESSION_NOM]    ?? '';
    return trim("$p $n");
}
