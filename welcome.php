<?php
/**
 * APA4CAD - Page d'accueil (v3 enrichie)
 *
 * Améliorations :
 *   - Cadenas ouvert/fermé selon l'état de connexion
 *   - Card Praticien : 2 sous-boutons (Patient existant / Nouveau patient)
 *   - Card Exploration : liste avec points verts et grisés
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/praticien_session.php';

$isAdminConnected     = !empty($_SESSION['apa4cad_admin_authenticated'] ?? false);
$isPraticienConnected = isPraticienLoggedIn();

// Reset du flag welcome_seen pour que l'utilisateur puisse choisir
unset($_SESSION['welcome_seen']);

// URL des sous-boutons praticien :
// Si connecté → direct dans les pages. Sinon → login_praticien.php (avec redirect)
$praticienExistingUrl = $isPraticienConnected
    ? 'patient.php'
    : 'login_praticien.php';
$praticienNewUrl      = $isPraticienConnected
    ? 'index.php?from_welcome=1'
    : 'login_praticien.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Bienvenue · APA4CAD</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
     font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 35%,#2563eb 65%,#3b82f6 100%);
     color:#1e293b;padding:24px;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;inset:0;
              background-image:radial-gradient(circle at 20% 30%, rgba(255,255,255,.05) 0%, transparent 50%),
                                radial-gradient(circle at 80% 70%, rgba(255,255,255,.05) 0%, transparent 50%);
              pointer-events:none;z-index:0}

.welcome{max-width:1100px;width:100%;position:relative;z-index:1}

.head{text-align:center;margin-bottom:32px;color:#fff}
.head-brand{display:inline-flex;align-items:center;gap:12px;
            background:rgba(255,255,255,.12);backdrop-filter:blur(10px);
            border:1px solid rgba(255,255,255,.18);
            padding:10px 18px;border-radius:50px;margin-bottom:20px;
            font-size:13px;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
.head-brand-icon{width:24px;height:24px;border-radius:6px;background:#fff;color:#1d4ed8;
                  font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center}
.head h1{margin:0 0 12px;font-size:36px;font-weight:800;letter-spacing:-0.025em;
         line-height:1.15;text-shadow:0 2px 20px rgba(0,0,0,.15)}
.head p{margin:0;font-size:15px;opacity:.9;max-width:600px;margin-left:auto;margin-right:auto;line-height:1.6}

/* ════ Grille 2x2 ════ */
.modes{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
@media(max-width:740px){.modes{grid-template-columns:1fr}}

.mode-card{background:#fff;border-radius:18px;padding:22px 22px;
           box-shadow:0 18px 45px rgba(0,0,0,.2);transition:.3s cubic-bezier(.4,0,.2,1);
           display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;
           border-top:5px solid;min-height:300px;color:#1e293b}
.mode-card:hover{transform:translateY(-4px);box-shadow:0 26px 56px rgba(0,0,0,.28)}

.mode-card-icon{width:48px;height:48px;border-radius:12px;display:flex;
                align-items:center;justify-content:center;font-size:22px;
                font-weight:800;color:#fff;box-shadow:0 6px 16px rgba(0,0,0,.15)}
.mode-card-tag{position:absolute;top:14px;right:14px;font-size:9px;font-weight:700;
                text-transform:uppercase;letter-spacing:.6px;padding:3px 9px;border-radius:999px}
.mode-card h2{margin:6px 0 0;font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.01em}
.mode-card-desc{font-size:13px;color:#64748b;line-height:1.55}

/* ─── Liste de capacités (card exploration) ─── */
.capabilities{list-style:none;padding:0;margin:6px 0 0;display:flex;flex-direction:column;gap:5px}
.capabilities li{font-size:12.5px;color:#475569;display:flex;align-items:center;gap:8px;line-height:1.4}
.capabilities li::before{content:"";width:6px;height:6px;border-radius:50%;background:#10b981;flex-shrink:0}
.capabilities li.disabled{color:#cbd5e1}
.capabilities li.disabled::before{background:#cbd5e1}

/* ─── Sous-boutons (card praticien) ─── */
.sub-actions{display:flex;flex-direction:column;gap:8px;margin-top:6px}
.sub-action{display:flex;align-items:center;justify-content:space-between;
             padding:10px 14px;border:1.5px solid #bfdbfe;background:#eff6ff;
             border-radius:10px;text-decoration:none;color:#1d4ed8;font-weight:600;
             font-size:13px;transition:.15s;cursor:pointer}
.sub-action:hover{background:#dbeafe;border-color:#93c5fd;transform:translateX(2px)}
.sub-action-text{display:flex;align-items:center;gap:8px}
.sub-action-icon{font-size:14px}
.sub-action-arrow{opacity:.5;font-size:14px;transition:.15s}
.sub-action:hover .sub-action-arrow{opacity:1;transform:translateX(2px)}

/* ─── Footer de card (lock + state) ─── */
.mode-card-foot{display:flex;align-items:center;justify-content:space-between;
                 margin-top:auto;padding-top:12px;border-top:1px solid #f1f5f9}
.mode-card-btn{font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:6px;transition:.15s}
.mode-card:hover .mode-card-btn{gap:10px}
.mode-card-lock{font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:4px}
.mode-card-lock.connected{color:#059669;font-weight:600}

.lock-icon{font-size:13px;line-height:1}

/* Couleurs par mode */
.mode-explore{border-top-color:#10b981}
.mode-explore .mode-card-icon{background:linear-gradient(135deg,#10b981,#059669)}
.mode-explore .mode-card-tag{background:#dcfce7;color:#065f46}
.mode-explore .mode-card-btn{color:#059669;cursor:pointer}
.mode-explore-link{text-decoration:none;color:inherit;display:flex;flex-direction:column;flex:1;gap:10px}

.mode-praticien{border-top-color:#1d4ed8}
.mode-praticien .mode-card-icon{background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.mode-praticien .mode-card-tag{background:#dbeafe;color:#1e40af}

.mode-stats{border-top-color:#7c3aed}
.mode-stats .mode-card-icon{background:linear-gradient(135deg,#7c3aed,#a855f7)}
.mode-stats .mode-card-tag{background:#f3e8ff;color:#5b21b6}
.mode-stats .mode-card-btn{color:#7c3aed}
.mode-stats-link{text-decoration:none;color:inherit;display:flex;flex-direction:column;flex:1;gap:10px}

.mode-admin{border-top-color:#dc2626}
.mode-admin .mode-card-icon{background:linear-gradient(135deg,#dc2626,#b91c1c)}
.mode-admin .mode-card-tag{background:#fee2e2;color:#991b1b}
.mode-admin .mode-card-btn{color:#dc2626}
.mode-admin-link{text-decoration:none;color:inherit;display:flex;flex-direction:column;flex:1;gap:10px}

.connected-tag{position:absolute;top:14px;right:14px;background:#059669;color:#fff;
                font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
                padding:3px 9px;border-radius:999px;display:flex;align-items:center;gap:4px}
.connected-tag::before{content:"";width:5px;height:5px;background:#fff;border-radius:50%}

.foot{text-align:center;color:rgba(255,255,255,.55);font-size:12px;margin-top:24px;line-height:1.6}
.foot strong{color:rgba(255,255,255,.8)}
</style>
</head>
<body>

<div class="welcome">

    <div class="head">
        <div class="head-brand">
            <div class="head-brand-icon">A</div>
            APA4CAD
        </div>
        <h1>Bienvenue sur APA4CAD</h1>
        <p>Système d'aide à la prescription d'activité physique adaptée<br>basé sur une ontologie.</p>
    </div>

    <div class="modes">

        <!-- ━━━ 1. EXPLORATION LIBRE ━━━ -->
        <div class="mode-card mode-explore">
            <div class="mode-card-tag">Libre · sans connexion</div>
            <a href="index.php?mode=explore" class="mode-explore-link">
                <div class="mode-card-icon">🔓</div>
                <h2>Exploration libre</h2>
                <div class="mode-card-desc">
                    Découvrez les pathologies de l'ontologie et leurs recommandations d'activités
                    physiques adaptées.
                </div>
                <ul class="capabilities">
                    <li>Visualiser l'arbre des pathologies</li>
                    <li>Consulter les recommandations</li>
                    <li>Voir les contre-indications</li>
                    <li class="disabled">Prescrire (réservé praticien)</li>
                    <li class="disabled">Gérer les patients (réservé praticien)</li>
                </ul>
            </a>
            <div class="mode-card-foot">
                <a href="index.php?mode=explore" class="mode-card-btn">Explorer →</a>
                <span class="mode-card-lock"><span class="lock-icon">🔓</span> Aucun mot de passe</span>
            </div>
        </div>

        <!-- ━━━ 2. ESPACE PRATICIEN ━━━ -->
        <div class="mode-card mode-praticien">
            <?php if ($isPraticienConnected): ?>
                <div class="connected-tag">Connecté</div>
            <?php else: ?>
                <div class="mode-card-tag">Mode complet</div>
            <?php endif; ?>
            <div class="mode-card-icon">🩺</div>
            <h2>Espace praticien</h2>
            <div class="mode-card-desc">
                <?php if ($isPraticienConnected): ?>
                    Vous êtes connecté en tant que <strong><?= htmlspecialchars(currentPraticienName(), ENT_QUOTES) ?></strong>.
                    Choisissez comment continuer :
                <?php else: ?>
                    Créer une prescription adaptée pour un patient. Choisissez par où commencer :
                <?php endif; ?>
            </div>

            <!-- 2 sous-boutons -->
            <div class="sub-actions">
                <a href="<?= htmlspecialchars($praticienExistingUrl, ENT_QUOTES) ?>" class="sub-action">
                    <span class="sub-action-text">
                        <span class="sub-action-icon">🔍</span>
                        <span>Patient existant</span>
                    </span>
                    <span class="sub-action-arrow">→</span>
                </a>
                <a href="<?= htmlspecialchars($praticienNewUrl, ENT_QUOTES) ?>" class="sub-action">
                    <span class="sub-action-text">
                        <span class="sub-action-icon">➕</span>
                        <span>Nouveau patient (commencer par les pathologies)</span>
                    </span>
                    <span class="sub-action-arrow">→</span>
                </a>
            </div>

            <div class="mode-card-foot">
                <?php if ($isPraticienConnected): ?>
                    <a href="logout_praticien.php" style="color:#dc2626;font-size:12px;font-weight:600;text-decoration:none">
                        ✕ Se déconnecter
                    </a>
                    <span class="mode-card-lock connected">
                        <span class="lock-icon">🔓</span> Connecté
                    </span>
                <?php else: ?>
                    <a href="login_praticien.php" style="color:#1d4ed8;font-size:12px;font-weight:600;text-decoration:none">
                        → Se connecter d'abord
                    </a>
                    <span class="mode-card-lock"><span class="lock-icon">🔒</span> Mot de passe praticien</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ━━━ 3. STATISTIQUES ━━━ -->
        <div class="mode-card mode-stats">
            <?php if ($isAdminConnected): ?>
                <div class="connected-tag">Connecté</div>
            <?php else: ?>
                <div class="mode-card-tag">Tableaux de bord</div>
            <?php endif; ?>
            <a href="<?= $isAdminConnected ? 'stats.php' : 'stats_login.php' ?>" class="mode-stats-link">
                <div class="mode-card-icon">📊</div>
                <h2>Statistiques</h2>
                <div class="mode-card-desc">
                    Analyses détaillées : activité dans le temps, répartition des pathologies,
                    démographie patients, indicateurs qualité, exports CSV/Excel.
                </div>
            </a>
            <div class="mode-card-foot">
                <a href="<?= $isAdminConnected ? 'stats.php' : 'stats_login.php' ?>" class="mode-card-btn">
                    <?= $isAdminConnected ? 'Consulter →' : 'Accéder →' ?>
                </a>
                <span class="mode-card-lock <?= $isAdminConnected ? 'connected' : '' ?>">
                    <span class="lock-icon"><?= $isAdminConnected ? '🔓' : '🔒' ?></span>
                    <?= $isAdminConnected ? 'Connecté' : 'Mot de passe admin' ?>
                </span>
            </div>
        </div>

        <!-- ━━━ 4. ADMINISTRATION ━━━ -->
        <div class="mode-card mode-admin">
            <?php if ($isAdminConnected): ?>
                <div class="connected-tag">Connecté</div>
            <?php else: ?>
                <div class="mode-card-tag">Gestion système</div>
            <?php endif; ?>
            <a href="<?= $isAdminConnected ? 'admin/index.php' : 'admin/login.php' ?>" class="mode-admin-link">
                <div class="mode-card-icon">🛠</div>
                <h2>Administration</h2>
                <div class="mode-card-desc">
                    Gestion des praticiens, mots de passe et mise à jour de l'ontologie.
                    Réservé à l'administrateur du système.
                </div>
            </a>
            <div class="mode-card-foot">
                <a href="<?= $isAdminConnected ? 'admin/index.php' : 'admin/login.php' ?>" class="mode-card-btn">
                    <?= $isAdminConnected ? 'Accéder →' : 'Se connecter →' ?>
                </a>
                <span class="mode-card-lock <?= $isAdminConnected ? 'connected' : '' ?>">
                    <span class="lock-icon"><?= $isAdminConnected ? '🔓' : '🔒' ?></span>
                    <?= $isAdminConnected ? 'Connecté' : 'Mot de passe admin' ?>
                </span>
            </div>
        </div>

    </div>

    <div class="foot">
        <strong>APA4CAD</strong> · 
    </div>

</div>

</body>
</html>
