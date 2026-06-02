<?php
/**
 * APA4CAD - Récupération du mot de passe admin via clé de secours
 *
 * Accessible SANS connexion préalable (c'est le point d'entrée pour
 * récupérer un mot de passe oublié).
 *
 * Workflow en 2 étapes :
 *   1. L'utilisateur saisit la clé de secours
 *   2. Si OK → il peut définir un nouveau mot de passe
 *
 * SÉCURITÉ : usleep anti-bruteforce, validation stricte de la clé.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_config.php';
require_once __DIR__ . '/config_writer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$step    = 1;       // 1 = saisie de la clé ; 2 = saisie du nouveau mdp
$error   = '';
$success = false;

// Si on a validé la clé précédemment, on passe à l'étape 2
if (!empty($_SESSION['_recovery_key_validated'] ?? false)) {
    $step = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── Étape 1 : vérifier la clé de secours ───
    if ($action === 'verify_key') {
        $key = trim((string)($_POST['recovery_key'] ?? ''));
        if ($key === '') {
            $error = "Veuillez saisir votre clé de secours.";
        } elseif (hash_equals(ADMIN_RECOVERY_KEY, $key)) {
            // Clé OK : on autorise l'étape 2
            $_SESSION['_recovery_key_validated'] = true;
            $_SESSION['_recovery_key_validated_at'] = time();
            $step = 2;
        } else {
            $error = "Clé de secours incorrecte.";
            usleep(500000); // 500ms anti-bruteforce
        }
    }

    // ─── Étape 2 : réinitialiser le mot de passe ───
    elseif ($action === 'reset_password') {
        // Vérifier que l'étape 1 a bien été franchie ET récemment (15 min max)
        $validatedAt = $_SESSION['_recovery_key_validated_at'] ?? 0;
        if (empty($_SESSION['_recovery_key_validated'] ?? false) || (time() - $validatedAt) > 900) {
            unset($_SESSION['_recovery_key_validated'], $_SESSION['_recovery_key_validated_at']);
            $error = "La session de récupération a expiré. Recommencez depuis le début.";
            $step = 1;
        } else {
            $newPwd     = (string)($_POST['new_password']     ?? '');
            $confirmPwd = (string)($_POST['confirm_password'] ?? '');

            if (strlen($newPwd) < 8) {
                $error = "Le mot de passe doit faire au moins 8 caractères.";
                $step  = 2;
            } elseif ($newPwd !== $confirmPwd) {
                $error = "Les deux mots de passe ne correspondent pas.";
                $step  = 2;
            } else {
                // Tout OK : on hash et on écrit
                $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
                $ok1 = updateAdminConfigConstant('ADMIN_PASSWORD_HASH', $newHash);
                $ok2 = commentAdminConfigConstant('ADMIN_DEFAULT_PASSWORD_PLAIN');

                if ($ok1) {
                    // Nettoyer la session de récupération
                    unset($_SESSION['_recovery_key_validated'], $_SESSION['_recovery_key_validated_at']);
                    $success = true;
                } else {
                    $error = "Impossible d'écrire dans admin_config.php. Vérifie les permissions.";
                    $step  = 2;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Mot de passe oublié · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);color:#f8fafc;
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;color:#1e293b;border-radius:18px;padding:38px 36px;
      width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.brand-icon{width:42px;height:42px;border-radius:11px;
            background:linear-gradient(135deg,#dc2626,#b91c1c);
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-weight:800;font-size:18px}
.brand-name{font-size:18px;font-weight:800;color:#1d4ed8}
.brand-sub{font-size:11px;color:#dc2626;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
           background:#fef2f2;border:1px solid #fca5a5;padding:1px 7px;border-radius:5px;margin-left:auto}

h1{margin:18px 0 6px;font-size:21px;font-weight:700}
.sub{color:#64748b;font-size:13px;margin-bottom:24px;line-height:1.5}

.steps{display:flex;align-items:center;gap:8px;margin-bottom:22px;font-size:11px;font-weight:600;color:#94a3b8}
.steps .s{padding:5px 11px;border-radius:6px;background:#f1f5f9}
.steps .s.active{background:#1d4ed8;color:#fff}
.steps .s.done{background:#dcfce7;color:#065f46}

.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input{width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;
             border-radius:10px;font-size:15px;font-family:inherit}
.field input.recovery{font-family:ui-monospace,monospace;letter-spacing:1px;text-transform:uppercase}
.field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.hint{font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic}

.btn{width:100%;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;
     border:none;border-radius:11px;padding:13px;font-size:14px;font-weight:700;
     cursor:pointer;font-family:inherit;box-shadow:0 6px 18px rgba(37,99,235,.3)}
.btn:hover{transform:translateY(-1px)}

.msg{padding:11px 13px;border-radius:9px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:9px}
.msg-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.msg-info {background:#eff6ff;border:1px solid #93c5fd;color:#1e40af}
.msg-success{background:#dcfce7;border:1px solid #6ee7b7;color:#065f46}

.foot{margin-top:22px;text-align:center;font-size:12px;color:#94a3b8}
.foot a{color:#2563eb;text-decoration:none;font-weight:600}
.foot a:hover{text-decoration:underline}

.success-card{text-align:center;padding:14px 0 4px}
.success-card .check{font-size:56px;line-height:1;margin-bottom:14px}
.success-card h2{margin:0 0 8px;font-size:20px;color:#065f46}
.success-card p{color:#475569;font-size:13px;line-height:1.5;margin-bottom:22px}
</style>
</head>
<body>

<div class="card">

    <div class="brand">
        <div class="brand-icon">🔑</div>
        <div class="brand-name">APA4CAD</div>
        <div class="brand-sub">Admin</div>
    </div>

    <?php if ($success): ?>
        <div class="success-card">
            <div class="check">✅</div>
            <h2>Mot de passe réinitialisé</h2>
            <p>Tu peux maintenant te connecter avec ton nouveau mot de passe.</p>
            <a href="login.php" class="btn" style="text-decoration:none;display:inline-block;width:auto;padding:13px 28px">
                Aller à la connexion →
            </a>
        </div>

    <?php else: ?>

        <h1>Mot de passe oublié ?</h1>
        <div class="sub">
            <?= $step === 1
                ? 'Saisis ta clé de secours pour réinitialiser ton mot de passe.'
                : 'Clé validée. Choisis maintenant un nouveau mot de passe.' ?>
        </div>

        <div class="steps">
            <span class="s <?= $step === 1 ? 'active' : 'done' ?>"><?= $step === 1 ? '1' : '✓' ?>&nbsp; Clé de secours</span>
            →
            <span class="s <?= $step === 2 ? 'active' : '' ?>">2&nbsp; Nouveau mot de passe</span>
        </div>

        <?php if ($error !== ''): ?>
            <div class="msg msg-error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="msg msg-info">
                💡 La clé de secours t'a été communiquée dans <code>admin_config.php</code>.<br>
                Format : <strong>APA4CAD-XXXX-XXXX-XXXX</strong>
            </div>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="verify_key">
                <div class="field">
                    <label>Clé de secours</label>
                    <input type="text" name="recovery_key" required autofocus
                           class="recovery"
                           placeholder="APA4CAD-XXXX-XXXX-XXXX">
                    <div class="hint">Saisis la clé exactement telle qu'elle apparaît dans le fichier de config.</div>
                </div>
                <button type="submit" class="btn">Vérifier la clé →</button>
            </form>

        <?php else: /* step 2 */ ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="reset_password">
                <div class="field">
                    <label>Nouveau mot de passe</label>
                    <input type="password" name="new_password" required minlength="8" autofocus>
                    <div class="hint">Minimum 8 caractères.</div>
                </div>
                <div class="field">
                    <label>Confirmer le mot de passe</label>
                    <input type="password" name="confirm_password" required minlength="8">
                </div>
                <button type="submit" class="btn">Réinitialiser le mot de passe →</button>
            </form>
        <?php endif; ?>

        <div class="foot">
            <a href="login.php">← Retour à la connexion</a>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
