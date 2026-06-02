<?php
/**
 * APA4CAD - Régénération de la clé de secours
 *
 * Génère une nouvelle clé aléatoire (format APA4CAD-XXXX-XXXX-XXXX),
 * l'écrit dans admin_config.php et l'affiche UNE SEULE FOIS à l'utilisateur.
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';        // auth requise
require_once __DIR__ . '/config_writer.php'; // helpers

$newKey  = '';
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate') {
    // Vérifier le mot de passe actuel pour confirmer l'identité
    $currentPwd = (string)($_POST['current_password'] ?? '');
    $currentOK = !empty(ADMIN_PASSWORD_HASH) && password_verify($currentPwd, ADMIN_PASSWORD_HASH);
    $plainOK   = defined('ADMIN_DEFAULT_PASSWORD_PLAIN') && hash_equals(ADMIN_DEFAULT_PASSWORD_PLAIN, $currentPwd);

    if (!$currentOK && !$plainOK) {
        $error = "Mot de passe incorrect. Confirme ton identité pour régénérer la clé.";
    } else {
        $newKey = generateRecoveryKey();
        if (updateAdminConfigConstant('ADMIN_RECOVERY_KEY', $newKey)) {
            $success = true;
        } else {
            $error = "Impossible d'écrire dans admin_config.php. Vérifie les permissions.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Régénérer ma clé de secours · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;padding:40px 20px}
.topbar{background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:2px solid #1d4ed8;
        padding:14px 0;color:#f8fafc;margin:-40px -20px 30px}
.topbar-inner{max-width:900px;margin:0 auto;padding:0 28px;display:flex;align-items:center;gap:20px}
.topbar-brand{font-weight:700;font-size:16px;color:#fff;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#3b82f6;border-radius:2px}
.admin-badge{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:3px 9px;
             border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.back-link{margin-left:auto;color:#cbd5e1;text-decoration:none;font-size:13px;padding:6px 12px;
            border-radius:7px;transition:.15s}
.back-link:hover{background:rgba(255,255,255,.08);color:#fff}

.container{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;
           border-radius:18px;padding:32px 36px;box-shadow:0 4px 12px rgba(15,23,42,.06)}
h1{margin:0 0 6px;font-size:22px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:10px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px;line-height:1.5}

.field{margin-bottom:16px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:15px;font-family:inherit}
.field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}

.btn{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;
     border-radius:11px;padding:12px 24px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;
     box-shadow:0 6px 16px rgba(217,119,6,.3)}
.btn:hover{transform:translateY(-1px)}
.btn-cancel{background:#fff;color:#64748b;border:1px solid #e5e7eb;border-radius:10px;
             padding:11px 22px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;
             text-decoration:none;display:inline-block}
.actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:24px}

.msg{padding:13px 15px;border-radius:10px;font-size:13px;margin-bottom:18px;line-height:1.5;display:flex;align-items:flex-start;gap:10px}
.msg-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.msg-warn {background:#fef3c7;border:1px solid #fcd34d;color:#78350f}

.key-display{background:#0f172a;color:#fbbf24;padding:18px 22px;border-radius:14px;
              font-family:ui-monospace,'Menlo',monospace;font-size:22px;font-weight:700;
              letter-spacing:2px;text-align:center;margin:22px 0;border:2px solid #f59e0b;
              box-shadow:0 0 0 6px rgba(245,158,11,.15)}
.copy-btn{background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:10px 18px;
          font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.copy-btn:hover{background:#1e40af}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <span class="topbar-brand">APA4CAD</span>
        <span class="admin-badge">Admin</span>
        <a href="index.php" class="back-link">← Retour au dashboard</a>
    </div>
</div>

<div class="container">

    <?php if ($success): ?>
        <h1>🎉 Nouvelle clé de secours générée</h1>
        <div class="msg msg-warn">
            ⚠ <strong>IMPORTANT</strong> : note cette clé dans un endroit sûr maintenant.
            Elle ne sera plus jamais affichée. C'est la seule façon de récupérer ton mot de passe si tu l'oublies.
        </div>

        <div class="key-display" id="newkey"><?= htmlspecialchars($newKey) ?></div>

        <div style="text-align:center;margin-bottom:18px">
            <button type="button" class="copy-btn"
                    onclick="navigator.clipboard.writeText(document.getElementById('newkey').textContent.trim()).then(() => this.textContent = '✓ Copié dans le presse-papier')">
                📋 Copier la clé
            </button>
        </div>

        <div style="text-align:center;margin-top:24px">
            <a href="index.php" class="btn-cancel" style="display:inline-block">← Retour au dashboard</a>
        </div>

    <?php else: ?>

        <h1>🔁 Régénérer ma clé de secours</h1>
        <div class="sub">
            Une nouvelle clé aléatoire sera générée et remplacera l'actuelle.
            Confirme ton mot de passe pour valider cette action sensible.
        </div>

        <div class="msg msg-warn">
            ⚠ Une fois régénérée, l'ancienne clé ne fonctionnera plus.
            Note bien la nouvelle clé après génération.
        </div>

        <?php if ($error !== ''): ?>
            <div class="msg msg-error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="regenerate">
            <div class="field">
                <label>Confirme ton mot de passe</label>
                <input type="password" name="current_password" required autofocus>
            </div>
            <div class="actions">
                <a href="index.php" class="btn-cancel">Annuler</a>
                <button type="submit" class="btn">🔁 Générer une nouvelle clé</button>
            </div>
        </form>

    <?php endif; ?>

</div>

</body>
</html>
