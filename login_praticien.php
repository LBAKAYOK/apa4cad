<?php
/**
 * APA4CAD - Page de connexion praticien
 *
 * Authentification d'un praticien :
 *   - Liste déroulante des praticiens existants (par prénom/nom)
 *   - Champ mot de passe
 *   - Vérification du hash bcrypt en base
 *   - Si OK : appel à loginPraticien() et redirection vers index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/praticien_session.php';
require_once __DIR__ . '/sparql_update.php';

// Si déjà connecté, on redirige direct vers l'app
if (isPraticienLoggedIn()) {
    header('Location: index.php?from_welcome=1');
    exit;
}

// ─── Charger la liste des praticiens depuis Fuseki ────────────────────
function loadPraticiensForLogin(): array {
    $query = sparqlPrefixes() . "
        SELECT ?uri ?prenom ?nom WHERE {
            ?uri a ex:Praticien ;
                 ex:aPourPrenom ?prenom ;
                 ex:aPourNom ?nom .
        }
        ORDER BY ?nom ?prenom
    ";
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    $rows = $d['results']['bindings'] ?? [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'uri'    => $r['uri']['value']    ?? '',
            'prenom' => $r['prenom']['value'] ?? '',
            'nom'    => $r['nom']['value']    ?? '',
        ];
    }
    return $out;
}

function getPraticienHash(string $uri): string {
    $escUri = '<' . str_replace('>', '', $uri) . '>';
    $query = sparqlPrefixes() . "
        SELECT ?hash WHERE {
            $escUri ex:aPourMotDePasseHash ?hash .
        }
        LIMIT 1
    ";
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    return $d['results']['bindings'][0]['hash']['value'] ?? '';
}

// ─── Traitement du formulaire de connexion ────────────────────────────
$error = '';
$praticiens = loadPraticiensForLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uri = trim((string)($_POST['praticien_uri'] ?? ''));
    $pwd = (string)($_POST['password'] ?? '');

    if ($uri === '' || $pwd === '') {
        $error = "Veuillez sélectionner un praticien et saisir votre mot de passe.";
    } else {
        // Vérifier que le praticien existe bien dans la liste
        $praticien = null;
        foreach ($praticiens as $p) {
            if ($p['uri'] === $uri) { $praticien = $p; break; }
        }
        if (!$praticien) {
            $error = "Praticien introuvable.";
        } else {
            $hash = getPraticienHash($uri);
            if ($hash === '') {
                $error = "Aucun mot de passe configuré pour ce praticien. Contactez l'administrateur.";
            } elseif (!password_verify($pwd, $hash)) {
                $error = "Mot de passe incorrect.";
                usleep(300000); // anti-bruteforce
            } else {
                loginPraticien($uri, $praticien['prenom'], $praticien['nom']);
                header('Location: index.php?from_welcome=1');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Connexion praticien · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 50%,#2563eb 100%);
     color:#1e293b;font-size:14px;line-height:1.5;
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:18px;width:100%;max-width:440px;padding:38px 36px;
      box-shadow:0 20px 60px rgba(0,0,0,.3)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.brand-icon{width:42px;height:42px;border-radius:11px;
            background:linear-gradient(135deg,#1d4ed8,#3b82f6);
            display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px}
.brand-name{font-size:18px;font-weight:800;color:#1d4ed8}
.brand-sub{font-size:11px;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
           background:#eff6ff;border:1px solid #bfdbfe;padding:1px 7px;border-radius:5px;margin-left:auto}
h1{margin:18px 0 6px;font-size:22px;font-weight:800}
.sub{color:#64748b;font-size:13px;margin-bottom:22px}

.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field select, .field input{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;
                              font-size:15px;font-family:inherit;background:#fff}
.field select:focus, .field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}

.btn{width:100%;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;
     border:none;border-radius:11px;padding:13px;font-size:14px;font-weight:700;
     cursor:pointer;font-family:inherit;box-shadow:0 6px 18px rgba(37,99,235,.3);margin-top:6px}
.btn:hover{transform:translateY(-1px)}

.msg{padding:11px 13px;border-radius:9px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:9px}
.msg-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.msg-info {background:#eff6ff;border:1px solid #93c5fd;color:#1e40af}

.foot{margin-top:22px;text-align:center;font-size:12px;color:#94a3b8}
.foot a{color:#2563eb;text-decoration:none;font-weight:600}
.foot a:hover{text-decoration:underline}
</style>
</head>
<body>

<div class="card">

    <div class="brand">
        <div class="brand-icon">🩺</div>
        <div class="brand-name">APA4CAD</div>
        <div class="brand-sub">Praticien</div>
    </div>

    <h1>Connexion praticien</h1>
    <div class="sub">Sélectionnez votre nom et saisissez votre mot de passe.</div>

    <?php if ($error !== ''): ?>
        <div class="msg msg-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($praticiens)): ?>
        <div class="msg msg-info">
            ℹ Aucun praticien n'est encore enregistré.<br>
            L'administrateur doit créer au moins un compte praticien
            depuis l'espace d'administration.
        </div>
        <div style="text-align:center;margin-top:16px">
            <a href="admin/login.php" style="background:#dc2626;color:#fff;padding:9px 18px;
                border-radius:9px;font-weight:600;font-size:13px;text-decoration:none;display:inline-block">
                → Espace administrateur
            </a>
        </div>
    <?php else: ?>
        <form method="post" autocomplete="off">
            <div class="field">
                <label>Praticien</label>
                <select name="praticien_uri" required autofocus>
                    <option value="">— Sélectionnez votre identité —</option>
                    <?php foreach ($praticiens as $p): ?>
                        <option value="<?= htmlspecialchars($p['uri'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Mot de passe</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn">Se connecter →</button>
        </form>
    <?php endif; ?>

    <div class="foot">
        <a href="welcome.php">← Retour à l'accueil</a>
    </div>

</div>

</body>
</html>
