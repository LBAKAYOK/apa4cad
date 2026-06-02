<?php
/**
 * APA4CAD - Admin : toutes les prescriptions
 *
 * Vue administrateur permettant de :
 *   - Consulter toutes les prescriptions du système (tous praticiens confondus)
 *   - Filtrer par praticien, patient, dates
 *   - Cliquer sur une prescription pour voir son détail
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

function sparqlPA(string $query): array {
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
function hPA(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function localNamePA(string $uri): string {
    $pos = strrpos($uri, '#');
    return $pos !== false ? substr($uri, $pos + 1) : $uri;
}

// ─── Filtres ────────────────────────────────────────────────────────────
$qPatient   = trim((string)($_GET['q']         ?? ''));
$qPraticien = trim((string)($_GET['praticien'] ?? ''));
$qDateFrom  = trim((string)($_GET['from']      ?? ''));
$qDateTo    = trim((string)($_GET['to']        ?? ''));

$filterParts = [];
if ($qPatient !== '') {
    $esc = sparqlEscapeString($qPatient);
    $filterParts[] = 'FILTER(CONTAINS(LCASE(STR(?nom)),    LCASE("' . $esc . '"))
                       || CONTAINS(LCASE(STR(?prenom)), LCASE("' . $esc . '"))
                       || CONTAINS(LCASE(STR(?dossier)), LCASE("' . $esc . '")))';
}
if ($qPraticien !== '' && str_starts_with($qPraticien, ONTO_NAMESPACE)) {
    $filterParts[] = 'FILTER(?praticien = <' . $qPraticien . '>)';
}
if ($qDateFrom !== '') {
    $filterParts[] = 'FILTER(?date >= "' . $qDateFrom . 'T00:00:00"^^xsd:dateTime)';
}
if ($qDateTo !== '') {
    $filterParts[] = 'FILTER(?date <= "' . $qDateTo . 'T23:59:59"^^xsd:dateTime)';
}
$filterStr = implode("\n        ", $filterParts);

// ─── Charger toutes les prescriptions ────────────────────────────────────
$query = sparqlPrefixes() . "
    SELECT ?prescription ?date ?patient ?nom ?prenom ?dossier
           ?praticien ?pratPrenom ?pratNom
           (COUNT(DISTINCT ?activite) AS ?nb_actes) WHERE {
        ?prescription a ex:Prescription .
        OPTIONAL { ?prescription ex:concerne ?patient .
                   OPTIONAL { ?patient ex:aPourNom ?nom }
                   OPTIONAL { ?patient ex:aPourPrenom ?prenom }
                   OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
        }
        OPTIONAL { ?prescription ex:aPourDate ?date }
        OPTIONAL { ?prescription ex:prescritPar ?praticien .
                   OPTIONAL { ?praticien ex:aPourPrenom ?pratPrenom }
                   OPTIONAL { ?praticien ex:aPourNom    ?pratNom }
        }
        OPTIONAL { ?prescription ex:contient ?activite }
        $filterStr
    }
    GROUP BY ?prescription ?date ?patient ?nom ?prenom ?dossier
             ?praticien ?pratPrenom ?pratNom
    ORDER BY DESC(?date)
";
$prescriptions = [];
foreach (sparqlPA($query) as $r) {
    $prescriptions[] = [
        'uri'      => $r['prescription']['value'] ?? '',
        'date'     => $r['date']['value']         ?? '',
        'patient_uri' => $r['patient']['value']   ?? '',
        'nom'      => $r['nom']['value']          ?? '',
        'prenom'   => $r['prenom']['value']       ?? '',
        'dossier'  => $r['dossier']['value']      ?? '',
        'praticien_uri'    => $r['praticien']['value']  ?? '',
        'praticien_prenom' => $r['pratPrenom']['value'] ?? '',
        'praticien_nom'    => $r['pratNom']['value']    ?? '',
        'nb_actes' => (int)($r['nb_actes']['value'] ?? 0),
    ];
}

// Stats simples
$totalCount = count($prescriptions);
$thisMonth  = 0;
$thisWeek   = 0;
$today      = 0;
$now        = new DateTime();
$startOfMonth = new DateTime('first day of this month');
$startOfWeek  = (new DateTime('monday this week'));
$startOfDay   = new DateTime('today');
foreach ($prescriptions as $p) {
    if (!$p['date']) continue;
    try {
        $d = new DateTime($p['date']);
        if ($d >= $startOfMonth) $thisMonth++;
        if ($d >= $startOfWeek)  $thisWeek++;
        if ($d >= $startOfDay)   $today++;
    } catch (\Exception $e) {}
}

// Liste des praticiens (pour le dropdown filtre)
$allPraticiens = [];
foreach (sparqlPA(sparqlPrefixes() . "
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
<title>Toutes les prescriptions · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5}
a{color:#2563eb;text-decoration:none}

/* Topbar admin */
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

/* Hero */
.hero{background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 50%,#2563eb 100%);
      color:#fff;padding:28px 32px;border-radius:18px;margin-bottom:22px;
      box-shadow:0 10px 30px rgba(29,78,216,.2);position:relative;overflow:hidden}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);
           border:1px solid rgba(255,255,255,.2);padding:4px 12px;border-radius:50px;
           font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px}
.hero h1{margin:0 0 6px;font-size:26px;font-weight:800;letter-spacing:-.02em}
.hero p{margin:0;font-size:14px;opacity:.85}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:760px){.stats-row{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:13px;padding:16px 20px;
           box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid}
.stat-num{font-size:26px;font-weight:800;letter-spacing:-.5px;line-height:1.1}
.stat-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}
.stat-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.sc-total{border-left-color:#1d4ed8} .sc-total .stat-num{color:#1d4ed8}
.sc-month{border-left-color:#7c3aed} .sc-month .stat-num{color:#7c3aed}
.sc-week{border-left-color:#0891b2}  .sc-week .stat-num{color:#0891b2}
.sc-today{border-left-color:#059669} .sc-today .stat-num{color:#059669}

/* Filtres */
.filters-card{background:#fff;border:1px solid #e5e7eb;border-radius:13px;padding:18px 22px;
              box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:20px}
.filters-form{display:grid;grid-template-columns:2fr 1.5fr 1fr 1fr auto;gap:12px;align-items:end}
@media(max-width:920px){.filters-form{grid-template-columns:1fr 1fr;gap:10px}}
.field label{display:block;font-size:11px;font-weight:700;color:#64748b;
              text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.field input, .field select{width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;
                              border-radius:9px;font-size:13px;font-family:inherit;background:#fff}
.field input:focus, .field select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.btn-filter{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;
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

.prescr-table{width:100%;border-collapse:collapse}
.prescr-table th{background:#fafbfc;padding:11px 14px;text-align:left;font-size:11px;
                  font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;
                  border-bottom:1px solid #e5e7eb}
.prescr-table td{padding:13px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
.prescr-table tbody tr{transition:.1s;cursor:pointer}
.prescr-table tbody tr:hover{background:#f8fafc}
.prescr-table tbody tr:last-child td{border-bottom:0}

.dot{width:7px;height:7px;border-radius:50%;background:#10b981;display:inline-block;margin-right:8px;vertical-align:middle}
.date-cell{font-weight:600;color:#0f172a;white-space:nowrap}
.patient-link{color:#1d4ed8;font-weight:700;text-decoration:none}
.patient-link:hover{text-decoration:underline}
.dossier-tag{font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8;
              border:1px solid #bfdbfe;padding:2px 8px;border-radius:5px;font-family:'Courier New',monospace}
.prat-tag{display:inline-flex;align-items:center;gap:6px;background:#f3e8ff;color:#5b21b6;
           border:1px solid #d8b4fe;padding:3px 10px;border-radius:50px;font-size:12px;font-weight:600}
.prat-tag-empty{background:#fef3c7;color:#92400e;border-color:#fcd34d;font-style:italic}
.acts-badge{background:#dcfce7;color:#065f46;border:1px solid #6ee7b7;
             font-size:11px;font-weight:700;padding:3px 9px;border-radius:50px}
.view-link{color:#1d4ed8;font-weight:600;font-size:12px;text-decoration:none}
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
            <a href="prescriptions_all.php" class="active">📋 Prescriptions</a>
            <a href="patients_all.php">👤 Patients</a>
            <a href="ontology.php">🩺 Ontologie</a>
            <a href="../welcome.php" class="home-link">🏠 Accueil</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <div class="hero">
        <div class="hero-tag">📋 Vue administrateur</div>
        <h1>Toutes les prescriptions du système</h1>
        <p>Consultation globale de toutes les prescriptions, tous praticiens confondus.</p>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card sc-total">
            <div class="stat-num"><?= $totalCount ?></div>
            <div class="stat-lbl">Total</div>
            <div class="stat-sub">depuis le début</div>
        </div>
        <div class="stat-card sc-month">
            <div class="stat-num"><?= $thisMonth ?></div>
            <div class="stat-lbl">Ce mois</div>
            <div class="stat-sub">depuis le 1er</div>
        </div>
        <div class="stat-card sc-week">
            <div class="stat-num"><?= $thisWeek ?></div>
            <div class="stat-lbl">Cette semaine</div>
            <div class="stat-sub">depuis lundi</div>
        </div>
        <div class="stat-card sc-today">
            <div class="stat-num"><?= $today ?></div>
            <div class="stat-lbl">Aujourd'hui</div>
            <div class="stat-sub"><?= date('d/m/Y') ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="filters-card">
        <form class="filters-form" method="get">
            <div class="field">
                <label>🔍 Rechercher un patient</label>
                <input type="text" name="q" value="<?= hPA($qPatient) ?>"
                       placeholder="Nom, prénom ou n° de dossier...">
            </div>
            <div class="field">
                <label>👤 Praticien</label>
                <select name="praticien">
                    <option value="">— Tous les praticiens —</option>
                    <?php foreach ($allPraticiens as $p): ?>
                        <option value="<?= hPA($p['uri']) ?>" <?= $qPraticien === $p['uri'] ? 'selected' : '' ?>>
                            <?= hPA($p['prenom'] . ' ' . $p['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>📅 Depuis le</label>
                <input type="date" name="from" value="<?= hPA($qDateFrom) ?>">
            </div>
            <div class="field">
                <label>📅 Jusqu'au</label>
                <input type="date" name="to" value="<?= hPA($qDateTo) ?>">
            </div>
            <div style="display:flex;gap:6px">
                <button type="submit" class="btn-filter">Filtrer</button>
                <?php if ($qPatient !== '' || $qPraticien !== '' || $qDateFrom !== '' || $qDateTo !== ''): ?>
                    <a href="prescriptions_all.php" class="btn-reset">↺</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Liste -->
    <div class="table-card">
        <div class="table-head">
            <span><strong><?= $totalCount ?></strong> prescription<?= $totalCount > 1 ? 's' : '' ?> affichée<?= $totalCount > 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($prescriptions)): ?>
            <div class="empty">
                <span class="empty-icon">📋</span>
                Aucune prescription trouvée avec ces critères.
            </div>
        <?php else: ?>
        <table class="prescr-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Patient</th>
                <th>Dossier</th>
                <th>Praticien</th>
                <th>Activités</th>
                <th style="text-align:right">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($prescriptions as $p):
                $prescFrag = localNamePA($p['uri']);
                $patientFrag = $p['patient_uri'] ? localNamePA($p['patient_uri']) : '';
                $dateF = '';
                if ($p['date']) {
                    try { $dateF = (new DateTime($p['date']))->format('d/m/Y à H:i'); }
                    catch (\Exception $e) {}
                }
                $patientName = trim($p['prenom'] . ' ' . $p['nom']);
                if ($patientName === '') $patientName = 'Patient inconnu';
                $pratName = trim($p['praticien_prenom'] . ' ' . $p['praticien_nom']);
            ?>
            <tr onclick="window.location='../prescription_detail.php?id=<?= urlencode($prescFrag) ?>&from_admin=1'">
                <td class="date-cell">
                    <span class="dot"></span><?= hPA($dateF) ?>
                </td>
                <td>
                    <?php if ($patientFrag !== ''): ?>
                        <a href="../patient_detail.php?id=<?= urlencode($patientFrag) ?>&from_admin=1"
                           class="patient-link" onclick="event.stopPropagation()">
                            <?= hPA($patientName) ?>
                        </a>
                    <?php else: ?>
                        <span><?= hPA($patientName) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['dossier']): ?>
                        <span class="dossier-tag"><?= hPA($p['dossier']) ?></span>
                    <?php else: ?>
                        <span style="color:#94a3b8">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($pratName !== ''): ?>
                        <span class="prat-tag">👤 <?= hPA($pratName) ?></span>
                    <?php else: ?>
                        <span class="prat-tag prat-tag-empty">Non spécifié</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="acts-badge"><?= $p['nb_actes'] ?> activité<?= $p['nb_actes'] > 1 ? 's' : '' ?></span>
                </td>
                <td style="text-align:right">
                    <a href="../prescription_detail.php?id=<?= urlencode($prescFrag) ?>&from_admin=1"
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
