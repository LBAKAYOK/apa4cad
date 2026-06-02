<?php
/**
 * APA4CAD - Configuration de l'espace admin
 *
 * Pour changer le mot de passe : utilise le bouton "🔑 Mon compte" dans le dashboard.
 * Mot de passe oublié : utilise le lien "Mot de passe oublié ?" sur la page de connexion,
 *                       puis saisis ta clé de secours (voir ADMIN_RECOVERY_KEY).
 */

// ─── Mot de passe administrateur ─────────────────────────────────────────
// Hash bcrypt du mot de passe (sera rempli automatiquement au 1er changement)
// Tant que vide, c'est ADMIN_DEFAULT_PASSWORD_PLAIN qui sert de mot de passe.
const ADMIN_PASSWORD_HASH = '$2y$10$wqtzz/UfrCwLXg2fogynAuZ5HDKg4geIR6IxgLDOz5SuQVZpvwoA2';

// Fallback en clair pour le 1er démarrage (sera commenté automatiquement
// dès le 1er changement de mot de passe via l'interface admin)
// const ADMIN_DEFAULT_PASSWORD_PLAIN = 'apa4cad-admin-2026';  // Désactivé automatiquement après changement de mdp

// ─── Clé de secours (pour récupération mot de passe oublié) ──────────────
// IMPORTANT : note-la dans un endroit sûr et change-la dès que possible
// via le bouton "Régénérer ma clé de secours" dans le dashboard.
const ADMIN_RECOVERY_KEY = 'APA4CAD-RESCUE-2026';

// ─── Paramètres de session ───────────────────────────────────────────────
const ADMIN_SESSION_LIFETIME = 4 * 3600;            // 4h
const ADMIN_SESSION_KEY      = 'apa4cad_admin_authenticated';
const ADMIN_SESSION_TIME_KEY = 'apa4cad_admin_login_time';

// ─── Affichage UI ─────────────────────────────────────────────────────────
const ADMIN_DISPLAY_NAME = 'Administrateur';
