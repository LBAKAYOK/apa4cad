<?php
/**
 * APA4CAD - Statistiques avancées (Admin)
 *
 * 7 sections :
 *   1. Activité dans le temps (graphique avec filtre période)
 *   2. Répartition des pathologies (camembert + tableau)
 *   3. Top 10 activités prescrites (barres horizontales)
 *   4. Freins et leviers (catégories + tops)
 *   5. Démographie patients (pyramide âges + répartition sexe)
 *   6. Indicateurs qualité (moyennes, taux de complétude)
 *   7. Exports (CSV / XLS / impression PDF)
 *
 * Tous les graphiques sont en SVG natif (zéro dépendance externe).
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

// ─────────────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────────────
function sparqlS(string $query): array {
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
function hS(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function localNameS(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}
function prettyS(string $s): string {
    $s = str_replace('_', ' ', $s);
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', $s));
}

// ─────────────────────────────────────────────────────────────────────────
//  Filtres de période
// ─────────────────────────────────────────────────────────────────────────
$preset = $_GET['preset'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$today = new DateTimeImmutable('today');

// Appliquer les presets
if ($preset === '7d') {
    $dateFrom = $today->modify('-7 days')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} elseif ($preset === '30d') {
    $dateFrom = $today->modify('-30 days')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} elseif ($preset === '3m') {
    $dateFrom = $today->modify('-3 months')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} elseif ($preset === '6m') {
    $dateFrom = $today->modify('-6 months')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} elseif ($preset === '1y') {
    $dateFrom = $today->modify('-1 year')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} elseif ($preset === 'all') {
    $dateFrom = '';
    $dateTo   = '';
}

// Construction du filtre SPARQL
$dateFilter = '';
if ($dateFrom !== '') {
    $dateFilter .= "FILTER(?date >= \"" . $dateFrom . "T00:00:00\"^^xsd:dateTime) ";
}
if ($dateTo !== '') {
    $dateFilter .= "FILTER(?date <= \"" . $dateTo . "T23:59:59\"^^xsd:dateTime) ";
}

// Label de la période active
$periodLabel = ($dateFrom === '' && $dateTo === '')
    ? 'Toute la période'
    : ($dateFrom !== '' ? 'Du ' . $dateFrom : '') .
      ($dateTo !== '' ? ' au ' . $dateTo : '');

// ─────────────────────────────────────────────────────────────────────────
//  Export CSV / XLS (avant tout HTML !)
// ─────────────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];

    $query = sparqlPrefixes() . "
        SELECT ?presc ?date ?prenom ?nom ?dossier
               (GROUP_CONCAT(DISTINCT ?actClass; separator=', ') AS ?activites) WHERE {
            ?presc a ex:Prescription ; ex:concerne ?patient ; ex:aPourDate ?date .
            $dateFilter
            OPTIONAL { ?patient ex:aPourPrenom ?prenom }
            OPTIONAL { ?patient ex:aPourNom ?nom }
            OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
            OPTIONAL {
                ?presc ex:contient ?act .
                ?act a ?actClass .
                FILTER(?actClass != owl:NamedIndividual)
                FILTER(STRSTARTS(STR(?actClass), '" . ONTO_NAMESPACE . "'))
            }
        }
        GROUP BY ?presc ?date ?prenom ?nom ?dossier
        ORDER BY DESC(?date)
    ";
    $rows = sparqlS($query);

    if ($type === 'csv') {
        $filename = 'apa4cad_prescriptions_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Date', 'Prénom', 'Nom', 'Dossier', 'Nb activités', 'Activités'], ';');
        foreach ($rows as $r) {
            $acts = $r['activites']['value'] ?? '';
            $actsPretty = '';
            if ($acts !== '') {
                $actsList = array_map(fn($u) => prettyS(localNameS(trim($u))), explode(',', $acts));
                $actsPretty = implode(' | ', $actsList);
                $nbActs = count($actsList);
            } else {
                $nbActs = 0;
            }
            fputcsv($fp, [
                $r['date']['value']    ?? '',
                $r['prenom']['value']  ?? '',
                $r['nom']['value']     ?? '',
                $r['dossier']['value'] ?? '',
                $nbActs,
                $actsPretty,
            ], ';');
        }
        fclose($fp);
        exit;
    }

    if ($type === 'xls') {
        $filename = 'apa4cad_prescriptions_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        echo "<table border='1'><thead><tr>";
        foreach (['Date', 'Prénom', 'Nom', 'Dossier', 'Nb activités', 'Activités'] as $h) {
            echo "<th>" . hS($h) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) {
            $acts = $r['activites']['value'] ?? '';
            $actsList = $acts !== '' ? array_map(fn($u) => prettyS(localNameS(trim($u))), explode(',', $acts)) : [];
            echo "<tr>";
            echo "<td>" . hS($r['date']['value'] ?? '') . "</td>";
            echo "<td>" . hS($r['prenom']['value'] ?? '') . "</td>";
            echo "<td>" . hS($r['nom']['value'] ?? '') . "</td>";
            echo "<td>" . hS($r['dossier']['value'] ?? '') . "</td>";
            echo "<td>" . count($actsList) . "</td>";
            echo "<td>" . hS(implode(' | ', $actsList)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        exit;
    }
}

// ═════════════════════════════════════════════════════════════════════════
//  CHARGEMENT DES DONNÉES STATISTIQUES
// ═════════════════════════════════════════════════════════════════════════

// 1) Nombre total de prescriptions sur la période
$r = sparqlS(sparqlPrefixes() . "
    SELECT (COUNT(DISTINCT ?p) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:aPourDate ?date .
        $dateFilter
    }
");
$totalPrescriptions = (int)($r[0]['n']['value'] ?? 0);

// 2) Nombre de patients distincts
$r = sparqlS(sparqlPrefixes() . "
    SELECT (COUNT(DISTINCT ?pat) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
    }
");
$totalPatientsActifs = (int)($r[0]['n']['value'] ?? 0);

// 3) Activité par jour (pour graphique en barres)
$activityDaily = []; // 'Y-m-d' => count
$r = sparqlS(sparqlPrefixes() . "
    SELECT ?date WHERE {
        ?p a ex:Prescription ; ex:aPourDate ?date .
        $dateFilter
    }
");
foreach ($r as $row) {
    try {
        $d = new DateTimeImmutable($row['date']['value'] ?? '');
        $k = $d->format('Y-m-d');
        $activityDaily[$k] = ($activityDaily[$k] ?? 0) + 1;
    } catch (Exception $e) {}
}
ksort($activityDaily);

// Construire la série complète (avec zéros pour les jours sans activité) si période bornée
if ($dateFrom !== '' && $dateTo !== '') {
    $cursor = new DateTimeImmutable($dateFrom);
    $end    = new DateTimeImmutable($dateTo);
    $full = [];
    while ($cursor <= $end) {
        $k = $cursor->format('Y-m-d');
        $full[$k] = $activityDaily[$k] ?? 0;
        $cursor = $cursor->modify('+1 day');
    }
    $activityDaily = $full;
}

// 4) Top pathologies prescrites (via les patients liés aux prescriptions)
$topPathologies = [];
$r = sparqlS(sparqlPrefixes() . "
    SELECT ?patho (COUNT(DISTINCT ?p) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
        { ?pat ex:aPourPathologie ?patho } UNION { ?pat ex:aPourPathologieArchivee ?patho }
        FILTER(STRSTARTS(STR(?patho), '" . ONTO_NAMESPACE . "'))
    }
    GROUP BY ?patho
    ORDER BY DESC(?n)
");
$totalPathoOccurrences = 0;
foreach ($r as $row) {
    $cnt = (int)($row['n']['value'] ?? 0);
    $totalPathoOccurrences += $cnt;
    $topPathologies[] = [
        'label' => prettyS(localNameS($row['patho']['value'] ?? '')),
        'count' => $cnt,
    ];
}

// 5) Top 10 activités prescrites
$topActivites = [];
$r = sparqlS(sparqlPrefixes() . "
    SELECT ?actClass (COUNT(DISTINCT ?derived) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:contient ?derived ; ex:aPourDate ?date .
        $dateFilter
        ?derived a ?actClass .
        FILTER(?actClass != owl:NamedIndividual)
        FILTER(STRSTARTS(STR(?actClass), '" . ONTO_NAMESPACE . "'))
    }
    GROUP BY ?actClass
    ORDER BY DESC(?n)
    LIMIT 10
");
foreach ($r as $row) {
    $topActivites[] = [
        'label' => prettyS(localNameS($row['actClass']['value'] ?? '')),
        'count' => (int)($row['n']['value'] ?? 0),
    ];
}

// 6) Top freins identifiés (sur les patients liés aux prescriptions de la période)
$topFreins = [];
$r = sparqlS(sparqlPrefixes() . "
    SELECT ?frein (COUNT(DISTINCT ?pat) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
        ?pat ex:aPourFrein ?frein .
        FILTER(STRSTARTS(STR(?frein), '" . ONTO_NAMESPACE . "'))
    }
    GROUP BY ?frein
    ORDER BY DESC(?n)
    LIMIT 10
");
foreach ($r as $row) {
    $topFreins[] = [
        'label' => prettyS(localNameS($row['frein']['value'] ?? '')),
        'count' => (int)($row['n']['value'] ?? 0),
    ];
}

// 7) Top leviers utilisés
$topLeviers = [];
$r = sparqlS(sparqlPrefixes() . "
    SELECT ?levier (COUNT(DISTINCT ?pat) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
        ?pat ex:aPourLevier ?levier .
        FILTER(STRSTARTS(STR(?levier), '" . ONTO_NAMESPACE . "'))
    }
    GROUP BY ?levier
    ORDER BY DESC(?n)
    LIMIT 10
");
foreach ($r as $row) {
    $topLeviers[] = [
        'label' => prettyS(localNameS($row['levier']['value'] ?? '')),
        'count' => (int)($row['n']['value'] ?? 0),
    ];
}

// 8) Démographie : tranches d'âge et sexe des patients ayant eu au moins une prescription
//    sur la période (ou tous patients si pas de filtre)
$ageBuckets = [
    '0-17'  => ['count' => 0, 'h' => 0, 'f' => 0],
    '18-30' => ['count' => 0, 'h' => 0, 'f' => 0],
    '31-45' => ['count' => 0, 'h' => 0, 'f' => 0],
    '46-60' => ['count' => 0, 'h' => 0, 'f' => 0],
    '61-75' => ['count' => 0, 'h' => 0, 'f' => 0],
    '76+'   => ['count' => 0, 'h' => 0, 'f' => 0],
];
$totalH = 0; $totalF = 0; $totalDemo = 0;

$r = sparqlS(sparqlPrefixes() . "
    SELECT DISTINCT ?pat ?age ?genreLabel WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
        OPTIONAL { ?pat ex:aPourAge ?age }
        OPTIONAL { ?pat ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), '#') AS ?genreLabel) }
    }
");
foreach ($r as $row) {
    $age = (int)($row['age']['value'] ?? 0);
    $genre = strtolower($row['genreLabel']['value'] ?? '');
    $totalDemo++;
    if ($genre === 'masculin') $totalH++;
    elseif ($genre === 'feminin' || $genre === 'féminin') $totalF++;

    $bucket = null;
    if ($age > 0 && $age <= 17)      $bucket = '0-17';
    elseif ($age >= 18 && $age <= 30) $bucket = '18-30';
    elseif ($age >= 31 && $age <= 45) $bucket = '31-45';
    elseif ($age >= 46 && $age <= 60) $bucket = '46-60';
    elseif ($age >= 61 && $age <= 75) $bucket = '61-75';
    elseif ($age >= 76)               $bucket = '76+';

    if ($bucket !== null) {
        $ageBuckets[$bucket]['count']++;
        if ($genre === 'masculin') $ageBuckets[$bucket]['h']++;
        elseif ($genre === 'feminin' || $genre === 'féminin') $ageBuckets[$bucket]['f']++;
    }
}

// 9) Indicateurs qualité
//    - Moyenne pathologies / patient
//    - Moyenne activités / prescription
//    - % prescriptions avec freins
//    - % prescriptions avec résumé IA (rdfs:comment non-CI/non-frein)

// Moyenne pathologies / patient (sur ceux ayant une prescription en période)
$avgPathosPerPatient = 0;
if ($totalPatientsActifs > 0) {
    $r = sparqlS(sparqlPrefixes() . "
        SELECT (COUNT(?link) AS ?n) WHERE {
            ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
            $dateFilter
            { ?pat ex:aPourPathologie ?link } UNION { ?pat ex:aPourPathologieArchivee ?link }
        }
    ");
    $totalLinks = (int)($r[0]['n']['value'] ?? 0);
    $avgPathosPerPatient = round($totalLinks / max(1, $totalPatientsActifs), 2);
}

// Moyenne activités / prescription
$avgActsPerPresc = 0;
if ($totalPrescriptions > 0) {
    $r = sparqlS(sparqlPrefixes() . "
        SELECT (COUNT(?act) AS ?n) WHERE {
            ?p a ex:Prescription ; ex:contient ?act ; ex:aPourDate ?date .
            $dateFilter
        }
    ");
    $totalActsCount = (int)($r[0]['n']['value'] ?? 0);
    $avgActsPerPresc = round($totalActsCount / max(1, $totalPrescriptions), 2);
}

// % prescriptions avec freins (via le patient lié)
$r = sparqlS(sparqlPrefixes() . "
    SELECT (COUNT(DISTINCT ?p) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:concerne ?pat ; ex:aPourDate ?date .
        $dateFilter
        ?pat ex:aPourFrein ?f .
    }
");
$prescWithFreins = (int)($r[0]['n']['value'] ?? 0);
$pctWithFreins = $totalPrescriptions > 0 ? round($prescWithFreins / $totalPrescriptions * 100) : 0;

// % prescriptions avec résumé IA (rdfs:comment sans préfixe [CI] / [FREIN] / [LEVIER])
$r = sparqlS(sparqlPrefixes() . "
    SELECT (COUNT(DISTINCT ?p) AS ?n) WHERE {
        ?p a ex:Prescription ; ex:aPourDate ?date ; rdfs:comment ?c .
        $dateFilter
        FILTER(!STRSTARTS(STR(?c), '[CI]'))
        FILTER(!STRSTARTS(STR(?c), '[FREIN]'))
        FILTER(!STRSTARTS(STR(?c), '[LEVIER]'))
    }
");
$prescWithSummary = (int)($r[0]['n']['value'] ?? 0);
$pctWithSummary = $totalPrescriptions > 0 ? round($prescWithSummary / $totalPrescriptions * 100) : 0;

// Calculs auxiliaires pour les graphiques
$maxActivityDaily = !empty($activityDaily) ? max($activityDaily) : 1;
$avgActivityDaily = !empty($activityDaily) ? round(array_sum($activityDaily) / count($activityDaily), 1) : 0;

// Couleurs pour camembert (palette professionnelle)
$pieColors = ['#1d4ed8', '#7c3aed', '#0891b2', '#059669', '#dc2626', '#f59e0b', '#db2777', '#0ea5e9', '#84cc16', '#a855f7'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Statistiques avancées · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}

/* Topbar admin sombre */
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

.app{max-width:1400px;margin:0 auto;padding:28px}

.dash-header{margin-bottom:18px}
.dash-header h1{margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a}
.dash-header p{margin:0;color:#64748b;font-size:13px}

/* Filtre période */
.period-bar{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px 22px;
            margin-bottom:18px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.period-bar-title{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;
                  letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}
.period-active{color:#1d4ed8;background:#eff6ff;padding:3px 10px;border-radius:10px;font-weight:600;
                text-transform:none;letter-spacing:0;font-size:11px}
.period-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.period-form .field{display:flex;flex-direction:column;gap:4px}
.period-form .field label{font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.period-form .field input[type="date"]{padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;
                                         font-size:13px;font-family:inherit}
.btn-filter{background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:9px 18px;
            font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-filter:hover{background:#1e40af}
.presets{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
.preset-btn{background:#f1f5f9;color:#475569;border:1px solid #e5e7eb;border-radius:7px;
            padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;
            text-decoration:none}
.preset-btn:hover{background:#e0e7ff;color:#1d4ed8}
.preset-btn.active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}

/* Navigation interne (ancres) */
.section-nav{position:sticky;top:0;z-index:50;background:#f4f7fb;padding:10px 0;
              margin:-28px -28px 18px;padding-left:28px;padding-right:28px;
              border-bottom:1px solid #e5e7eb;overflow-x:auto;white-space:nowrap}
.section-nav-inner{display:flex;gap:6px;align-items:center;min-width:max-content}
.section-nav a{padding:7px 12px;border-radius:7px;color:#475569;font-weight:600;font-size:12px;transition:.15s}
.section-nav a:hover{background:#e0e7ff;color:#1d4ed8}

/* Cards */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:22px 24px;
      box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:18px}
.card-title{font-size:14px;font-weight:800;color:#0f172a;text-transform:uppercase;
             letter-spacing:.5px;margin:0 0 16px;display:flex;align-items:center;gap:10px;padding-bottom:8px;
             border-bottom:1px solid #f1f5f9}
.card-title-icon{font-size:18px}
.card-title-tag{margin-left:auto;font-size:11px;font-weight:600;color:#94a3b8;text-transform:none;letter-spacing:0}

/* Stats grid général */
.mini-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
@media(max-width:900px){.mini-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.mini-stats{grid-template-columns:1fr}}
.mini-stat{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;
           border-left:4px solid;display:flex;flex-direction:column;gap:4px}
.mini-stat-num{font-size:24px;font-weight:800;line-height:1;letter-spacing:-.4px}
.mini-stat-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;line-height:1.3}
.ms-blue{border-left-color:#1d4ed8} .ms-blue .mini-stat-num{color:#1d4ed8}
.ms-green{border-left-color:#059669} .ms-green .mini-stat-num{color:#059669}
.ms-orange{border-left-color:#f59e0b} .ms-orange .mini-stat-num{color:#b45309}
.ms-purple{border-left-color:#7c3aed} .ms-purple .mini-stat-num{color:#7c3aed}

/* === Section 1 : graphique en barres temporelles === */
.chart-container{padding:8px 4px}
.chart-bars{display:flex;align-items:end;gap:2px;height:200px;padding:0 6px;
            border-bottom:2px solid #e5e7eb;position:relative}
.chart-bar-wrap{flex:1;display:flex;align-items:end;height:100%;position:relative}
.chart-bar{width:100%;background:linear-gradient(180deg,#3b82f6,#1d4ed8);border-radius:3px 3px 0 0;
           min-height:2px;cursor:default;transition:.15s}
.chart-bar:hover{background:linear-gradient(180deg,#60a5fa,#2563eb)}
.chart-bar-empty{background:#e5e7eb;min-height:2px}
.chart-bar-tooltip{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);
                    background:#0f172a;color:#fff;font-size:11px;padding:5px 10px;border-radius:6px;
                    white-space:nowrap;opacity:0;pointer-events:none;transition:.15s;margin-bottom:6px;font-weight:600;z-index:10}
.chart-bar-wrap:hover .chart-bar-tooltip{opacity:1}
.chart-x-axis{display:flex;justify-content:space-between;font-size:10px;color:#94a3b8;margin-top:8px;padding:0 6px}
.chart-stats{display:flex;gap:24px;margin-top:14px;flex-wrap:wrap}
.chart-stat{font-size:12px;color:#475569}
.chart-stat strong{color:#1d4ed8;font-size:14px}

/* === Section 2 : Camembert pathologies === */
.pie-row{display:grid;grid-template-columns:auto 1fr;gap:30px;align-items:center}
@media(max-width:780px){.pie-row{grid-template-columns:1fr}}
.pie-chart-wrap{display:flex;justify-content:center}
.pie-svg{display:block}
.pie-legend{display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto;padding-right:8px}
.pie-legend-row{display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:8px;
                 background:#fafbfc;border:1px solid #f1f5f9;font-size:13px}
.pie-legend-color{width:14px;height:14px;border-radius:3px;flex-shrink:0}
.pie-legend-name{flex:1;font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pie-legend-val{font-weight:700;color:#64748b;font-size:12px;background:#fff;padding:2px 8px;border-radius:8px;border:1px solid #e5e7eb}
.pie-legend-pct{font-size:11px;color:#94a3b8;min-width:40px;text-align:right}

/* === Section 3 : Barres horizontales (top activités, freins) === */
.bar-list{display:flex;flex-direction:column;gap:10px}
.bar-row{display:flex;align-items:center;gap:12px}
.bar-rank{width:24px;height:24px;border-radius:50%;background:#eff6ff;color:#1d4ed8;
           font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.bar-rank.r1{background:#fef3c7;color:#92400e}
.bar-rank.r2{background:#e5e7eb;color:#475569}
.bar-rank.r3{background:#fee2e2;color:#b91c1c}
.bar-name{flex:1;font-size:13px;font-weight:600;color:#1e293b;
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:120px}
.bar-progress{flex:2;height:8px;background:#f1f5f9;border-radius:4px;position:relative;overflow:hidden}
.bar-fill{position:absolute;top:0;left:0;bottom:0;border-radius:4px;transition:width .6s}
.bar-fill-blue{background:linear-gradient(90deg,#3b82f6,#1d4ed8)}
.bar-fill-red{background:linear-gradient(90deg,#f87171,#dc2626)}
.bar-fill-green{background:linear-gradient(90deg,#34d399,#059669)}
.bar-count{font-size:12px;font-weight:700;color:#475569;background:#f1f5f9;padding:3px 10px;border-radius:10px;min-width:40px;text-align:center}

/* === Section 5 : pyramide démographique === */
.demo-row{display:grid;grid-template-columns:2fr 1fr;gap:24px}
@media(max-width:780px){.demo-row{grid-template-columns:1fr}}

.pyramid{display:flex;flex-direction:column;gap:6px}
.pyramid-row{display:grid;grid-template-columns:50px 1fr 4px 1fr 50px;align-items:center;gap:8px}
.pyramid-label{font-size:11px;color:#64748b;font-weight:600;text-align:center}
.pyramid-bar{height:18px;display:flex;justify-content:flex-end}
.pyramid-bar.right{justify-content:flex-start}
.pyramid-fill{height:100%;border-radius:3px;display:flex;align-items:center;justify-content:flex-end;padding:0 6px;
                color:#fff;font-size:10px;font-weight:700;transition:.4s}
.pyramid-fill.left{background:linear-gradient(90deg,#1d4ed8,#3b82f6);justify-content:flex-end}
.pyramid-fill.right{background:linear-gradient(90deg,#ec4899,#db2777);justify-content:flex-start}
.pyramid-center{height:18px;background:#e5e7eb;border-radius:1px}
.pyramid-legend{display:flex;justify-content:center;gap:24px;margin-top:14px;font-size:12px;color:#475569}
.pyramid-legend-row{display:flex;align-items:center;gap:6px}
.pyramid-color{width:14px;height:14px;border-radius:3px}

/* Donut sexe */
.donut-wrap{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px}
.donut-svg{display:block}
.donut-legend{display:flex;gap:18px;font-size:13px}
.donut-legend-row{display:flex;align-items:center;gap:6px}
.donut-stats{font-size:12px;color:#64748b;text-align:center;line-height:1.5}

/* === Section 6 : indicateurs qualité === */
.quality-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
@media(max-width:780px){.quality-grid{grid-template-columns:1fr}}
.quality-item{background:#fafbfc;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;
              display:flex;justify-content:space-between;align-items:center;gap:14px}
.quality-label{font-size:13px;color:#1e293b;font-weight:600;line-height:1.4}
.quality-value{font-size:22px;font-weight:800;color:#1d4ed8;flex-shrink:0}
.quality-progress{flex:1;height:6px;background:#fff;border-radius:3px;overflow:hidden;border:1px solid #e5e7eb;max-width:120px}
.quality-progress-fill{height:100%;background:linear-gradient(90deg,#3b82f6,#1d4ed8);border-radius:3px;transition:.6s}

/* === Section 7 : exports === */
.export-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:780px){.export-grid{grid-template-columns:1fr}}
.export-card{padding:18px;border:1px solid #e5e7eb;border-radius:12px;text-align:center;
              text-decoration:none;color:#1e293b;transition:.2s;display:flex;flex-direction:column;
              align-items:center;gap:8px;background:#fff}
.export-card:hover{transform:translateY(-3px);box-shadow:0 8px 18px rgba(15,23,42,.08)}
.export-icon{font-size:32px;line-height:1}
.export-name{font-size:14px;font-weight:700;color:#0f172a}
.export-desc{font-size:11px;color:#94a3b8}
.ex-csv{border-color:#86efac} .ex-csv:hover{background:#f0fdf4}
.ex-xls{border-color:#a7f3d0} .ex-xls:hover{background:#ecfdf5}
.ex-pdf{border-color:#fecaca} .ex-pdf:hover{background:#fef2f2}

.empty{padding:30px;text-align:center;color:#94a3b8;font-style:italic;font-size:13px}

/* Imprimer */
@media print {
    .topbar-admin, .section-nav, .period-bar, .export-grid { display:none !important; }
    .card { box-shadow:none; border:1px solid #ccc; break-inside:avoid; page-break-inside:avoid; }
    body { background:#fff }
}
</style>
</head>
<body>

<div class="topbar-admin">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <span class="admin-badge">Admin</span>
        <nav class="topbar-nav">
            <a href="index.php">📊 Dashboard</a>
            <a href="ontology.php">🩺 Ontologie</a>
            <a href="stats.php" class="active">📈 Stats avancées</a>
            <a href="../index.php">← Application</a>
            <a href="change_password.php">🔑 Mon compte</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <div class="dash-header">
        <h1>📈 Statistiques avancées</h1>
        <p>Analyse approfondie de l'activité de prescription · <strong><?= hS($periodLabel) ?></strong></p>
    </div>

    <!-- Navigation interne (ancres) -->
    <div class="section-nav">
        <div class="section-nav-inner">
            <a href="#period">⏱ Période</a>
            <a href="#activity">📈 Activité temps</a>
            <a href="#pathos">🩺 Pathologies</a>
            <a href="#acts">🏃 Activités</a>
            <a href="#freins">⚠ Freins/Leviers</a>
            <a href="#demo">👥 Démographie</a>
            <a href="#quality">✅ Qualité</a>
            <a href="#export">💾 Exports</a>
        </div>
    </div>

    <!-- ━━━ FILTRE PÉRIODE ━━━ -->
    <div class="period-bar" id="period">
        <div class="period-bar-title">
            <span>⏱ Période d'analyse</span>
            <span class="period-active"><?= hS($periodLabel) ?></span>
        </div>
        <form method="get" class="period-form">
            <div class="field">
                <label>Du</label>
                <input type="date" name="from" value="<?= hS($dateFrom) ?>">
            </div>
            <div class="field">
                <label>Au</label>
                <input type="date" name="to" value="<?= hS($dateTo) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 Filtrer</button>
        </form>
        <div class="presets">
            <a href="?preset=7d" class="preset-btn <?= $preset === '7d' ? 'active' : '' ?>">7 jours</a>
            <a href="?preset=30d" class="preset-btn <?= $preset === '30d' ? 'active' : '' ?>">30 jours</a>
            <a href="?preset=3m" class="preset-btn <?= $preset === '3m' ? 'active' : '' ?>">3 mois</a>
            <a href="?preset=6m" class="preset-btn <?= $preset === '6m' ? 'active' : '' ?>">6 mois</a>
            <a href="?preset=1y" class="preset-btn <?= $preset === '1y' ? 'active' : '' ?>">1 an</a>
            <a href="?preset=all" class="preset-btn <?= $preset === 'all' ? 'active' : '' ?>">Tout</a>
        </div>
    </div>

    <!-- Mini-stats globales -->
    <div class="mini-stats">
        <div class="mini-stat ms-blue">
            <div class="mini-stat-num"><?= $totalPrescriptions ?></div>
            <div class="mini-stat-lbl">Prescriptions</div>
        </div>
        <div class="mini-stat ms-green">
            <div class="mini-stat-num"><?= $totalPatientsActifs ?></div>
            <div class="mini-stat-lbl">Patients actifs</div>
        </div>
        <div class="mini-stat ms-purple">
            <div class="mini-stat-num"><?= count($topPathologies) ?></div>
            <div class="mini-stat-lbl">Pathologies distinctes</div>
        </div>
        <div class="mini-stat ms-orange">
            <div class="mini-stat-num"><?= count($topActivites) > 0 ? count($topActivites) : '0' ?></div>
            <div class="mini-stat-lbl">Activités distinctes</div>
        </div>
    </div>

    <!-- ━━━ SECTION 1 : Activité dans le temps ━━━ -->
    <div class="card" id="activity">
        <h3 class="card-title">
            <span class="card-title-icon">📈</span>
            <span>Activité dans le temps</span>
            <span class="card-title-tag">Prescriptions par jour</span>
        </h3>
        <?php if (empty($activityDaily)): ?>
            <div class="empty">Aucune prescription dans cette période.</div>
        <?php else: ?>
            <div class="chart-container">
                <div class="chart-bars">
                    <?php foreach ($activityDaily as $date => $count):
                        $height = $maxActivityDaily > 0 ? max(2, ($count / $maxActivityDaily) * 100) : 2;
                        $isEmpty = $count === 0;
                    ?>
                        <div class="chart-bar-wrap">
                            <div class="<?= $isEmpty ? 'chart-bar-empty' : 'chart-bar' ?>"
                                 style="height:<?= $height ?>%"></div>
                            <div class="chart-bar-tooltip">
                                <?= $count ?> · <?= hS((new DateTime($date))->format('d/m/Y')) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-x-axis">
                    <?php
                    $dateKeys = array_keys($activityDaily);
                    $first = reset($dateKeys);
                    $last  = end($dateKeys);
                    $mid   = $dateKeys[intval(count($dateKeys) / 2)] ?? '';
                    ?>
                    <span><?= hS((new DateTime($first))->format('d/m/Y')) ?></span>
                    <?php if ($mid !== '' && $mid !== $first && $mid !== $last): ?>
                        <span><?= hS((new DateTime($mid))->format('d/m/Y')) ?></span>
                    <?php endif; ?>
                    <span><?= hS((new DateTime($last))->format('d/m/Y')) ?></span>
                </div>
                <div class="chart-stats">
                    <div class="chart-stat">Moyenne : <strong><?= $avgActivityDaily ?></strong> presc./jour</div>
                    <div class="chart-stat">Pic : <strong><?= $maxActivityDaily ?></strong> presc.</div>
                    <div class="chart-stat">Jours : <strong><?= count($activityDaily) ?></strong></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ SECTION 2 : Répartition des pathologies (camembert) ━━━ -->
    <div class="card" id="pathos">
        <h3 class="card-title">
            <span class="card-title-icon">🩺</span>
            <span>Répartition des pathologies</span>
            <span class="card-title-tag">Top <?= min(10, count($topPathologies)) ?></span>
        </h3>
        <?php if (empty($topPathologies)): ?>
            <div class="empty">Aucune pathologie liée à un patient sur cette période.</div>
        <?php else:
            $piePathos = array_slice($topPathologies, 0, 10);
            $piePathoTotal = array_sum(array_column($piePathos, 'count'));
        ?>
            <div class="pie-row">
                <!-- Camembert SVG -->
                <div class="pie-chart-wrap">
                    <svg class="pie-svg" width="260" height="260" viewBox="0 0 200 200">
                        <?php
                        // Calculer les arcs du camembert
                        $cumAngle = -90; // commence en haut
                        foreach ($piePathos as $idx => $p) {
                            $color = $pieColors[$idx % count($pieColors)];
                            $angle = ($p['count'] / max(1, $piePathoTotal)) * 360;
                            $start = $cumAngle;
                            $end   = $cumAngle + $angle;
                            $largeArc = $angle > 180 ? 1 : 0;

                            $x1 = 100 + 80 * cos(deg2rad($start));
                            $y1 = 100 + 80 * sin(deg2rad($start));
                            $x2 = 100 + 80 * cos(deg2rad($end));
                            $y2 = 100 + 80 * sin(deg2rad($end));

                            if ($angle >= 359.99) {
                                // Cercle entier (une seule pathologie)
                                echo "<circle cx='100' cy='100' r='80' fill='" . hS($color) . "' />";
                            } else {
                                $path = "M 100 100 L $x1 $y1 A 80 80 0 $largeArc 1 $x2 $y2 Z";
                                echo "<path d='$path' fill='" . hS($color) . "' stroke='#fff' stroke-width='2' />";
                            }
                            $cumAngle = $end;
                        }
                        // Donut hole pour effet pro
                        echo "<circle cx='100' cy='100' r='38' fill='#fff' />";
                        echo "<text x='100' y='95' text-anchor='middle' font-family='-apple-system,sans-serif' font-size='22' font-weight='800' fill='#1e293b'>" . count($topPathologies) . "</text>";
                        echo "<text x='100' y='112' text-anchor='middle' font-family='-apple-system,sans-serif' font-size='10' font-weight='600' fill='#64748b' letter-spacing='1'>PATHOLOGIES</text>";
                        ?>
                    </svg>
                </div>
                <!-- Légende -->
                <div class="pie-legend">
                    <?php foreach ($piePathos as $idx => $p):
                        $color = $pieColors[$idx % count($pieColors)];
                        $pct = $piePathoTotal > 0 ? round($p['count'] / $piePathoTotal * 100, 1) : 0;
                    ?>
                        <div class="pie-legend-row">
                            <span class="pie-legend-color" style="background:<?= hS($color) ?>"></span>
                            <span class="pie-legend-name"><?= hS($p['label']) ?></span>
                            <span class="pie-legend-val"><?= $p['count'] ?></span>
                            <span class="pie-legend-pct"><?= $pct ?> %</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ SECTION 3 : Top 10 activités ━━━ -->
    <div class="card" id="acts">
        <h3 class="card-title">
            <span class="card-title-icon">🏃</span>
            <span>Top 10 activités prescrites</span>
        </h3>
        <?php if (empty($topActivites)): ?>
            <div class="empty">Aucune activité prescrite sur cette période.</div>
        <?php else:
            $maxAct = $topActivites[0]['count'] ?? 1;
        ?>
            <div class="bar-list">
                <?php foreach ($topActivites as $idx => $a):
                    $pct = $maxAct > 0 ? ($a['count'] / $maxAct) * 100 : 0;
                    $rankClass = 'r' . min(3, $idx + 1);
                ?>
                    <div class="bar-row">
                        <div class="bar-rank <?= $idx < 3 ? $rankClass : '' ?>"><?= $idx + 1 ?></div>
                        <div class="bar-name"><?= hS($a['label']) ?></div>
                        <div class="bar-progress"><div class="bar-fill bar-fill-blue" style="width:<?= $pct ?>%"></div></div>
                        <div class="bar-count"><?= $a['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ SECTION 4 : Freins et leviers ━━━ -->
    <div class="card" id="freins">
        <h3 class="card-title">
            <span class="card-title-icon">⚠</span>
            <span>Freins et leviers identifiés</span>
        </h3>

        <h4 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#475569">⚠ Top 10 freins rencontrés</h4>
        <?php if (empty($topFreins)): ?>
            <div class="empty" style="padding:20px">Aucun frein identifié sur cette période.</div>
        <?php else:
            $maxFrein = $topFreins[0]['count'] ?? 1;
        ?>
            <div class="bar-list" style="margin-bottom:24px">
                <?php foreach ($topFreins as $idx => $f):
                    $pct = $maxFrein > 0 ? ($f['count'] / $maxFrein) * 100 : 0;
                    $rankClass = 'r' . min(3, $idx + 1);
                ?>
                    <div class="bar-row">
                        <div class="bar-rank <?= $idx < 3 ? $rankClass : '' ?>"><?= $idx + 1 ?></div>
                        <div class="bar-name"><?= hS($f['label']) ?></div>
                        <div class="bar-progress"><div class="bar-fill bar-fill-red" style="width:<?= $pct ?>%"></div></div>
                        <div class="bar-count"><?= $f['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h4 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#475569">✓ Top 10 leviers utilisés</h4>
        <?php if (empty($topLeviers)): ?>
            <div class="empty" style="padding:20px">Aucun levier sélectionné sur cette période.</div>
        <?php else:
            $maxLev = $topLeviers[0]['count'] ?? 1;
        ?>
            <div class="bar-list">
                <?php foreach ($topLeviers as $idx => $l):
                    $pct = $maxLev > 0 ? ($l['count'] / $maxLev) * 100 : 0;
                    $rankClass = 'r' . min(3, $idx + 1);
                ?>
                    <div class="bar-row">
                        <div class="bar-rank <?= $idx < 3 ? $rankClass : '' ?>"><?= $idx + 1 ?></div>
                        <div class="bar-name"><?= hS($l['label']) ?></div>
                        <div class="bar-progress"><div class="bar-fill bar-fill-green" style="width:<?= $pct ?>%"></div></div>
                        <div class="bar-count"><?= $l['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ SECTION 5 : Démographie ━━━ -->
    <div class="card" id="demo">
        <h3 class="card-title">
            <span class="card-title-icon">👥</span>
            <span>Démographie des patients</span>
            <span class="card-title-tag"><?= $totalDemo ?> patients actifs</span>
        </h3>
        <?php if ($totalDemo === 0): ?>
            <div class="empty">Aucune donnée démographique disponible.</div>
        <?php else:
            // Pour la pyramide : trouver le max H ou F dans toute la pyramide
            $maxBucket = 0;
            foreach ($ageBuckets as $b) {
                $maxBucket = max($maxBucket, $b['h'], $b['f']);
            }
            if ($maxBucket === 0) $maxBucket = 1;
        ?>
            <div class="demo-row">

                <!-- Pyramide des âges -->
                <div>
                    <h4 style="margin:0 0 14px;font-size:13px;font-weight:700;color:#475569">Pyramide des âges</h4>
                    <div class="pyramid">
                        <?php foreach (array_reverse($ageBuckets, true) as $bucket => $data):
                            $pctH = ($data['h'] / $maxBucket) * 100;
                            $pctF = ($data['f'] / $maxBucket) * 100;
                        ?>
                            <div class="pyramid-row">
                                <span class="pyramid-label"><?= hS($bucket) ?></span>
                                <div class="pyramid-bar">
                                    <?php if ($data['h'] > 0): ?>
                                        <div class="pyramid-fill left" style="width:<?= $pctH ?>%"
                                             title="Hommes : <?= $data['h'] ?>"><?= $data['h'] ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="pyramid-center"></div>
                                <div class="pyramid-bar right">
                                    <?php if ($data['f'] > 0): ?>
                                        <div class="pyramid-fill right" style="width:<?= $pctF ?>%"
                                             title="Femmes : <?= $data['f'] ?>"><?= $data['f'] ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="pyramid-label"><?= hS($bucket) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="pyramid-legend">
                        <div class="pyramid-legend-row">
                            <span class="pyramid-color" style="background:linear-gradient(90deg,#1d4ed8,#3b82f6)"></span>
                            <span>Masculin (<?= $totalH ?>)</span>
                        </div>
                        <div class="pyramid-legend-row">
                            <span class="pyramid-color" style="background:linear-gradient(90deg,#ec4899,#db2777)"></span>
                            <span>Féminin (<?= $totalF ?>)</span>
                        </div>
                    </div>
                </div>

                <!-- Donut sexe -->
                <div>
                    <h4 style="margin:0 0 14px;font-size:13px;font-weight:700;color:#475569">Répartition par sexe</h4>
                    <div class="donut-wrap">
                        <?php
                        $totalSexe = $totalH + $totalF;
                        if ($totalSexe > 0) {
                            $pctHomme = $totalH / $totalSexe;
                            $angleH = $pctHomme * 360;
                            $largeArc = $angleH > 180 ? 1 : 0;
                            $x2 = 80 + 60 * cos(deg2rad($angleH - 90));
                            $y2 = 80 + 60 * sin(deg2rad($angleH - 90));
                        }
                        ?>
                        <svg class="donut-svg" width="160" height="160" viewBox="0 0 160 160">
                            <?php if ($totalSexe > 0): ?>
                                <?php if ($totalH > 0 && $totalF === 0): ?>
                                    <circle cx="80" cy="80" r="60" fill="#1d4ed8" />
                                <?php elseif ($totalF > 0 && $totalH === 0): ?>
                                    <circle cx="80" cy="80" r="60" fill="#db2777" />
                                <?php else: ?>
                                    <!-- Arc Homme -->
                                    <path d="M 80 20 A 60 60 0 <?= $largeArc ?> 1 <?= $x2 ?> <?= $y2 ?> L 80 80 Z" fill="#1d4ed8" />
                                    <!-- Arc Femme (complément) -->
                                    <path d="M <?= $x2 ?> <?= $y2 ?> A 60 60 0 <?= $largeArc === 1 ? 0 : 1 ?> 1 80 20 L 80 80 Z" fill="#db2777" />
                                <?php endif; ?>
                                <circle cx="80" cy="80" r="34" fill="#fff" />
                                <text x="80" y="76" text-anchor="middle" font-family="-apple-system,sans-serif" font-size="20" font-weight="800" fill="#1e293b"><?= $totalSexe ?></text>
                                <text x="80" y="92" text-anchor="middle" font-family="-apple-system,sans-serif" font-size="9" font-weight="600" fill="#64748b" letter-spacing="1">PATIENTS</text>
                            <?php else: ?>
                                <circle cx="80" cy="80" r="60" fill="#e5e7eb" />
                                <text x="80" y="86" text-anchor="middle" font-family="-apple-system,sans-serif" font-size="11" fill="#94a3b8">Aucune donnée</text>
                            <?php endif; ?>
                        </svg>
                        <div class="donut-legend">
                            <div class="donut-legend-row">
                                <span class="pyramid-color" style="background:#1d4ed8"></span>
                                <span><?= $totalH ?> hommes (<?= $totalSexe > 0 ? round($totalH / $totalSexe * 100) : 0 ?>%)</span>
                            </div>
                            <div class="donut-legend-row">
                                <span class="pyramid-color" style="background:#db2777"></span>
                                <span><?= $totalF ?> femmes (<?= $totalSexe > 0 ? round($totalF / $totalSexe * 100) : 0 ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ SECTION 6 : Indicateurs qualité ━━━ -->
    <div class="card" id="quality">
        <h3 class="card-title">
            <span class="card-title-icon">✅</span>
            <span>Indicateurs qualité</span>
        </h3>
        <div class="quality-grid">
            <div class="quality-item">
                <div>
                    <div class="quality-label">Pathologies / patient</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">Moyenne (actives + archivées)</div>
                </div>
                <div class="quality-value"><?= number_format($avgPathosPerPatient, 2, ',', ' ') ?></div>
            </div>
            <div class="quality-item">
                <div>
                    <div class="quality-label">Activités / prescription</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">Moyenne</div>
                </div>
                <div class="quality-value"><?= number_format($avgActsPerPresc, 2, ',', ' ') ?></div>
            </div>
            <div class="quality-item">
                <div>
                    <div class="quality-label">Prescriptions avec freins identifiés</div>
                    <div class="quality-progress" style="margin-top:6px">
                        <div class="quality-progress-fill" style="width:<?= $pctWithFreins ?>%"></div>
                    </div>
                </div>
                <div class="quality-value"><?= $pctWithFreins ?>%</div>
            </div>
            <div class="quality-item">
                <div>
                    <div class="quality-label">Prescriptions avec résumé IA</div>
                    <div class="quality-progress" style="margin-top:6px">
                        <div class="quality-progress-fill" style="width:<?= $pctWithSummary ?>%"></div>
                    </div>
                </div>
                <div class="quality-value"><?= $pctWithSummary ?>%</div>
            </div>
        </div>
    </div>

    <!-- ━━━ SECTION 7 : Exports ━━━ -->
    <div class="card" id="export">
        <h3 class="card-title">
            <span class="card-title-icon">💾</span>
            <span>Exporter les données</span>
            <span class="card-title-tag"><?= $totalPrescriptions ?> prescriptions sur la période</span>
        </h3>
        <p style="margin:0 0 16px;color:#64748b;font-size:13px">
            Téléchargez les données brutes de cette période pour analyse externe ou archivage.
        </p>
        <div class="export-grid">
            <?php
            $exportParams = http_build_query(array_filter([
                'from'   => $dateFrom,
                'to'     => $dateTo,
                'preset' => $preset,
            ]));
            ?>
            <a href="?<?= $exportParams ?>&export=csv" class="export-card ex-csv">
                <div class="export-icon">📊</div>
                <div class="export-name">Export CSV</div>
                <div class="export-desc">Compatible Excel, R, Python</div>
            </a>
            <a href="?<?= $exportParams ?>&export=xls" class="export-card ex-xls">
                <div class="export-icon">📈</div>
                <div class="export-name">Export Excel (XLS)</div>
                <div class="export-desc">Format Microsoft Excel</div>
            </a>
            <a href="javascript:window.print()" class="export-card ex-pdf">
                <div class="export-icon">📄</div>
                <div class="export-name">Imprimer / PDF</div>
                <div class="export-desc">Cette page complète</div>
            </a>
        </div>
    </div>

</div>

<script>
// Smooth scroll pour la nav interne
document.querySelectorAll('.section-nav a').forEach(link => {
    link.addEventListener('click', (e) => {
        const href = link.getAttribute('href');
        if (href?.startsWith('#')) {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });
});
</script>

</body>
</html>
