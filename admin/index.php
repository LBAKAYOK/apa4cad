<?php
/**
 * APA4CAD - Dashboard administrateur (v3 - amélioré)
 *
 * Améliorations :
 *   - Topbar simplifiée (Ontologie retiré, bouton Accueil en évidence)
 *   - Bouton flottant Accueil en bas à droite (toujours visible)
 *   - Header avec gradient et icône
 *   - Tuiles d'action avec effet visuel renforcé
 *   - Cartes du bas plus polies
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

// ─── Helpers ────────────────────────────────────────────────────────────
function sparqlD(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    return $d['results']['bindings'] ?? [];
}
function hD(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ─── Compteurs simples pour les 3 tuiles ────────────────────────────────

// 1) Praticiens actifs / inactifs
$r = sparqlD(sparqlPrefixes() . "
    SELECT
        (COUNT(?p) AS ?total)
        (SUM(IF(BOUND(?inactive) && ?inactive = true, 1, 0)) AS ?inactifs)
    WHERE {
        ?p a ex:Praticien .
        OPTIONAL { ?p ex:estPraticienInactif ?inactive }
    }
");
$totalPraticiens    = (int)($r[0]['total']['value']    ?? 0);
$praticiensInactifs = (int)($r[0]['inactifs']['value'] ?? 0);
$praticiensActifs   = max(0, $totalPraticiens - $praticiensInactifs);

// 2) Pathologies actives dans l'ontologie
//    On part des 5 racines connues + on suit la hiérarchie subClassOf*
//    + on traverse les intersections OWL (rdf:rest*/rdf:first)
//    + on exclut les pathologies inactives (soft delete)
$ns = ONTO_NAMESPACE;
$query = "
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX ex:   <$ns>

SELECT (COUNT(DISTINCT ?p) AS ?n) WHERE {
  {
    # Pathologies par hiérarchie directe (subClassOf*)
    ?p rdfs:subClassOf* ?root .
    VALUES ?root {
        ex:AffectionDeLongueDuree
        ex:PathologieCardiaque
        ex:PathologieDigestive
        ex:PathologieMusculosquelettique
        ex:PathologieRespiratoire
    }
  } UNION {
    # Pathologies via intersection OWL
    ?p rdfs:subClassOf ?b .
    ?b owl:intersectionOf/rdf:rest*/rdf:first ?root .
    VALUES ?root {
        ex:AffectionDeLongueDuree
        ex:PathologieCardiaque
        ex:PathologieDigestive
        ex:PathologieMusculosquelettique
        ex:PathologieRespiratoire
    }
  }
  FILTER(STRSTARTS(STR(?p), \"$ns\"))
  FILTER NOT EXISTS { ?p ex:estPathologieInactive ?i }
  # On exclut les 5 racines elles-mêmes pour ne compter que les pathologies réelles
  FILTER(?p != ex:AffectionDeLongueDuree)
  FILTER(?p != ex:PathologieCardiaque)
  FILTER(?p != ex:PathologieDigestive)
  FILTER(?p != ex:PathologieMusculosquelettique)
  FILTER(?p != ex:PathologieRespiratoire)
}
";
$r = sparqlD($query);
$nbPathologies = (int)($r[0]['n']['value'] ?? 0);

// 3) Date du dernier praticien créé
$r = sparqlD(sparqlPrefixes() . "
    SELECT ?date WHERE {
        ?p a ex:Praticien ; ex:aPourDateCreation ?date .
    }
    ORDER BY DESC(?date)
    LIMIT 1
");
$lastPraticienDate = $r[0]['date']['value'] ?? '';

// Détection du fallback en clair (mdp par défaut)
$adminDefaultPasswordActive = defined('ADMIN_DEFAULT_PASSWORD_PLAIN');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dashboard · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;
     padding-bottom:80px}
a{color:#2563eb;text-decoration:none}

/* ════ Topbar admin sombre ════ */
.topbar-admin{background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:2px solid #1d4ed8;
              padding:14px 0;color:#f8fafc;position:sticky;top:0;z-index:50;
              box-shadow:0 2px 12px rgba(0,0,0,.15)}
.topbar-inner{max-width:1200px;margin:0 auto;padding:0 28px;display:flex;align-items:center;gap:24px}
.topbar-brand{font-weight:700;font-size:17px;color:#fff;display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-brand::before{content:"";width:5px;height:22px;background:#3b82f6;border-radius:2px}
.admin-badge{background:#dc2626;color:#fff;font-size:10px;font-weight:800;
             padding:3px 9px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.topbar-nav{display:flex;gap:6px;margin-left:auto;align-items:center}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#cbd5e1;font-weight:500;font-size:13px;
              transition:.15s;text-decoration:none}
.topbar-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.topbar-nav a.active{background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600}
.topbar-nav .home-link{background:linear-gradient(135deg,#10b981,#059669);color:#fff !important;
                        padding:8px 16px;font-weight:700;box-shadow:0 4px 10px rgba(5,150,105,.4)}
.topbar-nav .home-link:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(5,150,105,.5)}
.topbar-nav .logout-btn{background:#dc2626;color:#fff !important;padding:7px 14px;border-radius:8px;font-weight:600;font-size:12px}
.topbar-nav .logout-btn:hover{background:#b91c1c}

.app{max-width:1200px;margin:0 auto;padding:36px 28px}

/* ════ Hero header (header en haut de la page) ════ */
.dash-hero{background:linear-gradient(135deg,#fef2f2 0%,#fff7ed 100%);
            border:1px solid #fecaca;border-radius:22px;padding:32px 36px;margin-bottom:32px;
            position:relative;overflow:hidden}
.dash-hero::before{content:"";position:absolute;top:-30px;right:-30px;width:160px;height:160px;
                    background:radial-gradient(circle,rgba(220,38,38,.08) 0%,transparent 70%);
                    border-radius:50%;pointer-events:none}
.dash-hero-inner{display:flex;align-items:center;gap:24px;position:relative;z-index:1}
.dash-hero-icon{width:72px;height:72px;border-radius:18px;
                  background:linear-gradient(135deg,#dc2626,#b91c1c);
                  display:flex;align-items:center;justify-content:center;font-size:36px;
                  color:#fff;box-shadow:0 10px 24px rgba(220,38,38,.4);flex-shrink:0}
.dash-hero-text{flex:1}
.dash-hero-tag{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#991b1b;
                font-size:10px;font-weight:800;padding:4px 11px;border-radius:50px;
                border:1px solid #fca5a5;letter-spacing:.6px;text-transform:uppercase;margin-bottom:10px}
.dash-hero h1{margin:0 0 6px;font-size:28px;font-weight:800;color:#0f172a;letter-spacing:-0.02em}
.dash-hero p{margin:0;color:#64748b;font-size:14px;line-height:1.55}

/* Alerte mdp par défaut */
.security-warn{background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;
                border-radius:14px;padding:14px 20px;margin-bottom:28px;
                display:flex;align-items:center;gap:14px;color:#78350f;
                box-shadow:0 4px 12px rgba(245,158,11,.15)}
.security-warn-icon{font-size:22px;flex-shrink:0}
.security-warn-text{flex:1;font-size:13px;line-height:1.5}
.security-warn-text strong{color:#92400e}
.security-warn-btn{background:#92400e;color:#fff;padding:8px 14px;border-radius:8px;
                    font-weight:700;font-size:12px;text-decoration:none;white-space:nowrap;transition:.15s}
.security-warn-btn:hover{background:#78350f;transform:translateY(-1px)}

/* ════ Les 3 tuiles d'action ════ */
.section-title{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;
                letter-spacing:1px;margin:0 0 14px;display:flex;align-items:center;gap:10px}
.section-title::before, .section-title::after{content:"";flex:1;height:1px;background:#e5e7eb}

.actions-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:36px}
@media(max-width:920px){.actions-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.actions-grid{grid-template-columns:1fr}}

.action-tile{background:#fff;border:1px solid #e5e7eb;border-radius:18px;
              padding:26px 26px 22px;text-decoration:none;color:inherit;display:flex;
              flex-direction:column;gap:12px;position:relative;overflow:hidden;
              transition:.3s cubic-bezier(.4,0,.2,1);box-shadow:0 2px 6px rgba(15,23,42,.04);
              border-top:5px solid;cursor:pointer}
.action-tile::before{content:"";position:absolute;top:-50px;right:-50px;width:140px;height:140px;
                      border-radius:50%;opacity:.08;transition:.3s;pointer-events:none}
.action-tile:hover{transform:translateY(-8px);box-shadow:0 22px 50px rgba(15,23,42,.15)}
.action-tile:hover::before{transform:scale(1.3);opacity:.15}

.action-icon{width:60px;height:60px;border-radius:16px;display:flex;align-items:center;
              justify-content:center;font-size:28px;color:#fff;box-shadow:0 8px 20px rgba(0,0,0,.18);
              transition:.3s}
.action-tile:hover .action-icon{transform:scale(1.06) rotate(-3deg)}

.action-tile h2{margin:8px 0 0;font-size:19px;font-weight:800;color:#0f172a;letter-spacing:-.01em}
.action-tile p{margin:0;font-size:13px;color:#64748b;line-height:1.55;flex:1}

.action-stat{display:flex;align-items:baseline;gap:8px;margin-top:4px;padding:10px 0;
              border-top:1px dashed #f1f5f9;border-bottom:1px dashed #f1f5f9}
.action-stat-num{font-size:26px;font-weight:800;letter-spacing:-.5px;line-height:1}
.action-stat-lbl{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px}

.action-cta{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700}
.action-cta-arrow{transition:.2s;font-size:18px;line-height:1}
.action-tile:hover .action-cta-arrow{transform:translateX(6px)}

/* Couleurs des tuiles */
.tile-praticiens{border-top-color:#1d4ed8}
.tile-praticiens::before{background:#3b82f6}
.tile-praticiens .action-icon{background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.tile-praticiens .action-stat-num{color:#1d4ed8}
.tile-praticiens .action-cta{color:#1d4ed8}

.tile-passwords{border-top-color:#f59e0b}
.tile-passwords::before{background:#f59e0b}
.tile-passwords .action-icon{background:linear-gradient(135deg,#f59e0b,#d97706)}
.tile-passwords .action-stat-num{color:#b45309}
.tile-passwords .action-cta{color:#b45309}

.tile-ontology{border-top-color:#7c3aed}
.tile-ontology::before{background:#a855f7}
.tile-ontology .action-icon{background:linear-gradient(135deg,#7c3aed,#a855f7)}
.tile-ontology .action-stat-num{color:#7c3aed}
.tile-ontology .action-cta{color:#7c3aed}

/* ════ Bloc inférieur : 2 cartes ════ */
.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:760px){.bottom-grid{grid-template-columns:1fr}}

/* Consultation tiles - plus discrètes que les actions principales */
.consult-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:32px}
@media(max-width:760px){.consult-grid{grid-template-columns:1fr}}
.consult-tile{background:#fff;border:1px solid #e5e7eb;border-radius:13px;
              padding:18px 22px;text-decoration:none;color:inherit;
              display:flex;align-items:center;gap:16px;
              transition:.2s;box-shadow:0 1px 3px rgba(15,23,42,.04);
              border-left:4px solid}
.consult-tile:hover{transform:translateX(4px);box-shadow:0 6px 18px rgba(15,23,42,.1)}
.consult-icon{width:48px;height:48px;border-radius:11px;display:flex;align-items:center;
              justify-content:center;font-size:22px;color:#fff;flex-shrink:0;
              box-shadow:0 4px 12px rgba(0,0,0,.12)}
.consult-text{flex:1;min-width:0}
.consult-text h3{margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a}
.consult-text p{margin:0;font-size:12px;color:#64748b;line-height:1.45}
.consult-arrow{font-size:20px;font-weight:700;color:#94a3b8;flex-shrink:0;transition:.2s}
.consult-tile:hover .consult-arrow{color:#1e293b;transform:translateX(2px)}
.tile-cprescr{border-left-color:#1d4ed8}
.tile-cprescr .consult-icon{background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.tile-cpat{border-left-color:#7c3aed}
.tile-cpat .consult-icon{background:linear-gradient(135deg,#7c3aed,#a855f7)}

.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:22px 24px;
      box-shadow:0 1px 3px rgba(15,23,42,.04);transition:.2s}
.card:hover{box-shadow:0 6px 18px rgba(15,23,42,.08)}
.card-title{font-size:13px;font-weight:800;color:#0f172a;text-transform:uppercase;
             letter-spacing:.5px;margin:0 0 16px;display:flex;align-items:center;gap:10px;
             padding-bottom:12px;border-bottom:2px solid #f1f5f9}
.card-title-icon{font-size:16px}

.account-links{display:flex;flex-direction:column;gap:10px}
.account-link{padding:14px 16px;border:1.5px solid;border-radius:11px;font-size:13px;
              font-weight:700;text-decoration:none;display:flex;align-items:center;justify-content:space-between;
              transition:.2s;background:#fff;color:#1e293b}
.account-link:hover{transform:translateX(4px);box-shadow:0 6px 14px rgba(15,23,42,.08)}
.account-link-icon{font-size:18px;margin-right:10px}
.account-link.al-blue{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.account-link.al-blue:hover{background:#dbeafe;border-color:#93c5fd}
.account-link.al-amber{background:#fffbeb;border-color:#fcd34d;color:#92400e}
.account-link.al-amber:hover{background:#fef3c7;border-color:#fbbf24}
.account-link-arrow{opacity:.4;font-size:14px;transition:.15s}
.account-link:hover .account-link-arrow{opacity:1;transform:translateX(2px)}

.info-row{display:flex;justify-content:space-between;align-items:center;padding:11px 0;
           border-bottom:1px solid #f1f5f9;font-size:13px}
.info-row:last-child{border-bottom:0}
.info-lbl{color:#64748b;font-weight:500;display:flex;align-items:center;gap:8px}
.info-lbl-icon{font-size:14px;opacity:.7}
.info-val{font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px}
.info-val.muted{color:#94a3b8;font-style:italic;font-weight:500}
.info-val.success{color:#059669}
.info-val.warn{color:#92400e}
.info-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:50px;text-transform:uppercase;letter-spacing:.4px}
.info-pill.success{background:#dcfce7;color:#065f46;border:1px solid #6ee7b7}
.info-pill.warn{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}

/* ════ Bouton flottant Accueil ════ */
.floating-home{position:fixed;bottom:24px;right:24px;z-index:100;
                background:linear-gradient(135deg,#10b981,#059669);color:#fff;
                padding:14px 22px;border-radius:50px;font-weight:700;font-size:14px;
                text-decoration:none;box-shadow:0 10px 28px rgba(5,150,105,.4);
                display:flex;align-items:center;gap:8px;transition:.25s;
                border:2px solid rgba(255,255,255,.3)}
.floating-home:hover{transform:translateY(-3px) scale(1.03);
                      box-shadow:0 14px 36px rgba(5,150,105,.5)}
.floating-home-icon{font-size:18px;line-height:1}

.admin-foot{text-align:center;color:#94a3b8;font-size:12px;margin-top:32px;padding-top:18px;border-top:1px solid #e5e7eb}
.admin-foot a{color:#2563eb;font-weight:600}

@keyframes pulse {
    0%, 100% { box-shadow: 0 10px 28px rgba(5,150,105,.4) }
    50% { box-shadow: 0 10px 28px rgba(5,150,105,.7) }
}
.floating-home{animation:pulse 3s ease-in-out infinite}
.floating-home:hover{animation:none}
</style>
</head>
<body>

<div class="topbar-admin">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <span class="admin-badge">Admin</span>
        <nav class="topbar-nav">
            <a href="index.php" class="active">📊 Dashboard</a>
            <a href="praticiens.php">👥 Praticiens</a>
            <a href="prescriptions_all.php">📋 Prescriptions</a>
            <a href="patients_all.php">👤 Patients</a>
            <a href="../welcome.php" class="home-link">🏠 Accueil</a>
            <a href="change_password.php">🔑 Mon compte</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <!-- ━━━ Hero header ━━━ -->
    <div class="dash-hero">
        <div class="dash-hero-inner">
            <div class="dash-hero-icon">🛠</div>
            <div class="dash-hero-text">
                <div class="dash-hero-tag">⚙ Espace administrateur</div>
                <h1>Bienvenue dans l'administration</h1>
                <p>Gérez les praticiens, leurs mots de passe et maintenez l'ontologie médicale.</p>
            </div>
        </div>
    </div>

    <?php if ($adminDefaultPasswordActive): ?>
    <div class="security-warn">
        <div class="security-warn-icon">⚠</div>
        <div class="security-warn-text">
            <strong>Mot de passe par défaut actif.</strong>
            Pour sécuriser l'accès à l'administration, changez-le dès maintenant.
        </div>
        <a href="change_password.php" class="security-warn-btn">🔑 Changer le mdp</a>
    </div>
    <?php endif; ?>

    <!-- ━━━ Section : 3 actions principales ━━━ -->
    <h3 class="section-title">Vos actions principales</h3>

    <div class="actions-grid">

        <!-- 1. Gestion des praticiens -->
        <a href="praticiens.php" class="action-tile tile-praticiens">
            <div class="action-icon">👥</div>
            <h2>Gestion des praticiens</h2>
            <p>Créer, modifier et désactiver les comptes praticien du système.</p>
            <div class="action-stat">
                <div class="action-stat-num"><?= $praticiensActifs ?></div>
                <div class="action-stat-lbl">praticien<?= $praticiensActifs > 1 ? 's' : '' ?> actif<?= $praticiensActifs > 1 ? 's' : '' ?></div>
            </div>
            <div class="action-cta">
                Gérer les praticiens <span class="action-cta-arrow">→</span>
            </div>
        </a>

        <!-- 2. Gestion des mots de passe -->
        <a href="praticiens.php" class="action-tile tile-passwords">
            <div class="action-icon">🔑</div>
            <h2>Gestion des mots de passe</h2>
            <p>Réinitialiser le mot de passe d'un praticien qui l'aurait perdu.</p>
            <div class="action-stat">
                <div class="action-stat-num">🔐</div>
                <div class="action-stat-lbl">Reset sécurisé via bcrypt</div>
            </div>
            <div class="action-cta">
                Réinitialiser un mdp <span class="action-cta-arrow">→</span>
            </div>
        </a>

        <!-- 3. Mise à jour de l'ontologie -->
        <a href="ontology.php" class="action-tile tile-ontology">
            <div class="action-icon">🩺</div>
            <h2>Mise à jour de l'ontologie</h2>
            <p>Ajouter, modifier ou désactiver des pathologies et leurs recommandations.</p>
            <div class="action-stat">
                <div class="action-stat-num"><?= $nbPathologies ?></div>
                <div class="action-stat-lbl">pathologies actives</div>
            </div>
            <div class="action-cta">
                Modifier l'ontologie <span class="action-cta-arrow">→</span>
            </div>
        </a>

    </div>

    <!-- ━━━ Section : Consultation (vue globale) ━━━ -->
    <h3 class="section-title">Consultation globale</h3>

    <div class="consult-grid">
        <a href="prescriptions_all.php" class="consult-tile tile-cprescr">
            <div class="consult-icon">📋</div>
            <div class="consult-text">
                <h3>Toutes les prescriptions</h3>
                <p>Vue d'ensemble de toutes les prescriptions du système, filtrables par praticien, patient ou date.</p>
            </div>
            <div class="consult-arrow">→</div>
        </a>
        <a href="patients_all.php" class="consult-tile tile-cpat">
            <div class="consult-icon">👤</div>
            <div class="consult-text">
                <h3>Tous les patients</h3>
                <p>Liste complète des patients enregistrés dans le système, avec leur praticien créateur.</p>
            </div>
            <div class="consult-arrow">→</div>
        </a>
    </div>

    <!-- ━━━ Section : Mon compte + Infos ━━━ -->
    <h3 class="section-title">Mon compte &amp; informations</h3>

    <div class="bottom-grid">

        <!-- Mon compte -->
        <div class="card">
            <h3 class="card-title">
                <span class="card-title-icon">🔐</span>
                Mon compte administrateur
            </h3>
            <div class="account-links">
                <a href="change_password.php" class="account-link al-blue">
                    <span><span class="account-link-icon">🔑</span> Changer mon mot de passe</span>
                    <span class="account-link-arrow">→</span>
                </a>
                <a href="regenerate_key.php" class="account-link al-amber">
                    <span><span class="account-link-icon">🔁</span> Régénérer ma clé de secours</span>
                    <span class="account-link-arrow">→</span>
                </a>
            </div>
        </div>

        <!-- Informations système -->
        <div class="card">
            <h3 class="card-title">
                <span class="card-title-icon">ℹ</span>
                Informations système
            </h3>
            <div class="info-row">
                <span class="info-lbl">
                    <span class="info-lbl-icon">👥</span> Praticiens enregistrés
                </span>
                <span class="info-val"><?= $totalPraticiens ?> (<?= $praticiensActifs ?> actif<?= $praticiensActifs > 1 ? 's' : '' ?>)</span>
            </div>
            <div class="info-row">
                <span class="info-lbl">
                    <span class="info-lbl-icon">🩺</span> Pathologies actives
                </span>
                <span class="info-val"><?= $nbPathologies ?></span>
            </div>
            <div class="info-row">
                <span class="info-lbl">
                    <span class="info-lbl-icon">📅</span> Dernier praticien créé
                </span>
                <?php if ($lastPraticienDate !== ''): ?>
                    <span class="info-val"><?= hD((new DateTime($lastPraticienDate))->format('d/m/Y')) ?></span>
                <?php else: ?>
                    <span class="info-val muted">Aucun</span>
                <?php endif; ?>
            </div>
            <div class="info-row">
                <span class="info-lbl">
                    <span class="info-lbl-icon">🛡</span> Sécurité du mdp admin
                </span>
                <?php if ($adminDefaultPasswordActive): ?>
                    <span class="info-pill warn">⚠ Mdp par défaut</span>
                <?php else: ?>
                    <span class="info-pill success">✓ Personnalisé</span>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="admin-foot">
        APA4CAD Admin · <a href="../welcome.php">← Retour à l'accueil</a>
    </div>

</div>

<!-- ━━━ Bouton flottant Accueil ━━━ -->
<a href="../welcome.php" class="floating-home" title="Retour à la page d'accueil">
    <span class="floating-home-icon">🏠</span>
    <span>Accueil</span>
</a>

</body>
</html>
