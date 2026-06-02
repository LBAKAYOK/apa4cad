<?php
/**
 * APA4CAD - Gestion des praticiens (Admin)
 *
 * Permet à l'admin de :
 *   - Lister tous les praticiens (actifs / inactifs)
 *   - Créer un nouveau praticien (Prénom + Nom + mdp initial)
 *   - Modifier prénom/nom
 *   - Changer le mot de passe (reset par l'admin)
 *   - Désactiver (soft delete) un praticien
 *   - Réactiver un praticien désactivé
 *
 * Modèle Fuseki :
 *   ex:Praticien_xxx
 *     rdf:type ex:Praticien ;
 *     ex:aPourPrenom "Jean" ;
 *     ex:aPourNom "Dupont" ;
 *     ex:aPourMotDePasseHash "$2y$10$..." ;
 *     ex:aPourDateCreation "2026-05-27T..." ;
 *     [optionnel] ex:estPraticienInactif true ;
 *     [optionnel] ex:aPourDateDesactivation "..."
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

// ─── Helpers ────────────────────────────────────────────────────────────
function sparqlP(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    return $d['results']['bindings'] ?? [];
}
function hP(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Normalise prénom+nom en identifiant local OWL valide
 */
function makePraticienLocalName(string $prenom, string $nom): string {
    $combined = trim($prenom . '_' . $nom);
    $combined = preg_replace('/\s+/', '_', $combined);
    $combined = preg_replace('/[^A-Za-z0-9_]/', '', $combined);
    return 'Praticien_' . $combined . '_' . substr(md5(uniqid('', true)), 0, 6);
}

// ─── Traitement des actions POST ────────────────────────────────────────
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ═══ CRÉATION D'UN PRATICIEN ═══
    if ($action === 'create') {
        $prenom = trim((string)($_POST['prenom'] ?? ''));
        $nom    = trim((string)($_POST['nom']    ?? ''));
        $pwd    = (string)($_POST['password']    ?? '');
        $pwd2   = (string)($_POST['password_confirm'] ?? '');

        if ($prenom === '' || $nom === '') {
            $flash = ['type' => 'error', 'msg' => 'Le prénom et le nom sont obligatoires.'];
        } elseif (strlen($pwd) < 6) {
            $flash = ['type' => 'error', 'msg' => 'Le mot de passe doit faire au moins 6 caractères.'];
        } elseif ($pwd !== $pwd2) {
            $flash = ['type' => 'error', 'msg' => 'Les deux mots de passe ne correspondent pas.'];
        } else {
            $localName = makePraticienLocalName($prenom, $nom);
            $uri = ONTO_NAMESPACE . $localName;
            $hash = password_hash($pwd, PASSWORD_BCRYPT);
            $now = date('Y-m-d\TH:i:s');

            $query = sparqlPrefixes() . " INSERT DATA {
                <$uri> rdf:type owl:NamedIndividual ;
                       rdf:type ex:Praticien ;
                       ex:aPourPrenom \"" . sparqlEscapeString($prenom) . "\" ;
                       ex:aPourNom \"" . sparqlEscapeString($nom) . "\" ;
                       ex:aPourMotDePasseHash \"" . sparqlEscapeString($hash) . "\" ;
                       ex:aPourDateCreation \"$now\"^^xsd:dateTime .
            }";
            $res = sparqlUpdate($query);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => "Praticien « $prenom $nom » créé avec succès."]
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }

    // ═══ MODIFICATION DES INFOS (prénom/nom) ═══
    elseif ($action === 'edit') {
        $uri    = trim((string)($_POST['praticien_uri'] ?? ''));
        $prenom = trim((string)($_POST['prenom']        ?? ''));
        $nom    = trim((string)($_POST['nom']           ?? ''));

        if ($uri === '' || !str_starts_with($uri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Praticien invalide.'];
        } elseif ($prenom === '' || $nom === '') {
            $flash = ['type' => 'error', 'msg' => 'Le prénom et le nom sont obligatoires.'];
        } else {
            $delQuery = sparqlPrefixes() . "
                DELETE { <$uri> ex:aPourPrenom ?p ; ex:aPourNom ?n }
                WHERE  { <$uri> ex:aPourPrenom ?p ; ex:aPourNom ?n }
            ";
            sparqlUpdate($delQuery);

            $insQuery = sparqlPrefixes() . " INSERT DATA {
                <$uri> ex:aPourPrenom \"" . sparqlEscapeString($prenom) . "\" ;
                       ex:aPourNom \"" . sparqlEscapeString($nom) . "\" .
            }";
            $res = sparqlUpdate($insQuery);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => 'Informations mises à jour.']
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }

    // ═══ CHANGEMENT DE MOT DE PASSE (reset par l'admin) ═══
    elseif ($action === 'change_password') {
        $uri  = trim((string)($_POST['praticien_uri'] ?? ''));
        $pwd  = (string)($_POST['password']           ?? '');
        $pwd2 = (string)($_POST['password_confirm']   ?? '');

        if ($uri === '' || !str_starts_with($uri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Praticien invalide.'];
        } elseif (strlen($pwd) < 6) {
            $flash = ['type' => 'error', 'msg' => 'Le mot de passe doit faire au moins 6 caractères.'];
        } elseif ($pwd !== $pwd2) {
            $flash = ['type' => 'error', 'msg' => 'Les deux mots de passe ne correspondent pas.'];
        } else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT);

            $delQuery = sparqlPrefixes() . "
                DELETE { <$uri> ex:aPourMotDePasseHash ?h }
                WHERE  { <$uri> ex:aPourMotDePasseHash ?h }
            ";
            sparqlUpdate($delQuery);

            $insQuery = sparqlPrefixes() . " INSERT DATA {
                <$uri> ex:aPourMotDePasseHash \"" . sparqlEscapeString($hash) . "\" .
            }";
            $res = sparqlUpdate($insQuery);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => 'Mot de passe réinitialisé.']
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }

    // ═══ DÉSACTIVATION (soft delete) ═══
    elseif ($action === 'deactivate') {
        $uri = trim((string)($_POST['praticien_uri'] ?? ''));
        if ($uri === '' || !str_starts_with($uri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Praticien invalide.'];
        } else {
            $now = date('Y-m-d\TH:i:s');
            $insQuery = sparqlPrefixes() . " INSERT DATA {
                <$uri> ex:estPraticienInactif \"true\"^^xsd:boolean ;
                       ex:aPourDateDesactivation \"$now\"^^xsd:dateTime .
            }";
            $res = sparqlUpdate($insQuery);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => 'Praticien désactivé. Son historique est conservé.']
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }

    // ═══ RÉACTIVATION ═══
    elseif ($action === 'reactivate') {
        $uri = trim((string)($_POST['praticien_uri'] ?? ''));
        if ($uri === '' || !str_starts_with($uri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Praticien invalide.'];
        } else {
            $delQuery = sparqlPrefixes() . "
                DELETE {
                    <$uri> ex:estPraticienInactif ?v1 ;
                           ex:aPourDateDesactivation ?v2 .
                }
                WHERE {
                    <$uri> ex:estPraticienInactif ?v1 ;
                           ex:aPourDateDesactivation ?v2 .
                }
            ";
            $res = sparqlUpdate($delQuery);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => 'Praticien réactivé.']
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }
}

// ─── Chargement de tous les praticiens ──────────────────────────────────
$praticiens = [];
$query = sparqlPrefixes() . "
    SELECT ?uri ?prenom ?nom ?date ?inactive ?dateDeact WHERE {
        ?uri a ex:Praticien ;
             ex:aPourPrenom ?prenom ;
             ex:aPourNom ?nom .
        OPTIONAL { ?uri ex:aPourDateCreation ?date }
        OPTIONAL { ?uri ex:estPraticienInactif ?inactive }
        OPTIONAL { ?uri ex:aPourDateDesactivation ?dateDeact }
    }
    ORDER BY ?nom ?prenom
";
foreach (sparqlP($query) as $r) {
    $praticiens[] = [
        'uri'        => $r['uri']['value'] ?? '',
        'prenom'     => $r['prenom']['value'] ?? '',
        'nom'        => $r['nom']['value'] ?? '',
        'date'       => $r['date']['value'] ?? '',
        'inactive'   => ($r['inactive']['value'] ?? '') === 'true',
        'date_deact' => $r['dateDeact']['value'] ?? '',
    ];
}

// Compter ceux ayant des prescriptions signées (pour info)
$prescCounts = []; // uri => nb prescriptions
$pc = sparqlP(sparqlPrefixes() . "
    SELECT ?prat (COUNT(?p) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:prescritPar ?prat .
    }
    GROUP BY ?prat
");
foreach ($pc as $r) {
    $prescCounts[$r['prat']['value'] ?? ''] = (int)($r['n']['value'] ?? 0);
}

$totalActifs   = count(array_filter($praticiens, fn($p) => !$p['inactive']));
$totalInactifs = count(array_filter($praticiens, fn($p) => $p['inactive']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Gestion des praticiens · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}

/* Topbar admin */
.topbar-admin{background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:2px solid #1d4ed8;
              padding:14px 0;color:#f8fafc}
.topbar-inner{max-width:1400px;margin:0 auto;padding:0 28px;display:flex;align-items:center;gap:24px}
.topbar-brand{font-weight:700;font-size:17px;color:#fff;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#3b82f6;border-radius:2px}
.admin-badge{background:#dc2626;color:#fff;font-size:10px;font-weight:800;
             padding:3px 9px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.topbar-nav{display:flex;gap:6px;margin-left:auto;align-items:center}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#cbd5e1;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.topbar-nav a.active{background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600}
.topbar-nav .logout-btn{background:#dc2626;color:#fff !important;padding:7px 14px;border-radius:8px;font-weight:600;font-size:12px}

.app{max-width:1100px;margin:0 auto;padding:28px}

.dash-header{margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px}
.dash-header h1{margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a}
.dash-header p{margin:0;color:#64748b;font-size:13px}

.btn-primary{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;
             border-radius:11px;padding:11px 22px;font-size:14px;font-weight:700;
             cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:8px;
             box-shadow:0 6px 16px rgba(37,99,235,.3);transition:.15s;text-decoration:none}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(37,99,235,.4)}

/* Mini-stats */
.mini-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px}
@media(max-width:600px){.mini-stats{grid-template-columns:1fr}}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 22px;
           box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid;
           display:flex;flex-direction:column;gap:6px}
.stat-num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-.5px}
.stat-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.sc-active{border-left-color:#059669} .sc-active .stat-num{color:#059669}
.sc-inactive{border-left-color:#94a3b8} .sc-inactive .stat-num{color:#475569}

/* Liste des praticiens */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:0;
      box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:14px;overflow:hidden}
.prat-row{display:flex;align-items:center;gap:14px;padding:16px 22px;
          border-bottom:1px solid #f1f5f9;transition:.15s}
.prat-row:last-child{border-bottom:0}
.prat-row:hover{background:#fafbfc}
.prat-row.inactive{opacity:.65;background:#fafbfc}
.prat-avatar{width:42px;height:42px;border-radius:50%;
              background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;
              display:flex;align-items:center;justify-content:center;
              font-weight:800;font-size:15px;flex-shrink:0}
.prat-row.inactive .prat-avatar{background:linear-gradient(135deg,#94a3b8,#64748b)}
.prat-info{flex:1;min-width:0}
.prat-name{font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.prat-tag-inactive{font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;
                    text-transform:uppercase;padding:2px 8px;border-radius:5px;letter-spacing:.4px}
.prat-tag-presc{font-size:11px;font-weight:600;background:#fef3c7;color:#92400e;
                 border:1px solid #fcd34d;padding:1px 8px;border-radius:10px}
.prat-meta{font-size:11px;color:#94a3b8;margin-top:3px}
.prat-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.btn-action{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;
            border:1px solid;background:#fff;font-family:inherit;transition:.15s;text-decoration:none;display:inline-block}
.btn-edit{border-color:#bfdbfe;color:#1d4ed8}
.btn-edit:hover{background:#eff6ff}
.btn-key{border-color:#fcd34d;color:#92400e}
.btn-key:hover{background:#fef3c7}
.btn-deact{border-color:#fca5a5;color:#991b1b}
.btn-deact:hover{background:#fef2f2}
.btn-react{border-color:#a7f3d0;color:#047857}
.btn-react:hover{background:#dcfce7}

.flash{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;
       display:flex;align-items:flex-start;gap:10px;line-height:1.5}
.flash-success{background:#dcfce7;border:1px solid #6ee7b7;color:#065f46}
.flash-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}

/* Modales */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);
               display:none;align-items:flex-start;justify-content:center;
               z-index:1000;padding:60px 20px;overflow-y:auto;animation:fadeIn .2s ease-out}
.modal-overlay.open{display:flex}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:#fff;border-radius:18px;width:100%;max-width:480px;
       box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;
       animation:slideIn .25s cubic-bezier(.4,0,.2,1)}
@keyframes slideIn{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
.modal-head h2{margin:0;font-size:18px;font-weight:800;color:#1e293b}
.modal-close{background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer;
             padding:4px 10px;border-radius:8px;font-family:inherit}
.modal-close:hover{background:#f1f5f9;color:#1e293b}
.modal-body{padding:20px 24px}
.modal-foot{padding:14px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;gap:10px;background:#f8fafc}

.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input{width:100%;padding:10px 12px;border:1.5px solid #e5e7eb;
             border-radius:9px;font-size:14px;font-family:inherit;background:#fff}
.field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.field .hint{font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic}

.btn-cancel{background:#fff;color:#64748b;border:1px solid #e5e7eb;border-radius:9px;
            padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-cancel:hover{background:#f8fafc;color:#1e293b}
.btn-submit{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:9px;
            padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
            box-shadow:0 4px 12px rgba(37,99,235,.3)}
.btn-submit:hover{transform:translateY(-1px)}
.btn-danger{background:#dc2626;color:#fff;border:none;border-radius:9px;padding:10px 18px;
            font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}

.empty{padding:50px 20px;text-align:center;color:#94a3b8;font-style:italic;font-size:14px}
.empty-icon{font-size:42px;margin-bottom:10px;display:block}
</style>
</head>
<body>

<div class="topbar-admin">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <span class="admin-badge">Admin</span>
        <nav class="topbar-nav">
            <a href="index.php">📊 Dashboard</a>
            <a href="praticiens.php" class="active">👥 Praticiens</a>
            <a href="ontology.php">🩺 Ontologie</a>
            <a href="../welcome.php">← Accueil</a>
            <a href="change_password.php">🔑 Mon compte</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <div class="dash-header">
        <div>
            <h1>👥 Gestion des praticiens</h1>
            <p>Créer, modifier et gérer les comptes praticien du système.</p>
        </div>
        <button class="btn-primary" onclick="openModal('modal-create')">＋ Créer un praticien</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= hP($flash['type']) ?>">
            <span><?= $flash['type'] === 'success' ? '✓' : '⚠' ?></span>
            <span><?= hP($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="mini-stats">
        <div class="stat-card sc-active">
            <div class="stat-num"><?= $totalActifs ?></div>
            <div class="stat-lbl">Praticiens actifs</div>
        </div>
        <div class="stat-card sc-inactive">
            <div class="stat-num"><?= $totalInactifs ?></div>
            <div class="stat-lbl">Praticiens inactifs</div>
        </div>
    </div>

    <!-- Liste -->
    <div class="card">
        <?php if (empty($praticiens)): ?>
            <div class="empty">
                <span class="empty-icon">👥</span>
                Aucun praticien enregistré pour l'instant.<br>
                Cliquez sur « ＋ Créer un praticien » pour commencer.
            </div>
        <?php else:
            foreach ($praticiens as $p):
                $initials = strtoupper(mb_substr($p['prenom'], 0, 1) . mb_substr($p['nom'], 0, 1));
                $nbPresc = $prescCounts[$p['uri']] ?? 0;
                $editData = ['uri' => $p['uri'], 'prenom' => $p['prenom'], 'nom' => $p['nom']];
        ?>
            <div class="prat-row<?= $p['inactive'] ? ' inactive' : '' ?>">
                <div class="prat-avatar"><?= hP($initials) ?></div>
                <div class="prat-info">
                    <div class="prat-name">
                        <?= hP($p['prenom'] . ' ' . $p['nom']) ?>
                        <?php if ($p['inactive']): ?>
                            <span class="prat-tag-inactive">Inactif</span>
                        <?php endif; ?>
                        <?php if ($nbPresc > 0): ?>
                            <span class="prat-tag-presc" title="<?= $nbPresc ?> prescription(s) signée(s)">
                                📋 <?= $nbPresc ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="prat-meta">
                        <?php if ($p['date']): ?>
                            Créé le <?= hP((new DateTime($p['date']))->format('d/m/Y')) ?>
                        <?php endif; ?>
                        <?php if ($p['inactive'] && $p['date_deact']): ?>
                            · désactivé le <?= hP((new DateTime($p['date_deact']))->format('d/m/Y')) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="prat-actions">
                    <button class="btn-action btn-edit"
                            onclick='openEditModal(<?= json_encode($editData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        ✏ Modifier
                    </button>
                    <button class="btn-action btn-key"
                            onclick='openPwdModal(<?= json_encode($editData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        🔑 Mot de passe
                    </button>
                    <?php if ($p['inactive']): ?>
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="action" value="reactivate">
                            <input type="hidden" name="praticien_uri" value="<?= hP($p['uri']) ?>">
                            <button type="submit" class="btn-action btn-react">↻ Réactiver</button>
                        </form>
                    <?php else: ?>
                        <button class="btn-action btn-deact"
                                onclick='openDeactModal(<?= json_encode($editData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            🗑 Désactiver
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

</div>

<!-- ━━━ MODALE : Créer un praticien ━━━ -->
<div class="modal-overlay" id="modal-create">
    <div class="modal">
        <div class="modal-head">
            <h2>＋ Créer un praticien</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-create')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="field">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" required placeholder="Ex : Jean">
                </div>
                <div class="field">
                    <label>Nom *</label>
                    <input type="text" name="nom" required placeholder="Ex : Dupont">
                </div>
                <div class="field">
                    <label>Mot de passe initial *</label>
                    <input type="password" name="password" required minlength="6">
                    <div class="hint">Minimum 6 caractères. Le praticien pourra le changer après.</div>
                </div>
                <div class="field">
                    <label>Confirmer le mot de passe *</label>
                    <input type="password" name="password_confirm" required minlength="6">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-create')">Annuler</button>
                <button type="submit" class="btn-submit">Créer le praticien →</button>
            </div>
        </form>
    </div>
</div>

<!-- ━━━ MODALE : Modifier un praticien ━━━ -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal">
        <div class="modal-head">
            <h2>✏ Modifier le praticien</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-edit')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="praticien_uri" id="edit-uri">
            <div class="modal-body">
                <div class="field">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" id="edit-prenom" required>
                </div>
                <div class="field">
                    <label>Nom *</label>
                    <input type="text" name="nom" id="edit-nom" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-edit')">Annuler</button>
                <button type="submit" class="btn-submit">Enregistrer →</button>
            </div>
        </form>
    </div>
</div>

<!-- ━━━ MODALE : Changer mot de passe ━━━ -->
<div class="modal-overlay" id="modal-pwd">
    <div class="modal">
        <div class="modal-head">
            <h2>🔑 Réinitialiser le mot de passe</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-pwd')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="praticien_uri" id="pwd-uri">
            <div class="modal-body">
                <p style="margin:0 0 16px;color:#475569;font-size:13px;line-height:1.5">
                    Vous allez définir un nouveau mot de passe pour
                    <strong id="pwd-name">...</strong>.<br>
                    L'ancien mot de passe sera invalidé immédiatement.
                </p>
                <div class="field">
                    <label>Nouveau mot de passe *</label>
                    <input type="password" name="password" required minlength="6" autofocus>
                    <div class="hint">Minimum 6 caractères.</div>
                </div>
                <div class="field">
                    <label>Confirmer le nouveau mot de passe *</label>
                    <input type="password" name="password_confirm" required minlength="6">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-pwd')">Annuler</button>
                <button type="submit" class="btn-submit">Réinitialiser →</button>
            </div>
        </form>
    </div>
</div>

<!-- ━━━ MODALE : Confirmation désactivation ━━━ -->
<div class="modal-overlay" id="modal-deact">
    <div class="modal">
        <div class="modal-head">
            <h2>🗑 Désactiver le praticien</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-deact')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="praticien_uri" id="deact-uri">
            <div class="modal-body">
                <p style="margin:0 0 14px;line-height:1.6">
                    Vous allez désactiver le praticien <strong id="deact-name">...</strong>.
                </p>
                <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;
                            padding:12px 14px;font-size:13px;color:#78350f;line-height:1.5">
                    💡 <strong>Soft delete</strong> : le praticien ne pourra plus se connecter,
                    mais son historique de prescriptions reste conservé pour traçabilité.
                    Il peut être réactivé à tout moment.
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-deact')">Annuler</button>
                <button type="submit" class="btn-danger">Confirmer la désactivation</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        const first = document.querySelector('#' + id + ' input[type="text"], #' + id + ' input[type="password"]');
        if (first) first.focus();
    }, 100);
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
    if (e.target === o) closeModal(o.id);
}));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => closeModal(o.id));
});

function openEditModal(data) {
    document.getElementById('edit-uri').value    = data.uri;
    document.getElementById('edit-prenom').value = data.prenom;
    document.getElementById('edit-nom').value    = data.nom;
    openModal('modal-edit');
}
function openPwdModal(data) {
    document.getElementById('pwd-uri').value          = data.uri;
    document.getElementById('pwd-name').textContent   = data.prenom + ' ' + data.nom;
    openModal('modal-pwd');
}
function openDeactModal(data) {
    document.getElementById('deact-uri').value         = data.uri;
    document.getElementById('deact-name').textContent  = data.prenom + ' ' + data.nom;
    openModal('modal-deact');
}
</script>

</body>
</html>
