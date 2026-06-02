<?php
/**
 * APA4CAD - Admin : tous les patients
 *
 * Vue administrateur permettant de :
 *   - Consulter tous les patients du système (tous praticiens confondus)
 *   - Voir qui a créé chaque patient
 *   - Cliquer sur un patient pour voir son détail
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

function sparqlPaA(string $query): array {
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
function hPaA(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function localNamePaA(string $uri): string {
    $pos = strrpos($uri, '#');
    return $pos !== false ? substr($uri, $pos + 1) : $uri;
}

// ─── Filtres ────────────────────────────────────────────────────────────
$qSearch    = trim((string)($_GET['q']         ?? ''));
$qPraticien = trim((string)($_GET['praticien'] ?? ''));

$filterParts = [];
if ($qSearch !== '') {
    $esc = sparqlEscapeString($qSearch);
    $filterParts[] = 'FILTER(CONTAINS(LCASE(STR(?nom)),    LCASE("' . $esc . '"))
                       || CONTAINS(LCASE(STR(?prenom)), LCASE("' . $esc . '"))
                       || CONTAINS(LCASE(STR(?dossier)), LCASE("' . $esc . '")))';
}
if ($qPraticien !== '' && str_starts_with($qPraticien, ONTO_NAMESPACE)) {
    $filterParts[] = 'FILTER(?createur = <' . $qPraticien . '>)';
}
$filterStr = implode("\n        ", $filterParts);

// ─── Charger tous les patients ───────────────────────────────────────────
$query = sparqlPrefixes() . "
    SELECT ?uri ?nom ?prenom ?age ?dossier ?genreLabel
           ?createur ?creaPrenom ?creaNom
           (COUNT(DISTINCT ?presc) AS ?nb_prescs) WHERE {
        ?uri a ex:Patient .
        OPTIONAL { ?uri ex:aPourNom ?nom }
        OPTIONAL { ?uri ex:aPourPrenom ?prenom }
        OPTIONAL { ?uri ex:aPourAge ?age }
        OPTIONAL { ?uri ex:aPourNumeroDossier ?dossier }
        OPTIONAL { ?uri ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
        OPTIONAL { ?uri ex:creePar ?createur .
                   OPTIONAL { ?createur ex:aPourPrenom ?creaPrenom }
                   OPTIONAL { ?createur ex:aPourNom    ?creaNom }
        }
        OPTIONAL { ?presc a ex:Prescription ; ex:concerne ?uri }
        $filterStr
    }
    GROUP BY ?uri ?nom ?prenom ?age ?dossier ?genreLabel
             ?createur ?creaPrenom ?creaNom
    ORDER BY ?nom ?prenom
";
$patients = [];
foreach (sparqlPaA($query) as $r) {
    $patients[] = [
        'uri'         => $r['uri']['value']        ?? '',
        'nom'         => $r['nom']['value']        ?? '',
        'prenom'      => $r['prenom']['value']     ?? '',
        'age'         => $r['age']['value']        ?? '',
        'dossier'     => $r['dossier']['value']    ?? '',
        'genre'       => $r['genreLabel']['value'] ?? '',
        'createur_uri'    => $r['createur']['value']  ?? '',
        'createur_prenom' => $r['creaPrenom']['value'] ?? '',
        'createur_nom'    => $r['creaNom']['value']    ?? '',
        'nb_prescs'   => (int)($r['nb_prescs']['value'] ?? 0),
    ];
}

$totalCount = count($patients);
$withPresc  = count(array_filter($patients, fn($p) => $p['nb_prescs'] > 0));
$withoutPresc = $totalCount - $withPresc;

// Liste des praticiens (pour le dropdown)
$allPraticiens = [];
foreach (sparqlPaA(sparqlPrefixes() . "
    SELECT ?uri ?prenom ?nom WHERE {
        ?uri a ex:Praticien .
        OPTIONAL { ?uri ex:aPourPrenom ?prenom }
        OPTIONAL { ?uri ex:aPourNom    ?nom }
    }
    ORDER BY ?nom ?prenom
") as $r) {
    $allPraticiens[] = [
        'uri'    => $r['uri']['value']    ?? '',
        'prenom' => $r['prenom']['value'] ?? '',
        'nom'    => $r['nom']['value']    ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Tous les patients · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5}
a{color:#2563eb;text-decoration:none}

.topbar-admin{background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:2px solid #1d4ed8;
              padding:14px 0;color:#f8fafc;position:sticky;top:0;z-index:50}
.topbar-inner{max-width:1400px;margin:0 auto;padding:0 28px;display:flex;align-items:center;gap:24px}
.topbar-brand{font-weight:700;font-size:17px;color:#fff;display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-brand::before{content:"";width:5px;height:22px;background:#3b82f6;border-radius:2px}
.admin-badge{background:#dc2626;color:#fff;font-size:10px;font-weight:800;
             padding:3px 9px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.topbar-nav{display:flex;gap:6px;margin-left:auto;align-items:center}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#cbd5e1;font-weight:500;font-size:13px;transition:.15s;text-decoration:none}
.topbar-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.topbar-nav a.active{background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600}
.topbar-nav .home-link{background:linear-gradient(135deg,#10b981,#059669);color:#fff !important;
                        padding:8px 16px;font-weight:700;box-shadow:0 4px 10px rgba(5,150,105,.4)}
.topbar-nav .logout-btn{background:#dc2626;color:#fff !important;padding:7px 14px;border-radius:8px;font-weight:600;font-size:12px}

.app{max-width:1400px;margin:0 auto;padding:28px}

.hero{background:linear-gradient(135deg,#7c3aed 0%,#a855f7 50%,#c084fc 100%);
      color:#fff;padding:28px 32px;border-radius:18px;margin-bottom:22px;
      box-shadow:0 10px 30px rgba(124,58,237,.2);position:relative;overflow:hidden}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);
           border:1px solid rgba(255,255,255,.2);padding:4px 12px;border-radius:50px;
           font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px}
.hero h1{margin:0 0 6px;font-size:26px;font-weight:800;letter-spacing:-.02em}
.hero p{margin:0;font-size:14px;opacity:.9}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
@media(max-width:760px){.stats-row{grid-template-columns:1fr}}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:13px;padding:16px 20px;
           box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid}
.stat-num{font-size:26px;font-weight:800;letter-spacing:-.5px;line-height:1.1}
.stat-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}
.stat-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.sc-total{border-left-color:#7c3aed} .sc-total .stat-num{color:#7c3aed}
.sc-with{border-left-color:#059669} .sc-with .stat-num{color:#059669}
.sc-without{border-left-color:#94a3b8} .sc-without .stat-num{color:#475569}

/* Filtres */
.filters-card{background:#fff;border:1px solid #e5e7eb;border-radius:13px;padding:18px 22px;
              box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:20px}
.filters-form{display:grid;grid-template-columns:2fr 1.5fr auto;gap:12px;align-items:end}
@media(max-width:760px){.filters-form{grid-template-columns:1fr}}
.field label{display:block;font-size:11px;font-weight:700;color:#64748b;
              text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.field input, .field select{width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;
                              border-radius:9px;font-size:13px;font-family:inherit;background:#fff}
.field input:focus, .field select:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15)}
.btn-filter{background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border:none;
            padding:10px 20px;border-radius:9px;font-weight:700;font-size:13px;
            cursor:pointer;font-family:inherit;height:38px}
.btn-filter:hover{transform:translateY(-1px)}
.btn-reset{color:#64748b;background:#f8fafc;border:1px solid #e5e7eb;padding:9px 16px;
            border-radius:9px;font-weight:600;font-size:12px;text-decoration:none;height:38px;
            display:inline-flex;align-items:center}

/* Tableau */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
            overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.table-head{padding:14px 22px;background:#f8fafc;border-bottom:1px solid #e5e7eb;
            font-size:13px;color:#64748b;display:flex;justify-content:space-between;align-items:center}
.table-head strong{color:#0f172a}

.patient-table{width:100%;border-collapse:collapse}
.patient-table th{background:#fafbfc;padding:11px 14px;text-align:left;font-size:11px;
                  font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;
                  border-bottom:1px solid #e5e7eb}
.patient-table td{padding:13px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
.patient-table tbody tr{transition:.1s;cursor:pointer}
.patient-table tbody tr:hover{background:#f8fafc}
.patient-table tbody tr:last-child td{border-bottom:0}

.pat-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a855f7);
             color:#fff;display:inline-flex;align-items:center;justify-content:center;
             font-weight:800;font-size:11px;margin-right:10px;vertical-align:middle}
.pat-name{font-weight:700;color:#0f172a}
.pat-meta-tag{font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 7px;border-radius:5px;
               border:1px solid #e5e7eb;font-weight:600;margin-right:4px}
.dossier-tag{font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8;
              border:1px solid #bfdbfe;padding:2px 8px;border-radius:5px;font-family:'Courier New',monospace}
.prat-tag{display:inline-flex;align-items:center;gap:6px;background:#f3e8ff;color:#5b21b6;
           border:1px solid #d8b4fe;padding:3px 10px;border-radius:50px;font-size:12px;font-weight:600}
.prat-tag-empty{background:#fef3c7;color:#92400e;border-color:#fcd34d;font-style:italic}
.presc-badge{background:#dcfce7;color:#065f46;border:1px solid #6ee7b7;
              font-size:11px;font-weight:700;padding:3px 9px;border-radius:50px}
.presc-badge-zero{background:#f1f5f9;color:#64748b;border-color:#e5e7eb}
.view-link{color:#7c3aed;font-weight:600;font-size:12px;text-decoration:none}
.view-link:hover{text-decoration:underline}

.empty{padding:50px 24px;text-align:center;color:#94a3b8;font-style:italic}
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
            <a href="praticiens.php">👥 Praticiens</a>
            <a href="prescriptions_all.php">📋 Prescriptions</a>
            <a href="patients_all.php" class="active">👤 Patients</a>
            <a href="ontology.php">🩺 Ontologie</a>
            <a href="../welcome.php" class="home-link">🏠 Accueil</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <div class="hero">
        <div class="hero-tag">👤 Vue administrateur</div>
        <h1>Tous les patients du système</h1>
        <p>Consultation globale de tous les dossiers patients, tous praticiens confondus.</p>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card sc-total">
            <div class="stat-num"><?= $totalCount ?></div>
            <div class="stat-lbl">Total patients</div>
            <div class="stat-sub">dans le système</div>
        </div>
        <div class="stat-card sc-with">
            <div class="stat-num"><?= $withPresc ?></div>
            <div class="stat-lbl">Avec prescription</div>
            <div class="stat-sub">au moins 1</div>
        </div>
        <div class="stat-card sc-without">
            <div class="stat-num"><?= $withoutPresc ?></div>
            <div class="stat-lbl">Sans prescription</div>
            <div class="stat-sub">à prescrire</div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="filters-card">
        <form class="filters-form" method="get">
            <div class="field">
                <label>🔍 Rechercher un patient</label>
                <input type="text" name="q" value="<?= hPaA($qSearch) ?>"
                       placeholder="Nom, prénom ou n° de dossier...">
            </div>
            <div class="field">
                <label>👤 Créé par</label>
                <select name="praticien">
                    <option value="">— Tous les praticiens —</option>
                    <?php foreach ($allPraticiens as $p): ?>
                        <option value="<?= hPaA($p['uri']) ?>" <?= $qPraticien === $p['uri'] ? 'selected' : '' ?>>
                            <?= hPaA($p['prenom'] . ' ' . $p['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:6px">
                <button type="submit" class="btn-filter">Filtrer</button>
                <?php if ($qSearch !== '' || $qPraticien !== ''): ?>
                    <a href="patients_all.php" class="btn-reset">↺</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Liste -->
    <div class="table-card">
        <div class="table-head">
            <span><strong><?= $totalCount ?></strong> patient<?= $totalCount > 1 ? 's' : '' ?> affiché<?= $totalCount > 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($patients)): ?>
            <div class="empty">
                <span class="empty-icon">👤</span>
                Aucun patient trouvé avec ces critères.
            </div>
        <?php else: ?>
        <table class="patient-table">
            <thead>
            <tr>
                <th>Patient</th>
                <th>Dossier</th>
                <th>Âge / Genre</th>
                <th>Créé par</th>
                <th>Prescriptions</th>
                <th style="text-align:right">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($patients as $p):
                $frag = localNamePaA($p['uri']);
                $initials = strtoupper(mb_substr($p['prenom'], 0, 1) . mb_substr($p['nom'], 0, 1));
                if ($initials === '') $initials = '?';
                $patientName = trim($p['prenom'] . ' ' . $p['nom']);
                if ($patientName === '') $patientName = 'Patient inconnu';
                $createurName = trim($p['createur_prenom'] . ' ' . $p['createur_nom']);
            ?>
            <tr onclick="window.location='../patient_detail.php?id=<?= urlencode($frag) ?>&from_admin=1'">
                <td>
                    <span class="pat-avatar"><?= hPaA($initials) ?></span>
                    <span class="pat-name"><?= hPaA($patientName) ?></span>
                </td>
                <td>
                    <?php if ($p['dossier']): ?>
                        <span class="dossier-tag"><?= hPaA($p['dossier']) ?></span>
                    <?php else: ?>
                        <span style="color:#94a3b8">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['age']): ?>
                        <span class="pat-meta-tag"><?= hPaA($p['age']) ?> ans</span>
                    <?php endif; ?>
                    <?php if ($p['genre']): ?>
                        <span class="pat-meta-tag"><?= hPaA($p['genre']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($createurName !== ''): ?>
                        <span class="prat-tag">👤 <?= hPaA($createurName) ?></span>
                    <?php else: ?>
                        <span class="prat-tag prat-tag-empty">Non spécifié</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="presc-badge<?= $p['nb_prescs'] === 0 ? ' presc-badge-zero' : '' ?>">
                        📋 <?= $p['nb_prescs'] ?>
                    </span>
                </td>
                <td style="text-align:right">
                    <a href="../patient_detail.php?id=<?= urlencode($frag) ?>&from_admin=1"
                       class="view-link" onclick="event.stopPropagation()">
                        Voir →
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
