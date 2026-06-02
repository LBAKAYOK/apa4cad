<?php
/**
 * APA4CAD - Changement de mot de passe admin (auto-écriture)
 *
 * Accessible uniquement quand l'admin est connecté.
 * Modifie automatiquement admin_config.php :
 *   - Hash le nouveau mot de passe (bcrypt)
 *   - Écrit le hash dans ADMIN_PASSWORD_HASH
 *   - Commente ADMIN_DEFAULT_PASSWORD_PLAIN (si encore présent)
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';        // auth requise
require_once __DIR__ . '/config_writer.php'; // helpers d'écriture

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPwd = (string)($_POST['current_password'] ?? '');
    $newPwd     = (string)($_POST['new_password']     ?? '');
    $confirmPwd = (string)($_POST['confirm_password'] ?? '');

    // 1) Vérifier le mot de passe actuel
    $currentOK = !empty(ADMIN_PASSWORD_HASH) && password_verify($currentPwd, ADMIN_PASSWORD_HASH);
    $plainOK   = defined('ADMIN_DEFAULT_PASSWORD_PLAIN') && hash_equals(ADMIN_DEFAULT_PASSWORD_PLAIN, $currentPwd);

    if (!$currentOK && !$plainOK) {
        $error = "Mot de passe actuel incorrect.";
    } elseif (strlen($newPwd) < 8) {
        $error = "Le nouveau mot de passe doit faire au moins 8 caractères.";
    } elseif ($newPwd !== $confirmPwd) {
        $error = "Les deux mots de passe ne correspondent pas.";
    } elseif ($newPwd === $currentPwd) {
        $error = "Le nouveau mot de passe doit être différent de l'ancien.";
    } else {
        // 2) Générer le hash et l'écrire dans admin_config.php
        $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
        $ok1 = updateAdminConfigConstant('ADMIN_PASSWORD_HASH', $newHash);
        $ok2 = commentAdminConfigConstant('ADMIN_DEFAULT_PASSWORD_PLAIN'); // neutralise le fallback

        if ($ok1) {
            $success = true;
        } else {
            $error = "Impossible d'écrire dans admin_config.php. Vérifie les permissions du fichier.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Changer mon mot de passe · APA4CAD Admin</title>
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
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;
             border-radius:10px;font-size:15px;font-family:inherit}
.field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.field .hint{font-size:11px;color:#94a3b8;margin-top:4px;font-style:italic}

.btn{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;
     border-radius:11px;padding:12px 24px;font-size:14px;font-weight:700;cursor:pointer;
     font-family:inherit;box-shadow:0 6px 16px rgba(37,99,235,.3);transition:.15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(37,99,235,.4)}
.btn-cancel{background:#fff;color:#64748b;border:1px solid #e5e7eb;border-radius:10px;
             padding:11px 22px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;
             text-decoration:none;display:inline-block}
.btn-cancel:hover{background:#f8fafc;color:#1e293b}

.actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:24px}

.msg{padding:13px 15px;border-radius:10px;font-size:13px;margin-bottom:18px;line-height:1.5;display:flex;align-items:flex-start;gap:10px}
.msg-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.msg-success{background:#dcfce7;border:1px solid #6ee7b7;color:#065f46}
.msg-icon{font-size:18px;flex-shrink:0;line-height:1}

.success-card{text-align:center;padding:20px 0}
.success-card .check{font-size:56px;line-height:1;margin-bottom:14px}
.success-card h2{margin:0 0 10px;font-size:20px;color:#065f46}
.success-card p{color:#475569;font-size:14px;margin-bottom:24px}
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
        <div class="success-card">
            <div class="check">✅</div>
            <h2>Mot de passe modifié</h2>
            <p>Votre nouveau mot de passe est actif. Vous resterez connecté pour cette session,
               mais devrez l'utiliser à la prochaine connexion.</p>
            <a href="index.php" class="btn" style="text-decoration:none">← Retour au dashboard</a>
        </div>
    <?php else: ?>
        <h1>🔑 Changer mon mot de passe</h1>
        <div class="sub">Modifie le mot de passe d'accès à l'espace administrateur.</div>

        <?php if ($error !== ''): ?>
            <div class="msg msg-error">
                <span class="msg-icon">⚠</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="field">
                <label>Mot de passe actuel</label>
                <input type="password" name="current_password" required autofocus>
            </div>
            <div class="field">
                <label>Nouveau mot de passe</label>
                <input type="password" name="new_password" required minlength="8">
                <div class="hint">Minimum 8 caractères.</div>
            </div>
            <div class="field">
                <label>Confirmer le nouveau mot de passe</label>
                <input type="password" name="confirm_password" required minlength="8">
            </div>
            <div class="actions">
                <a href="index.php" class="btn-cancel">Annuler</a>
                <button type="submit" class="btn">Changer le mot de passe →</button>
            </div>
        </form>
    <?php endif; ?>

</div>

</body>
</html>
