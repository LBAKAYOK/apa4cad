<?php
/**
 * APA4CAD - Historique des prescriptions (refonte UX épurée)
 *
 * Navigation depuis topbar. Filtrage par patient et plage de dates.
 * Clic sur nom du patient → patient_detail.php
 * Clic sur "Voir" → prescription_detail.php
 */

declare(strict_types=1);

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/praticien_session.php';

// ─── Vérification : seul un praticien connecté peut voir ses prescriptions ──
if (!isPraticienLoggedIn()) {
    header('Location: login_praticien.php');
    exit;
}

$currentPraticienUri  = currentPraticienUri();
$currentPraticienName = currentPraticienName();
$currentPraticienEsc  = '<' . str_replace('>', '', $currentPraticienUri) . '>';

function sparqlQueryP(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    return $d['results']['bindings'] ?? [];
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function localName(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}
function formatDate(string $iso): string {
    if ($iso === '') return '—';
    try { return (new DateTime($iso))->format('d/m/Y à H:i'); }
    catch (Exception $e) { return $iso; }
}

// Filtres
$qPatient = trim($_GET['q'] ?? '');
$qDateFrom = trim($_GET['from'] ?? '');
$qDateTo = trim($_GET['to'] ?? '');

$filters = [];
if ($qPatient !== '') {
    $qEsc = sparqlEscapeString($qPatient);
    $filters[] = "FILTER(CONTAINS(LCASE(?nom), LCASE(\"$qEsc\")) || CONTAINS(LCASE(?prenom), LCASE(\"$qEsc\")) || CONTAINS(LCASE(?dossier), LCASE(\"$qEsc\")))";
}
if ($qDateFrom !== '') {
    $filters[] = "FILTER(?date >= \"" . sparqlEscapeString($qDateFrom) . "T00:00:00\"^^xsd:dateTime)";
}
if ($qDateTo !== '') {
    $filters[] = "FILTER(?date <= \"" . sparqlEscapeString($qDateTo) . "T23:59:59\"^^xsd:dateTime)";
}
$filterStr = implode("\n", $filters);

$query = sparqlPrefixes() . "
    SELECT ?prescription ?date ?patient ?nom ?prenom ?dossier
           (COUNT(DISTINCT ?activite) AS ?nb_actes) WHERE {
        ?prescription a ex:Prescription ; ex:concerne ?patient .
        ?prescription ex:prescritPar $currentPraticienEsc .
        OPTIONAL { ?prescription ex:aPourDate ?date }
        OPTIONAL { ?patient ex:aPourNom ?nom }
        OPTIONAL { ?patient ex:aPourPrenom ?prenom }
        OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
        OPTIONAL { ?prescription ex:contient ?activite }
        $filterStr
    }
    GROUP BY ?prescription ?date ?patient ?nom ?prenom ?dossier
    ORDER BY DESC(?date)
";

$prescriptions = [];
foreach (sparqlQueryP($query) as $r) {
    $prescriptions[] = [
        'fragment' => localName($r['prescription']['value']),
        'patient_uri' => $r['patient']['value'] ?? '',
        'date' => $r['date']['value'] ?? '',
        'nom' => $r['nom']['value'] ?? '',
        'prenom' => $r['prenom']['value'] ?? '',
        'dossier' => $r['dossier']['value'] ?? '',
        'nb_actes' => (int)($r['nb_actes']['value'] ?? 0),
    ];
}

// ── Stats pour le tableau de bord en haut ─────────────────────────────────
// On charge en plus une requête (filtrée par praticien) pour les stats de l'entête.
$statsBindings = sparqlQueryP(sparqlPrefixes() . "
    SELECT ?date WHERE {
        ?p a ex:Prescription ; ex:prescritPar $currentPraticienEsc .
        OPTIONAL { ?p ex:aPourDate ?date }
    }
");
$now    = new DateTime('now');
$today  = (clone $now)->setTime(0, 0, 0);
$weekAgo = (clone $today)->modify('-7 days');
$monthAgo = (clone $today)->modify('-30 days');
$stats = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
foreach ($statsBindings as $r) {
    $stats['total']++;
    $iso = $r['date']['value'] ?? '';
    if ($iso === '') continue;
    try {
        $d = new DateTime($iso);
        if ($d >= $today)    $stats['today']++;
        if ($d >= $weekAgo)  $stats['week']++;
        if ($d >= $monthAgo) $stats['month']++;
    } catch (Exception $e) { /* ignore */ }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Mes prescriptions · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}
button{font-family:inherit;cursor:pointer}

.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 0}
.topbar-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:32px}
.topbar-brand{font-weight:700;font-size:17px;color:#1d4ed8;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#1d4ed8;border-radius:2px;display:inline-block}
.topbar-nav{display:flex;gap:6px;margin-left:auto}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#475569;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:#f1f5f9;color:#1e293b}
.topbar-nav a.active{background:#eff6ff;color:#1d4ed8;font-weight:600}

.app{max-width:1200px;margin:0 auto;padding:32px 24px 80px}

.banner{background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;
        border-radius:18px;padding:30px 34px;margin-bottom:28px;
        box-shadow:0 14px 28px rgba(37,99,235,.18)}
.banner .crumbs{font-size:12px;opacity:.85;margin-bottom:8px}
.banner .my-badge{display:inline-flex;align-items:center;gap:6px;
                   background:rgba(255,255,255,.18);backdrop-filter:blur(4px);
                   border:1px solid rgba(255,255,255,.25);
                   padding:5px 12px;border-radius:50px;font-size:11px;font-weight:700;
                   letter-spacing:.4px;text-transform:uppercase;opacity:1}
.banner h1{margin:0;font-size:28px;font-weight:700;letter-spacing:-0.02em}
.banner .subtitle{margin-top:8px;opacity:.92;font-size:14px}

.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
      padding:24px 26px;box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:18px}

.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
@media(max-width:800px){.filters{grid-template-columns:1fr}}
.filters .field label{display:block;font-size:11px;color:#6b7280;
                       margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.filters .field input{width:100%;padding:10px 12px;border:1px solid #e5e7eb;
                       border-radius:9px;font-size:14px;font-family:inherit;background:#fff}
.filters .field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.btn-filter{background:#2563eb;color:#fff;border:none;border-radius:9px;
             padding:11px 18px;font-weight:600;font-size:13px;transition:.15s}
.btn-filter:hover{background:#1d4ed8}

.results-count{font-size:13px;color:#6b7280;margin:18px 0 14px;padding:0 4px}
.results-count strong{color:#1e293b;font-weight:700}

table{width:100%;border-collapse:collapse;border-spacing:0}
th{text-align:left;padding:12px 14px;font-size:11px;font-weight:700;
   color:#6b7280;text-transform:uppercase;letter-spacing:.5px;
   border-bottom:1px solid #e5e7eb;background:#fafbfc}
td{padding:14px;border-bottom:1px solid #f1f5f9;font-size:14px;vertical-align:middle}
tr:hover td{background:#fafbfc}
.col-date{color:#1e293b;font-weight:500}
.col-patient a{color:#2563eb;font-weight:600;border-bottom:1px dotted #93c5fd}
.col-patient a:hover{color:#1d4ed8}
.col-dossier{font-family:ui-monospace,monospace;background:#f1f5f9;
              padding:3px 8px;border-radius:5px;font-size:12px;color:#374151;display:inline-block}
.col-acts{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;
           border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;display:inline-block}
.btn-view{color:#2563eb;font-weight:600;padding:6px 12px;border-radius:7px;
           font-size:13px;text-decoration:none}
.btn-view:hover{background:#eff6ff}

.empty{padding:40px 16px;text-align:center;color:#9ca3af;font-size:13px;font-style:italic}

/* ═══════════════════════════════════════════════════════════════════════
   REFONTE : stats + table dynamique
   ═══════════════════════════════════════════════════════════════════════ */

/* Stats : Total · Mois · Semaine · Aujourd'hui */
.h-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
@media(max-width:800px){.h-stats{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.h-stats{grid-template-columns:1fr}}
.h-stat{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px 22px;
        box-shadow:0 1px 3px rgba(15,23,42,.04);display:flex;flex-direction:column;gap:6px;
        border-left:4px solid;transition:.2s}
.h-stat:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08)}
.h-stat-num{font-size:30px;font-weight:800;line-height:1;letter-spacing:-.5px}
.h-stat-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.h-stat-sub{font-size:11px;color:#94a3b8}
.h-stat-total{border-left-color:#1d4ed8} .h-stat-total .h-stat-num{color:#1d4ed8}
.h-stat-month{border-left-color:#7c3aed} .h-stat-month .h-stat-num{color:#7c3aed}
.h-stat-week {border-left-color:#0891b2} .h-stat-week  .h-stat-num{color:#0891b2}
.h-stat-today{border-left-color:#059669} .h-stat-today .h-stat-num{color:#059669}

/* Container de la table : permet les hover surélévés */
.table-card{padding:0;overflow:visible}
.table-wrap{padding:0 8px}

/* Table moderne : pas de bordures fixes, lignes flottantes */
table{width:100%;border-collapse:separate;border-spacing:0 6px;margin:6px 0}
thead th{padding:10px 16px;font-size:11px;font-weight:700;
         color:#64748b;text-transform:uppercase;letter-spacing:.5px;
         background:transparent;border:none;user-select:none}
thead th.sortable{cursor:pointer;transition:.15s}
thead th.sortable:hover{color:#1d4ed8}
.sort-arrow{display:inline-block;width:10px;font-size:9px;color:#cbd5e1;margin-left:4px;vertical-align:middle}
thead th.sort-asc  .sort-arrow{color:#1d4ed8} thead th.sort-asc  .sort-arrow::before{content:"▲"}
thead th.sort-desc .sort-arrow{color:#1d4ed8} thead th.sort-desc .sort-arrow::before{content:"▼"}

tbody tr{background:#fff;transition:.15s;border-radius:10px}
tbody td{padding:14px 16px;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;
         vertical-align:middle;background:#fff}
tbody td:first-child{border-left:1px solid #f1f5f9;border-top-left-radius:10px;border-bottom-left-radius:10px}
tbody td:last-child{border-right:1px solid #f1f5f9;border-top-right-radius:10px;border-bottom-right-radius:10px}
tbody tr:hover td{background:#fafbff;border-color:#e0e7ff}
tbody tr:hover{box-shadow:0 4px 12px rgba(37,99,235,.08);transform:translateY(-1px)}

/* Indicateur de fraîcheur */
.freshness-dot{display:inline-block;width:8px;height:8px;border-radius:50%;
                margin-right:8px;vertical-align:middle}
.fresh-today{background:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.fresh-week {background:#3b82f6}
.fresh-month{background:#a78bfa}
.fresh-old  {background:#cbd5e1}

.btn-print-sm{background:none;border:1px solid #e5e7eb;color:#64748b;border-radius:7px;
              padding:5px 9px;font-size:12px;margin-right:6px;transition:.15s;cursor:pointer;
              font-family:inherit}
.btn-print-sm:hover{background:#f1f5f9;color:#1e293b}

/* Empty state visuel */
.empty-rich{padding:60px 30px;text-align:center;color:#94a3b8}
.empty-rich-icon{font-size:48px;line-height:1;opacity:.4;margin-bottom:14px}
.empty-rich-title{font-size:15px;font-weight:700;color:#475569;margin-bottom:6px}
.empty-rich-sub{font-size:13px;color:#94a3b8}

/* Loader skeleton (recherche en cours) */
.search-loading{display:none;font-size:12px;color:#94a3b8;font-style:italic;padding:8px 16px}

/* Pagination "Voir plus" */
.pagination-more{padding:18px;text-align:center;border-top:1px solid #f1f5f9}
.btn-more{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:9px;
          padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s}
.btn-more:hover{background:#dbeafe}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <nav class="topbar-nav">
            <a href="index.php">Nouvelle prescription</a>
            <a href="patient.php">Patients</a>
            <a href="prescriptions.php" class="active">Mes prescriptions</a>
        </nav>
    </div>
</div>

<div class="app">

    <section class="banner">
        <div class="crumbs">
            <span class="my-badge">👤 Mes prescriptions</span>
        </div>
        <h1>📋 Mes prescriptions</h1>
        <div class="subtitle">
            Vos prescriptions personnelles, signées en tant que
            <strong style="color:#fff"><?= h($currentPraticienName) ?></strong>.
        </div>
    </section>

    <!-- ═══════ Stats : Total · Mois · Semaine · Aujourd'hui ═══════ -->
    <div class="h-stats">
        <div class="h-stat h-stat-total">
            <div class="h-stat-num"><?= $stats['total'] ?></div>
            <div class="h-stat-lbl">total</div>
            <div class="h-stat-sub">depuis le début</div>
        </div>
        <div class="h-stat h-stat-month">
            <div class="h-stat-num"><?= $stats['month'] ?></div>
            <div class="h-stat-lbl">ce mois</div>
            <div class="h-stat-sub">30 derniers jours</div>
        </div>
        <div class="h-stat h-stat-week">
            <div class="h-stat-num"><?= $stats['week'] ?></div>
            <div class="h-stat-lbl">cette semaine</div>
            <div class="h-stat-sub">7 derniers jours</div>
        </div>
        <div class="h-stat h-stat-today">
            <div class="h-stat-num"><?= $stats['today'] ?></div>
            <div class="h-stat-lbl">aujourd'hui</div>
            <div class="h-stat-sub"><?= h((new DateTime())->format('d/m/Y')) ?></div>
        </div>
    </div>

    <div class="card">
        <form method="get" class="filters" id="filters-form">
            <div class="field">
                <label>Rechercher un patient</label>
                <input type="text" name="q" id="search-input" autocomplete="off"
                       placeholder="Nom, prénom ou n° de dossier..." value="<?= h($qPatient) ?>">
            </div>
            <div class="field">
                <label>Depuis le</label>
                <input type="date" name="from" id="date-from" value="<?= h($qDateFrom) ?>">
            </div>
            <div class="field">
                <label>Jusqu'au</label>
                <input type="date" name="to" id="date-to" value="<?= h($qDateTo) ?>">
            </div>
            <button type="submit" class="btn-filter">Filtrer</button>
        </form>
        <div class="search-loading" id="search-loading">🔄 Filtrage en cours…</div>
    </div>

    <div class="results-count">
        <strong id="visible-count"><?= count($prescriptions) ?></strong> prescription<?= count($prescriptions) > 1 ? 's' : '' ?> affichée<?= count($prescriptions) > 1 ? 's' : '' ?>
        <?php if ($qPatient !== '' || $qDateFrom !== '' || $qDateTo !== ''): ?>
            · <a href="prescriptions.php" style="color:#6b7280">✕ Réinitialiser les filtres</a>
        <?php endif; ?>
    </div>

    <div class="card table-card">
        <?php if (empty($prescriptions)): ?>
            <div class="empty-rich">
                <div class="empty-rich-icon">📋</div>
                <div class="empty-rich-title">
                    <?= ($qPatient !== '' || $qDateFrom !== '' || $qDateTo !== '')
                        ? 'Aucune prescription trouvée'
                        : 'Vous n\'avez encore aucune prescription' ?>
                </div>
                <div class="empty-rich-sub">
                    <?= ($qPatient !== '' || $qDateFrom !== '' || $qDateTo !== '')
                        ? 'Essayez d\'ajuster vos filtres ou réinitialisez-les.'
                        : 'Vos prescriptions personnelles apparaîtront ici dès que vous en aurez enregistré.' ?>
                </div>
                <?php if ($qPatient === '' && $qDateFrom === '' && $qDateTo === ''): ?>
                    <a href="index.php?from_welcome=1" style="display:inline-block;margin-top:14px;
                        background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;
                        padding:10px 20px;border-radius:9px;font-weight:700;font-size:13px;
                        text-decoration:none;box-shadow:0 4px 12px rgba(29,78,216,.3)">
                        ➕ Créer ma première prescription
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table id="prescriptions-table">
                    <thead>
                        <tr>
                            <th class="sortable sort-desc" data-sort="date">
                                Date <span class="sort-arrow"></span>
                            </th>
                            <th class="sortable" data-sort="patient">
                                Patient <span class="sort-arrow"></span>
                            </th>
                            <th>Dossier</th>
                            <th class="sortable" data-sort="acts">
                                Activités <span class="sort-arrow"></span>
                            </th>
                            <th style="text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="prescriptions-tbody">
                        <?php
                        $nowTs = time();
                        foreach ($prescriptions as $p):
                            $patientName = trim($p['prenom'] . ' ' . $p['nom']) ?: '(patient anonyme)';
                            $patientFrag = localName($p['patient_uri']);
                            // Calcul de la fraîcheur pour la pastille
                            $freshClass = 'fresh-old';
                            $ageDays = 999;
                            if ($p['date'] !== '') {
                                try {
                                    $d = new DateTime($p['date']);
                                    $ageDays = (int)floor(($nowTs - $d->getTimestamp()) / 86400);
                                    if ($ageDays <= 0)       $freshClass = 'fresh-today';
                                    elseif ($ageDays <= 7)   $freshClass = 'fresh-week';
                                    elseif ($ageDays <= 30)  $freshClass = 'fresh-month';
                                } catch (Exception $e) {}
                            }
                            // Champ recherche : nom + prénom + dossier (lowercase)
                            $haystack = strtolower(trim($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['dossier']));
                        ?>
                            <tr data-search="<?= h($haystack) ?>"
                                data-date="<?= h($p['date']) ?>"
                                data-patient="<?= h(strtolower($patientName)) ?>"
                                data-acts="<?= $p['nb_actes'] ?>">
                                <td class="col-date">
                                    <span class="freshness-dot <?= $freshClass ?>"
                                          title="<?= $ageDays <= 0 ? 'Aujourd\'hui' : 'Il y a ' . $ageDays . ' jour' . ($ageDays > 1 ? 's' : '') ?>"></span>
                                    <?= h(formatDate($p['date'])) ?>
                                </td>
                                <td class="col-patient">
                                    <?php if ($patientFrag !== ''): ?>
                                        <a href="patient_detail.php?id=<?= urlencode($patientFrag) ?>"
                                           title="Ouvrir le dossier du patient"><?= h($patientName) ?></a>
                                    <?php else: ?>
                                        <?= h($patientName) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['dossier'] !== ''): ?>
                                        <span class="col-dossier"><?= h($p['dossier']) ?></span>
                                    <?php else: echo '—'; endif; ?>
                                </td>
                                <td>
                                    <span class="col-acts"><?= $p['nb_actes'] ?> activité<?= $p['nb_actes'] > 1 ? 's' : '' ?></span>
                                </td>
                                <td style="text-align:right">
                                    <a href="prescription_detail.php?id=<?= urlencode($p['fragment']) ?>"
                                       target="_blank" class="btn-print-sm" title="Imprimer la prescription"
                                       onclick="event.stopPropagation()">🖨</a>
                                    <a href="prescription_detail.php?id=<?= urlencode($p['fragment']) ?>" class="btn-view">
                                        Voir →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Empty state masqué par défaut (apparaît si recherche JS ne trouve rien) -->
                <div class="empty-rich" id="js-empty-state" style="display:none">
                    <div class="empty-rich-icon">🔎</div>
                    <div class="empty-rich-title">Aucun résultat</div>
                    <div class="empty-rich-sub">Aucune prescription ne correspond à votre recherche.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// ═══════════════════════════════════════════════════════════════════════
//  Recherche temps réel + tri cliquable
// ═══════════════════════════════════════════════════════════════════════
const searchInput = document.getElementById('search-input');
const tbody       = document.getElementById('prescriptions-tbody');
const visibleCount = document.getElementById('visible-count');
const jsEmpty     = document.getElementById('js-empty-state');
const tableEl     = document.getElementById('prescriptions-table');

if (tbody) {
    const allRows = Array.from(tbody.querySelectorAll('tr'));

    // ── Recherche temps réel (sans rechargement) ─────────────────────
    function applySearch() {
        const term = (searchInput?.value || '').trim().toLowerCase();
        let nbVisible = 0;
        allRows.forEach(row => {
            const hay = row.dataset.search || '';
            const match = term === '' || hay.includes(term);
            row.style.display = match ? '' : 'none';
            if (match) nbVisible++;
        });
        if (visibleCount) visibleCount.textContent = nbVisible;
        if (jsEmpty) jsEmpty.style.display = (nbVisible === 0 && allRows.length > 0) ? 'block' : 'none';
        if (tableEl) tableEl.style.display = (nbVisible === 0 && allRows.length > 0) ? 'none' : '';
    }

    searchInput?.addEventListener('input', applySearch);

    // ── Tri cliquable sur colonnes ───────────────────────────────────
    const sortableHeaders = tableEl?.querySelectorAll('thead th.sortable') || [];
    let currentSort = { col: 'date', dir: 'desc' }; // état initial (cohérent avec le SPARQL ORDER BY)

    function applySort(col, dir) {
        const sorted = allRows.slice().sort((a, b) => {
            let va, vb;
            if (col === 'acts') {
                va = parseInt(a.dataset.acts, 10) || 0;
                vb = parseInt(b.dataset.acts, 10) || 0;
            } else if (col === 'date') {
                va = a.dataset.date || '';
                vb = b.dataset.date || '';
            } else { // patient
                va = a.dataset.patient || '';
                vb = b.dataset.patient || '';
            }
            if (va < vb) return dir === 'asc' ? -1 :  1;
            if (va > vb) return dir === 'asc' ?  1 : -1;
            return 0;
        });
        // Réinsérer dans le bon ordre
        sorted.forEach(row => tbody.appendChild(row));
        // Mettre à jour les indicateurs visuels
        sortableHeaders.forEach(h => {
            h.classList.remove('sort-asc', 'sort-desc');
            if (h.dataset.sort === col) h.classList.add('sort-' + dir);
        });
        currentSort = { col, dir };
    }

    sortableHeaders.forEach(h => {
        h.addEventListener('click', () => {
            const col = h.dataset.sort;
            const dir = (currentSort.col === col && currentSort.dir === 'desc') ? 'asc' : 'desc';
            applySort(col, dir);
        });
    });
}

// ── Date filters : auto-submit instantané (avec petit debounce) ──────
let dateTimer = null;
['date-from','date-to'].forEach(id => {
    const el = document.getElementById(id);
    el?.addEventListener('change', () => {
        clearTimeout(dateTimer);
        const loading = document.getElementById('search-loading');
        if (loading) loading.style.display = 'block';
        dateTimer = setTimeout(() => document.getElementById('filters-form').submit(), 400);
    });
});
</script>

</body>
</html>
