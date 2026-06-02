<?php
/**
 * APA4CAD - Connexion administrateur
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$expired = isset($_GET['expired']);
$redirect = $_GET['redirect'] ?? 'index.php';

// Si déjà connecté, on redirige direct
if (!empty($_SESSION[ADMIN_SESSION_KEY])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');

    if ($password === '') {
        $error = 'Veuillez saisir un mot de passe.';
    } else {
        // Vérification : on essaie d'abord le hash, puis le fallback en clair
        // (le fallback sert UNIQUEMENT au premier démarrage avant régénération du hash).
        $hashOK  = !empty(ADMIN_PASSWORD_HASH) && password_verify($password, ADMIN_PASSWORD_HASH);
        $plainOK = defined('ADMIN_DEFAULT_PASSWORD_PLAIN') && hash_equals(ADMIN_DEFAULT_PASSWORD_PLAIN, $password);

        if ($hashOK || $plainOK) {
            // Auth réussie : on régénère l'ID de session pour éviter le fixation attack
            session_regenerate_id(true);
            $_SESSION[ADMIN_SESSION_KEY] = true;
            $_SESSION[ADMIN_SESSION_TIME_KEY] = time();

            // Redirection vers la page initialement demandée (ou index par défaut)
            $safeRedirect = preg_match('#^[a-z0-9_./?=&-]+$#i', $redirect) ? $redirect : 'index.php';
            header('Location: ' . $safeRedirect);
            exit;
        } else {
            $error = 'Mot de passe incorrect.';
            // Petit délai anti-bruteforce (300ms)
            usleep(300000);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Connexion admin · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);color:#f8fafc;
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-card{background:#fff;color:#1e293b;border-radius:18px;padding:38px 36px;
            width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.brand-icon{width:42px;height:42px;border-radius:11px;
            background:linear-gradient(135deg,#1d4ed8,#3b82f6);
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-weight:800;font-size:18px}
.brand-name{font-size:18px;font-weight:800;color:#1d4ed8;letter-spacing:-0.01em}
.brand-sub{font-size:11px;color:#dc2626;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
           background:#fef2f2;border:1px solid #fca5a5;padding:1px 7px;border-radius:5px;margin-left:auto}
h1{margin:18px 0 6px;font-size:22px;font-weight:700;letter-spacing:-0.01em}
.sub{color:#64748b;font-size:13px;margin-bottom:24px;line-height:1.5}

.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;
             border-radius:10px;font-size:15px;font-family:inherit;background:#fff;
             transition:.15s}
.field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}

.btn{width:100%;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;
     border:none;border-radius:11px;padding:13px;font-size:14px;font-weight:700;
     cursor:pointer;font-family:inherit;letter-spacing:.2px;
     box-shadow:0 6px 18px rgba(37,99,235,.3);transition:.15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(37,99,235,.4)}
.btn:active{transform:translateY(0)}

.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;
       padding:10px 12px;border-radius:9px;font-size:13px;margin-bottom:14px;line-height:1.5}
.warn{background:#fef3c7;border:1px solid #fcd34d;color:#78350f;
      padding:10px 12px;border-radius:9px;font-size:13px;margin-bottom:14px;line-height:1.5}

.foot{margin-top:22px;text-align:center;font-size:12px;color:#94a3b8}
.foot a{color:#2563eb;text-decoration:none;font-weight:600}
.foot a:hover{text-decoration:underline}
</style>
</head>
<body>

<div class="login-card">
    <div class="brand">
        <div class="brand-icon">A</div>
        <div class="brand-name">APA4CAD</div>
        <div class="brand-sub">Admin</div>
    </div>
    <h1>Connexion administrateur</h1>
    <div class="sub">Accès réservé à la gestion de l'application.</div>

    <?php if ($expired): ?>
        <div class="warn">⏱ Votre session a expiré. Veuillez vous reconnecter.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error">⚠ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
            <label for="password">Mot de passe</label>
            <input type="password" name="password" id="password" required autofocus
                   placeholder="••••••••••••••">
        </div>
        <button type="submit" class="btn">Se connecter →</button>
    </form>

    <div style="text-align:center;margin-top:18px">
        <a href="forgot_password.php" style="color:#dc2626;font-size:12px;font-weight:600;text-decoration:none">
            🔑 Mot de passe oublié ?
        </a>
    </div>

    <div class="foot">
        <a href="../index.php">← Retour à l'application</a>
    </div>
</div>

</body>
</html>
