<?php
/**
 * APA4CAD - Helper pour modifier admin_config.php depuis l'interface
 *
 * Permet de changer le hash du mot de passe et la clé de secours
 * en réécrivant les bonnes lignes du fichier de config.
 *
 * Crée aussi une sauvegarde .bak en cas de problème.
 */

declare(strict_types=1);

/**
 * Met à jour une constante PHP dans admin_config.php
 *
 * @param string $constName  Nom de la constante (ex: 'ADMIN_PASSWORD_HASH')
 * @param string $newValue   Nouvelle valeur (sera entourée de guillemets)
 * @return bool true si succès, false sinon
 */
function updateAdminConfigConstant(string $constName, string $newValue): bool {
    $configPath = __DIR__ . '/admin_config.php';
    if (!is_writable($configPath)) {
        return false;
    }

    $content = file_get_contents($configPath);
    if ($content === false) return false;

    // Sauvegarde avant modification
    file_put_contents($configPath . '.bak', $content);

    // Échapper les apostrophes dans la nouvelle valeur
    $escapedValue = str_replace("'", "\\'", $newValue);

    // Regex : trouve la ligne "const NOM = '...';" (avec ou sans espaces)
    // et la remplace. Supporte les commentaires en fin de ligne.
    $pattern = '/^(\s*const\s+' . preg_quote($constName, '/') . '\s*=\s*)([\'"])(.*?)\2(\s*;.*)$/m';

    // IMPORTANT : on utilise preg_replace_callback parce que $escapedValue peut
    // contenir des "$" (cas des hash bcrypt comme $2y$10$XXX...) que preg_replace
    // interpréterait comme des références de groupes ($1, $2, etc.) et casserait
    // tout. Avec une closure, la chaîne est insérée littéralement.
    $newContent = preg_replace_callback(
        $pattern,
        function ($matches) use ($escapedValue) {
            // $matches[1] = "const NOM = ", $matches[4] = "; commentaires éventuels"
            return $matches[1] . "'" . $escapedValue . "'" . $matches[4];
        },
        $content,
        1,
        $count
    );
    if ($newContent === null || $count === 0) {
        return false;
    }

    return file_put_contents($configPath, $newContent) !== false;
}

/**
 * Commente une ligne "const NOM = ..." pour la désactiver
 * (utilisé pour neutraliser ADMIN_DEFAULT_PASSWORD_PLAIN après 1er changement)
 *
 * @param string $constName  Nom de la constante à commenter
 * @return bool true si modifiée, false si pas trouvée
 */
function commentAdminConfigConstant(string $constName): bool {
    $configPath = __DIR__ . '/admin_config.php';
    if (!is_writable($configPath)) return false;

    $content = file_get_contents($configPath);
    if ($content === false) return false;

    // Si déjà commentée, on ne fait rien
    if (preg_match('/^\s*\/\/\s*const\s+' . preg_quote($constName, '/') . '\b/m', $content)) {
        return true; // déjà OK
    }

    $pattern = '/^(\s*)(const\s+' . preg_quote($constName, '/') . '\s*=.*;.*)$/m';
    $replacement = '$1// $2  // Désactivé automatiquement après changement de mdp';

    $newContent = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($newContent === null || $count === 0) return false;

    return file_put_contents($configPath, $newContent) !== false;
}

/**
 * Génère une nouvelle clé de secours aléatoire au format APA4CAD-XXXX-XXXX-XXXX
 */
function generateRecoveryKey(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // pas de I/O/0/1 (ambigus)
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $groups[] = $s;
    }
    return 'APA4CAD-' . implode('-', $groups);
}
