<?php
declare(strict_types=1);

require_once __DIR__ . '/patient_session.php';
requirePathologiesSelected();  // redirige vers index.php si pas de pathologies
requirePatientSelected();      // redirige vers patient.php si pas de patient

const FUSEKI_ENDPOINT = 'https://fuseki-apa4cad.onrender.com/mononto/query';
const NS = 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';

// ── Utilitaires ───────────────────────────────────────────────────────────
function sparqlQuery(string $query): array {
    $url = FUSEKI_ENDPOINT . '?query=' . urlencode($query) . '&output=json';
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => "Accept: application/sparql-results+json\r\n",
        'timeout' => 30, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false) return ['ok' => false, 'error' => 'Fuseki inaccessible'];
    if ($statusLine !== '' && !str_contains($statusLine, '200'))
        return ['ok' => false, 'error' => 'HTTP: ' . $statusLine];
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']['bindings']))
        return ['ok' => false, 'error' => 'Réponse invalide'];
    return ['ok' => true, 'bindings' => $data['results']['bindings']];
}
function localName(string $uri): string {
    if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
    if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
    return $uri;
}
function prettyLabel(string $name): string {
    $name = str_replace('_', ' ', $name);
    $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    return trim((string)$name);
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function categoryTitle(string $local): string {
    return match ($local) {
        'AffectionDeLongueDuree'         => 'Affections de longue durée',
        'PathologieCardiaque'            => 'Pathologies cardiaques',
        'PathologieDigestive'            => 'Pathologies digestives',
        'PathologieMusculosquelettique'  => 'Pathologies musculosquelettiques',
        'PathologieRespiratoire'         => 'Pathologies respiratoires',
        'Cancer'                         => 'Cancer',
        'Hypertension_arterielle'        => 'Hypertension artérielle',
        'Obesite'                        => 'Obésité',
        'Diabete'                        => 'Diabète',
        'DT1'                            => 'Diabète de type 1',
        'DT2'                            => 'Diabète de type 2',
        'AngorStable'                    => 'Angor stable',
        'Myocardite'                     => 'Myocardite',
        'Lombalgie'                      => 'Lombalgie',
        'Arthrose'                       => 'Arthrose',
        default => prettyLabel($local),
    };
}

// ── Chargement des recommandations finales (depuis rapport.php) ───────────
function loadRecommendations(string $pathologyUri): array {
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?nomActivite ?adaptation
WHERE {
  VALUES ?patho { <' . $pathologyUri . '> }
  {
    ?patho rdfs:subClassOf+ ?super .
    ?super rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ex:aPourActiviteRecommandee ;
                 owl:someValuesFrom ?cible .
    FILTER(isIRI(?cible))
    BIND(STRAFTER(STR(?cible), "#") AS ?nomActivite)
  }
  UNION
  {
    ?patho rdfs:subClassOf+ ?super .
    ?super rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ex:aPourActiviteRecommandee ;
                 owl:someValuesFrom ?cible .
    FILTER(isBlank(?cible))
    ?cible owl:intersectionOf ?list2 .
    ?list2 rdf:rest*/rdf:first ?elt .
    FILTER(isIRI(?elt))
    FILTER(?elt != ex:Pathologie) FILTER(?elt != ex:ActivitePhysique)
    FILTER(?elt != ex:Adaptation) FILTER(?elt != ex:Frein)
    BIND(STRAFTER(STR(?elt), "#") AS ?nomActivite)
    OPTIONAL {
      ?list2 rdf:rest*/rdf:first ?r2 .
      ?r2 owl:onProperty ex:aPourAdaptation ; owl:someValuesFrom ?ad .
      BIND(STRAFTER(STR(?ad), "#") AS ?adaptation)
    }
  }
}
ORDER BY ?nomActivite';
    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $grouped = [];
    foreach ($result['bindings'] as $row) {
        $act = $row['nomActivite']['value'] ?? '';
        $adap = $row['adaptation']['value'] ?? '';
        if ($act === '') continue;
        if (!isset($grouped[$act])) $grouped[$act] = ['activity' => $act, 'adaptations' => []];
        if ($adap !== '' && !in_array($adap, $grouped[$act]['adaptations'], true))
            $grouped[$act]['adaptations'][] = $adap;
    }
    return array_values($grouped);
}

function loadContraindications(string $pathologyUri): array {
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?nomElement
WHERE {
  VALUES ?patho { <' . $pathologyUri . '> }
  ?patho rdfs:subClassOf+ ?super .
  ?super rdfs:subClassOf ?expr .
  ?expr owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?restriction .
  ?restriction owl:onProperty ex:aPourContreIndication ;
               owl:someValuesFrom ?cible .
  FILTER(isIRI(?cible))
  BIND(STRAFTER(STR(?cible), "#") AS ?nomElement)
}';
    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $v = $row['nomElement']['value'] ?? '';
        if ($v !== '') $items[$v] = $v;
    }
    return array_values($items);
}

function loadBlockedByGenericCI(string $ciLocalName): string {
    if (in_array($ciLocalName, ['ActivitePhysique'], true)) return 'ALL';
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT (COUNT(?child) AS ?nb)
WHERE {
  ?child rdfs:subClassOf+ ex:' . $ciLocalName . ' .
  FILTER(STRSTARTS(STR(?child), "' . NS . '"))
}';
    $result = sparqlQuery($query);
    if ($result['ok'] && isset($result['bindings'][0]['nb']['value'])) {
        if ((int)$result['bindings'][0]['nb']['value'] > 0) return 'PARENT';
    }
    return 'SPECIFIC';
}

// ── Chargement des freins + leviers depuis l'ontologie ────────────────────
function loadFreinsAndLeviers(): array {
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?frein ?freinType ?levier
WHERE {
  ?frein rdfs:subClassOf ?anon .
  ?anon owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?freinType .
  FILTER(isIRI(?freinType))
  FILTER(STRSTARTS(STR(?freinType), "' . NS . '"))
  ?freinType rdfs:subClassOf ex:Frein .
  FILTER(?freinType != ex:Frein)
  FILTER(STRSTARTS(STR(?frein), "' . NS . '"))
  FILTER(?frein != ?freinType)
  OPTIONAL {
    ?list rdf:rest*/rdf:first ?restr .
    ?restr owl:onProperty ex:aPourLevier .
    {
      ?restr owl:someValuesFrom ?lev .
      FILTER(isIRI(?lev)) FILTER(STRSTARTS(STR(?lev), "' . NS . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
    UNION
    {
      ?restr owl:someValuesFrom ?union . FILTER(isBlank(?union))
      ?union owl:unionOf/rdf:rest*/rdf:first ?lev .
      FILTER(isIRI(?lev)) FILTER(STRSTARTS(STR(?lev), "' . NS . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
  }
}
ORDER BY ?freinType ?frein ?levier';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];

    $typeMeta = [
        'FreinPhysique'        => ['label' => 'Frein physique',        'icon' => '', 'order' => 1],
        'FreinPsychologique'   => ['label' => 'Frein psychologique',   'icon' => '', 'order' => 2],
        'FreinMotivationnel'   => ['label' => 'Frein motivationnel',   'icon' => '', 'order' => 3],
        'FreinSituationnel'    => ['label' => 'Frein situationnel',    'icon' => '', 'order' => 4],
        'FreinSocial'          => ['label' => 'Frein social',          'icon' => '', 'order' => 5],
        'FreinEnvironnemental' => ['label' => 'Frein environnemental', 'icon' => '', 'order' => 6],
    ];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $frein     = localName($row['frein']['value']     ?? '');
        $typeLocal = localName($row['freinType']['value'] ?? '');
        $levier    = $row['levier']['value'] ?? '';
        if ($frein === '' || isset($typeMeta[$frein])) continue;
        if (!isset($items[$frein])) {
            $m = $typeMeta[$typeLocal] ?? ['label' => prettyLabel($typeLocal), 'icon' => '•', 'order' => 99];
            $items[$frein] = [
                'id'        => $frein,
                'label'     => prettyLabel($frein),
                'typeKey'   => $typeLocal,
                'typeLabel' => $m['label'],
                'typeIcon'  => $m['icon'],
                'typeOrder' => $m['order'],
                'leviers'   => [],
            ];
        }
        if ($levier !== '' && !in_array($levier, $items[$frein]['leviers'], true))
            $items[$frein]['leviers'][] = $levier;
    }

    usort($items, fn($a, $b) => $a['typeOrder'] <=> $b['typeOrder'] ?: strcmp($a['label'], $b['label']));
    $grouped = [];
    foreach ($items as $data) {
        $grouped[$data['typeLabel']][] = $data;
    }
    return $grouped;
}

// ── Récupération des paramètres ───────────────────────────────────────────
// Priorité à la session (parcours inversé), fallback sur GET (compatibilité)
$selected = $_GET['pathologies'] ?? getParcoursPathologies();
if (!is_array($selected)) $selected = [$selected];
$selected = array_values(array_filter($selected, fn($v) => is_string($v) && $v !== ''));

if (empty($selected)) {
    header('Location: index.php');
    exit;
}

// ── Construire $finalRecos (même logique que rapport.php) ─────────────────
$pathologyLabels = [];
$recoByPatho     = [];
$contraByPatho   = [];

foreach ($selected as $uri) {
    $pathologyLabels[$uri] = categoryTitle(localName($uri));
    $recoByPatho[$uri]     = loadRecommendations($uri);
    $contraByPatho[$uri]   = loadContraindications($uri);
}

// Déduplication recommandations
$seenActs   = [];
$finalRecos = [];
$finalContra = [];
foreach ($selected as $uri) {
    $lbl = $pathologyLabels[$uri];
    foreach ($recoByPatho[$uri] as $item) {
        $act = $item['activity'];
        if (!isset($seenActs[$act])) {
            $seenActs[$act]      = count($finalRecos);
            $item['pathoLabels'] = [$lbl];
            $finalRecos[]        = $item;
        } else {
            $idx = $seenActs[$act];
            foreach ($item['adaptations'] as $adap)
                if (!in_array($adap, $finalRecos[$idx]['adaptations'], true))
                    $finalRecos[$idx]['adaptations'][] = $adap;
            if (!in_array($lbl, $finalRecos[$idx]['pathoLabels'], true))
                $finalRecos[$idx]['pathoLabels'][] = $lbl;
        }
    }
    foreach ($contraByPatho[$uri] as $c) {
        if (!isset($finalContra[$c])) $finalContra[$c] = [];
        if (!in_array($lbl, $finalContra[$c], true)) $finalContra[$c][] = $lbl;
    }
}

// Filtrage CI globales
$globalCIBlocks = [];
foreach ($selected as $uri) {
    foreach ($contraByPatho[$uri] as $c) {
        $t = loadBlockedByGenericCI($c);
        if ($t === 'ALL' || $t === 'PARENT')
            $globalCIBlocks[$uri][] = ['ci' => $c, 'type' => $t, 'label' => $pathologyLabels[$uri]];
    }
}
if (!empty($globalCIBlocks)) {
    $ok = [];
    foreach ($finalRecos as $item) {
        $blocked = false;
        foreach ($globalCIBlocks as $blocks)
            foreach ($blocks as $b)
                if ($b['type'] === 'ALL') { $blocked = true; break 2; }
        if (!$blocked) $ok[] = $item;
    }
    $finalRecos = $ok;
}

// ── Charger les freins ────────────────────────────────────────────────────
$freinsGrouped = loadFreinsAndLeviers();

// ── Données pour la synthèse à gauche (identique à patient.php) ───────────
$patient   = getPatient();
$parcoursCI = getParcoursContraindications();
$parcoursActivitesPathos = $_SESSION['parcours_activites_pathos'] ?? [];

$nbPathos    = count($selected);
$nbActivites = count($finalRecos);
$nbCI        = count($parcoursCI);

// ── URLs ──────────────────────────────────────────────────────────────────
$rapportUrl = 'rapport.php?' . http_build_query(['pathologies' => $selected]);
$indexUrl   = 'index.php?'   . http_build_query(['pathologies' => $selected]);

// Sérialiser données pour JS
$freinsFlat  = [];
foreach ($freinsGrouped as $typeName => $freins)
    foreach ($freins as $f)
        $freinsFlat[] = $f;

$activitesJs = array_values(array_map(fn($r) => [
    'id'    => $r['activity'],
    'label' => prettyLabel($r['activity']),
    'pathos' => $r['pathoLabels'] ?? [],
    'adaptations' => array_map('prettyLabel', $r['adaptations'] ?? []),
], $finalRecos));

$jsData = json_encode([
    'freins'    => $freinsFlat,
    'activites' => $activitesJs,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Freins & Leviers — APA</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#f0f4f8;--card:#fff;--line:#e2e8f0;--text:#0f172a;--muted:#64748b;
    --accent:#3b82f6;--accent-dark:#1d4ed8;
    --ok:#059669;--ok-bg:#ecfdf5;--ok-border:#6ee7b7;
    --warn:#d97706;--warn-bg:#fffbeb;
    --danger:#b91c1c;--danger-bg:#fef2f2;--danger-border:#fca5a5;
    --radius:14px;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
.container{max-width:1400px;margin:0 auto;padding:20px 16px 80px}

/* ── Header ── */
.page-header{
    background:linear-gradient(110deg,#1e40af 0%,#3b82f6 60%,#60a5fa 100%);
    color:#fff;border-radius:var(--radius);padding:18px 28px;
    display:flex;align-items:center;gap:14px;margin-bottom:20px;
    box-shadow:0 4px 20px rgba(59,130,246,.3);
}
.page-header-icon{font-size:26px}
.page-header h1{font-size:18px;font-weight:800;letter-spacing:-.3px}
.page-header p{font-size:12px;opacity:.85;margin-top:2px}
.header-actions{margin-left:auto;display:flex;gap:8px;align-items:center}
.btn-back{background:rgba(255,255,255,.18);color:#fff;border:1.5px solid rgba(255,255,255,.4);border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:background .15s}
.btn-back:hover{background:rgba(255,255,255,.28)}
.btn-rapport{background:#fff;color:var(--accent);border:1.5px solid rgba(255,255,255,.6);border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-rapport:hover{background:var(--accent);color:#fff}

/* ── Layout 3 colonnes ── */
.main-layout{display:grid;grid-template-columns:320px 1fr 320px;gap:16px;align-items:start}

/* ── Sidebar pathologies ── */
.sidebar-pathos{position:sticky;top:16px}
.sidebar-title{font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);margin-bottom:8px;padding:0 2px}
.patho-card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:5px;box-shadow:var(--shadow);border-left:3px solid var(--accent)}
.patho-card-name{font-weight:700;font-size:13px;color:var(--text)}
.patho-pill{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;border-radius:4px;padding:1px 6px;margin-top:4px;margin-right:3px;border:1px solid}
.pill-ok{background:var(--ok-bg);color:var(--ok);border-color:var(--ok-border)}
.pill-danger{background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border)}

/* ── Colonne centrale : activités finales ── */
.section-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.section-header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line)}
.section-icon{font-size:18px;flex-shrink:0}
.section-title{font-size:16px;font-weight:800;flex:1}
.section-count{font-size:11px;font-weight:700;background:#f1f5f9;border:1px solid var(--line);border-radius:20px;padding:2px 9px;color:var(--muted)}
.section-body{padding:14px 18px}

/* Carte activité */
.act-card{
    border:1px solid var(--line);border-radius:10px;
    padding:12px 14px;margin-bottom:8px;
    transition:all .2s;
}
.act-card:last-child{margin-bottom:0}
.act-card-top{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap}
.act-name{font-size:15px;font-weight:700;flex:1}
.act-status{font-size:11px;font-weight:700;border-radius:20px;padding:3px 10px;white-space:nowrap;flex-shrink:0}

/* États des activités */
.act-compatible{
    background:#f0fdf8;border-color:#d1fae5;border-left:4px solid var(--ok);
}
.act-compatible .act-name{color:#064e3b}
.act-compatible .act-status{background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok-border)}

.act-supported{
    background:#fffbeb;border-color:#fde68a;border-left:4px solid var(--warn);
}
.act-supported .act-name{color:#78350f}
.act-supported .act-status{background:var(--warn-bg);color:var(--warn);border:1px solid #fde68a}

.act-neutral{
    background:#ffffff;border-color:var(--line);border-left:4px solid #cbd5e1;
}
.act-neutral .act-name{color:var(--text)}
.act-neutral .act-status{background:#f1f5f9;color:var(--muted);border:1px solid var(--line)}

/* Leviers compatibles sur la carte (cliquables par le praticien) */
.act-leviers{display:flex;flex-wrap:wrap;gap:5px;margin-top:9px}
.act-levier-chip{
    font-size:11px;font-weight:700;
    background:#ffffff;color:#065f46;
    border:1.5px solid #a7f3d0;border-radius:6px;padding:4px 10px;
    display:inline-flex;align-items:center;gap:4px;
    cursor:pointer;
    transition:all .15s ease;
    user-select:none;
}
.act-levier-chip:hover{
    background:#ecfdf5;
    border-color:#6ee7b7;
    transform:translateY(-1px);
    box-shadow:0 2px 4px rgba(5,150,105,.1);
}
/* État sélectionné par le praticien — vert plein avec coche */
.act-levier-chip.selected{
    background:#10b981;color:#ffffff;
    border-color:#059669;
    box-shadow:0 2px 6px rgba(5,150,105,.3);
}
.act-levier-chip.selected:hover{
    background:#059669;
}
/* Indication visuelle "✓" / "+" devant le texte */
.act-levier-chip::before{
    content:"+";
    font-weight:900;
    font-size:13px;
    margin-right:2px;
}
.act-levier-chip.selected::before{
    content:"✓";
}
/* Style spécial pour les leviers très pertinents (cochés par ≥2 freins) */
.act-levier-chip.levier-chip-common{
    border-color:#10b981;
    border-width:2px;
}
.act-levier-chip.levier-chip-common:not(.selected){
    background:#f0fdf4;
}
.act-eapa-chip{
    font-size:10px;font-weight:600;
    background:#fff7ed;color:#c2410c;
    border:1px solid #fed7aa;border-radius:4px;padding:2px 7px;
}
.act-patho-tags{display:flex;flex-wrap:wrap;gap:3px;margin-top:4px}
.act-patho-tag{font-size:10px;font-weight:600;background:#eff6ff;color:var(--accent-dark);border:1px solid #bfdbfe;border-radius:4px;padding:1px 6px}

/* État vide */
.empty-state{text-align:center;padding:32px;color:var(--muted);font-size:13px;font-style:italic}

/* ── Colonne droite : freins à cocher ── */
.freins-col{position:sticky;top:16px}
.freins-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.freins-header{
    display:flex;align-items:center;gap:10px;
    padding:14px 18px;border-bottom:1px solid var(--line);
    background:linear-gradient(90deg,#fafafa,#fff);
}
.freins-title{font-size:15px;font-weight:800;flex:1}
.freins-reset{font-size:11px;font-weight:600;color:var(--muted);background:none;border:1px solid var(--line);border-radius:6px;padding:3px 9px;cursor:pointer;font-family:inherit;transition:all .15s}
.freins-reset:hover{background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border)}

.freins-body{padding:12px 16px;max-height:70vh;overflow-y:auto}
.frein-type-block{margin-bottom:14px}
.frein-type-block:last-child{margin-bottom:0}
.frein-type-header{
    display:flex;align-items:center;gap:6px;
    font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
    color:var(--muted);margin-bottom:6px;padding-bottom:5px;
    border-bottom:1px solid var(--line);
}
.frein-type-icon{font-size:13px}

.frein-cb-row{
    display:flex;align-items:center;gap:8px;
    padding:5px 6px;border-radius:6px;cursor:pointer;
    border:1px solid transparent;transition:background .1s;
    margin-bottom:3px;
}
.frein-cb-row:hover{background:#f0f7ff;border-color:#bfdbfe}
.frein-cb-row.checked{background:var(--ok-bg);border-color:var(--ok-border)}
.frein-cb-row input[type=checkbox]{
    width:14px;height:14px;cursor:pointer;
    accent-color:var(--ok);flex-shrink:0;
}
.frein-cb-text{font-size:12px;font-weight:500;color:var(--text);flex:1;line-height:1.3}
.frein-cb-row.checked .frein-cb-text{font-weight:700;color:var(--ok)}
.frein-lev-count{font-size:9px;font-weight:700;background:#eff6ff;color:var(--accent-dark);border:1px solid #bfdbfe;border-radius:8px;padding:1px 5px}
.frein-no-lev{font-size:9px;color:#e2e8f0}

/* Badge compteur freins cochés */
.freins-count-badge{
    font-size:11px;font-weight:700;background:var(--ok);color:#fff;
    border-radius:20px;padding:2px 8px;
}

/* ── Panneau leviers communs (pleine largeur, sous les 3 colonnes) ── */
.leviers-panel{
    grid-column:1 / -1;
    background:var(--card);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow);
    overflow:hidden;
    display:none;
}
.leviers-panel.visible{display:block}
.leviers-panel-header{
    display:flex;align-items:center;gap:10px;
    padding:12px 18px;
    background:linear-gradient(90deg,var(--ok-bg),#fff);
    border-bottom:1px solid var(--ok-border);
}
.leviers-panel-title{font-size:14px;font-weight:800;color:var(--ok);flex:1}
.leviers-panel-count{font-size:11px;font-weight:700;background:var(--ok);color:#fff;border-radius:20px;padding:2px 8px}
.leviers-panel-body{padding:14px 18px;display:flex;flex-wrap:wrap;gap:6px}
.levier-chip-final{
    display:inline-flex;align-items:center;gap:5px;
    font-size:12px;font-weight:600;
    background:var(--ok-bg);color:var(--ok);
    border:1px solid var(--ok-border);border-radius:6px;padding:4px 10px;
    animation:chipIn .15s ease;
}
.levier-chip-common{
    background:#ecfdf5;border-color:#10b981;
    box-shadow:0 1px 3px rgba(16,185,129,.1);
}
.levier-common-badge{font-size:9px;background:#d1fae5;color:#065f46;border-radius:4px;padding:1px 4px}
@keyframes chipIn{from{opacity:0;transform:translateY(-3px)}to{opacity:1;transform:translateY(0)}}

/* ══════════════════════════════════════════════════════════════════════
   AJOUTS REFONTE — Stepper, bouton retour, synthèse (cohérence patient.php)
   ══════════════════════════════════════════════════════════════════════ */

/* ── Stepper 5 étapes ─────────────────────────────────────────────────── */
.stepper-bar{background:linear-gradient(135deg,#1d4ed8,#4b8df8);
             border-radius:18px;padding:20px 24px;margin-bottom:24px;
             box-shadow:0 10px 24px rgba(37,99,235,.18)}
.stepper{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
.step{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;
      background:rgba(255,255,255,.18);color:#fff;font-size:13px;font-weight:600;
      text-decoration:none;transition:.15s}
.step-done{background:rgba(255,255,255,.28);opacity:.92}
.step-done:hover{background:rgba(255,255,255,.38);opacity:1}
.step-current{background:#fff;color:#1d4ed8;
              box-shadow:0 4px 12px rgba(0,0,0,.15);transform:scale(1.06)}
.step-todo{opacity:.55}
.step-num{width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.32);
          display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px}
.step-current .step-num{background:#2563eb;color:#fff}
.step-done .step-num{background:rgba(255,255,255,.5);color:#1e40af}
.step-sep{color:rgba(255,255,255,.6);font-size:14px;margin:0 2px}

/* ── Bouton flottant Retour ──────────────────────────────────────────── */
.back-fab{position:fixed;top:30px;left:24px;z-index:50;
          display:inline-flex;align-items:center;gap:8px;
          background:#fff;border:1px solid #e5e7eb;border-radius:999px;
          padding:10px 18px 10px 14px;color:#1d4ed8;font-size:14px;font-weight:700;
          text-decoration:none;box-shadow:0 6px 16px rgba(15,23,42,.12);
          transition:.2s cubic-bezier(.4,0,.2,1)}
.back-fab:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;
                transform:translateX(-3px);
                box-shadow:0 10px 24px rgba(37,99,235,.35)}
.back-fab-arrow{font-size:18px;line-height:1;font-weight:800;transition:transform .2s}
.back-fab:hover .back-fab-arrow{transform:translateX(-2px)}
@media(max-width:700px){.back-fab{padding:10px;border-radius:50%}.back-fab-lbl{display:none}}

/* ── Synthèse colonne gauche (cohérent avec patient.php) ─────────────── */
.rx-synth{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
          padding:22px 24px;box-shadow:0 1px 3px rgba(15,23,42,.04);
          position:sticky;top:16px}
.rx-synth-head{padding-bottom:14px;margin-bottom:16px;border-bottom:1px solid #f1f5f9}
.rx-synth-title{font-size:15px;font-weight:800;color:#1e293b;letter-spacing:-0.01em}
.rx-synth-sub{font-size:12px;color:#94a3b8;margin-top:3px;font-style:italic}

/* Patient banner dans la synthèse */
.rx-patient{background:linear-gradient(135deg,#dbeafe,#eff6ff);border:1px solid #93c5fd;
            border-radius:10px;padding:10px 12px;margin-bottom:16px;
            display:flex;align-items:center;gap:10px}
.rx-patient-icon{width:32px;height:32px;border-radius:50%;background:#2563eb;color:#fff;
                  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
.rx-patient-name{font-weight:800;color:#1d4ed8;font-size:13px}
.rx-patient-meta{font-size:11px;color:#64748b;margin-top:1px}

.rx-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
.rx-stat{background:linear-gradient(135deg,#eff6ff,#dbeafe);
         border:1px solid #bfdbfe;border-radius:10px;padding:12px 6px;text-align:center}
.rx-stat-num{font-size:22px;font-weight:800;color:#1d4ed8;line-height:1}
.rx-stat-lbl{font-size:9px;font-weight:600;color:#475569;text-transform:uppercase;
              letter-spacing:.4px;margin-top:4px}
.rx-stat-warn{background:linear-gradient(135deg,#fef2f2,#fee2e2);border-color:#fca5a5}
.rx-stat-warn .rx-stat-num{color:#b91c1c}
.rx-stat-ok{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#a7f3d0}
.rx-stat-ok .rx-stat-num{color:#047857}
.rx-stat-ok .rx-stat-lbl{color:#065f46}

.rx-block{margin-bottom:16px}
.rx-block-title{font-size:11px;font-weight:800;color:#475569;
                 text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.rx-tags{display:flex;flex-wrap:wrap;gap:6px}
.rx-tag{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;
        font-weight:600;border:1px solid}
.rx-tag-blue{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}

.rx-list{margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:4px}
.rx-list li{padding:6px 10px;background:#f8fafc;border-radius:7px;
             font-size:12px;color:#334155;font-weight:500;
             border-left:3px solid #10b981;
             display:flex;align-items:center;justify-content:space-between;gap:8px}
.rx-list li strong{color:#1e293b;font-weight:600}
.rx-reco-pathos{font-size:10px;color:#047857;font-weight:600;
                 font-style:italic;white-space:nowrap;flex-shrink:0}

/* ── Nouveau layout 3 colonnes ajusté pour synthèse plus large ───────── */
.main-layout-v2{display:grid;grid-template-columns:300px 1fr 320px;gap:16px;align-items:start}
@media(max-width:1100px){
    .main-layout-v2{grid-template-columns:1fr 1fr}
    .main-layout-v2 > .rx-synth{display:none}
}
@media(max-width:700px){
    .main-layout-v2{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- Bouton flottant retour vers patient.php (étape 3) -->
<a href="patient.php?from=rapport" class="back-fab" title="Revenir à l'étape Patient">
    <span class="back-fab-arrow">←</span>
    <span class="back-fab-lbl">Retour</span>
</a>

<div class="container">

<!-- ── Stepper 5 étapes (étape 4 active) ── -->
<div class="stepper-bar">
    <div class="stepper">
        <?php
        $steps = [
            ['Pathologies',     'index.php',                  'done'],
            ['Recommandations', $rapportUrl,                  'done'],
            ['Patient',         'patient.php?from=rapport',   'done'],
            ['Freins/Leviers',  '#',                          'current'],
            ['Résumé IA',       '#',                          'todo'],
        ];
        $i = 1;
        foreach ($steps as $s):
            [$lbl, $url, $st] = $s;
        ?>
            <?php if ($st === 'done'): ?>
                <a class="step step-done" href="<?= h($url) ?>">
                    <span class="step-num">✓</span><span class="step-lbl"><?= h($lbl) ?></span>
                </a>
            <?php else: ?>
                <div class="step step-<?= h($st) ?>">
                    <span class="step-num"><?= $i ?></span><span class="step-lbl"><?= h($lbl) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($i < 5): ?><span class="step-sep">→</span><?php endif; ?>
            <?php $i++; endforeach; ?>
    </div>
</div>

<!-- ── Grille principale : Synthèse | Activités | Freins ── -->
<div class="main-layout-v2" id="mainLayout">

    <!-- ─── COLONNE 1 : SYNTHÈSE (identique à patient.php) ─── -->
    <aside class="rx-synth">
        <div class="rx-synth-head">
            <div class="rx-synth-title">📋 Synthèse de prescription</div>
            <div class="rx-synth-sub">À garder sous les yeux.</div>
        </div>

        <?php if ($patient): ?>
            <div class="rx-patient">
                <div class="rx-patient-icon">👤</div>
                <div>
                    <div class="rx-patient-name"><?= h($patient['fullname']) ?></div>
                    <?php if (!empty($patient['age']) || !empty($patient['dossier'])): ?>
                        <div class="rx-patient-meta">
                            <?php if (!empty($patient['age'])): ?><?= h((string)$patient['age']) ?> ans<?php endif; ?>
                            <?php if (!empty($patient['dossier'])): ?> · <?= h($patient['dossier']) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($selected)): ?>
            <div class="rx-block">
                <div class="rx-block-title">🩺 Pathologies</div>
                <div class="rx-tags">
                    <?php foreach ($selected as $uri): ?>
                        <span class="rx-tag rx-tag-blue"><?= h($pathologyLabels[$uri]) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="rx-stats">
            <div class="rx-stat">
                <div class="rx-stat-num"><?= $nbPathos ?></div>
                <div class="rx-stat-lbl">pathologie<?= $nbPathos > 1 ? 's' : '' ?></div>
            </div>
            <div class="rx-stat rx-stat-ok">
                <div class="rx-stat-num"><?= $nbActivites ?></div>
                <div class="rx-stat-lbl">activités reco.</div>
            </div>
            <div class="rx-stat<?= $nbCI > 0 ? ' rx-stat-warn' : '' ?>">
                <div class="rx-stat-num"><?= $nbCI ?></div>
                <div class="rx-stat-lbl">contre-ind.</div>
            </div>
        </div>

        <?php if (!empty($finalRecos)): ?>
            <div class="rx-block">
                <div class="rx-block-title">✅ Activités recommandées</div>
                <ul class="rx-list">
                    <?php foreach ($finalRecos as $item):
                        $actLbl = prettyLabel($item['activity']);
                        $actUri = NS . $item['activity'];
                        $pathosReco = $item['pathoLabels'] ?? ($parcoursActivitesPathos[$actUri] ?? []);
                    ?>
                        <li>
                            <strong><?= h($actLbl) ?></strong>
                            <?php if (!empty($pathosReco)): ?>
                                <span class="rx-reco-pathos"><?= h(implode(', ', $pathosReco)) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </aside>

    <!-- ── Colonne 2 : activités finales ── -->
    <div>
        <div class="section-card">
            <div class="section-header" style="border-left:4px solid var(--ok);background:linear-gradient(90deg,#f0fdf4,#fff)">
                <span class="section-title" style="color:#064e3b">Activités finales adaptées</span>
                <span class="section-count" id="actCount" style="background:var(--ok-bg);color:var(--ok);border-color:var(--ok-border)"><?= count($finalRecos) ?></span>
            </div>
            <div class="section-body">
                <?php if (empty($finalRecos)): ?>
                    <div class="empty-state">Aucune activité disponible après filtrage des contre-indications.</div>
                <?php else: ?>
                    <p style="font-size:12px;color:var(--muted);margin-bottom:12px;font-style:italic">
                        Cochez les freins du patient à droite — les activités s'adaptent en temps réel.
                    </p>
                    <div id="activitesContainer">
                        <?php foreach ($finalRecos as $item): ?>
                            <div class="act-card act-neutral" id="act-<?= h($item['activity']) ?>" data-act="<?= h($item['activity']) ?>">
                                <div class="act-card-top">
                                    <div class="act-name"><?= h(prettyLabel($item['activity'])) ?></div>
                                    <span class="act-status" id="status-<?= h($item['activity']) ?>">—</span>
                                </div>
                                <?php if (!empty($item['adaptations'])): ?>
                                    <div class="act-leviers" style="margin-top:6px">
                                        <span class="act-eapa-chip"><span style="text-transform:uppercase;letter-spacing:.5px">Suggestion EAPA</span> : <?= h(implode(' — ', array_map('prettyLabel', $item['adaptations']))) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (count($selected) > 1 && !empty($item['pathoLabels'])): ?>
                                    <div class="act-patho-tags">
                                        <?php foreach ($item['pathoLabels'] as $pl): ?>
                                            <span class="act-patho-tag"><?= h($pl) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="act-leviers" id="leviers-<?= h($item['activity']) ?>"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Colonne 3 : freins à cocher ── -->
    <div class="freins-col">
        <div class="freins-card">
            <div class="freins-header">
                <span style="font-size:18px"></span>
                <span class="freins-title">Freins du patient</span>
                <span class="freins-count-badge" id="freinsCountBadge" style="display:none">0</span>
                <button class="freins-reset" onclick="resetFreins()">↺</button>
            </div>
            <div class="freins-body" id="freinsBody">
                <?php if (empty($freinsGrouped)): ?>
                    <p style="color:var(--muted);font-size:12px;font-style:italic;padding:12px 0">Aucun frein pour le moment.</p>
                <?php else: ?>
                    <?php foreach ($freinsGrouped as $typeName => $freins): ?>
                        <?php $firstFrein = reset($freins); ?>
                        <div class="frein-type-block">
                            <div class="frein-type-header">
                                <span class="frein-type-icon"><?= h($firstFrein['typeIcon']) ?></span>
                                <span><?= h($typeName) ?></span>
                            </div>
                            <?php foreach ($freins as $frein): ?>
                                <label class="frein-cb-row" id="row-<?= h($frein['id']) ?>">
                                    <input type="checkbox"
                                           class="frein-cb"
                                           value="<?= h($frein['id']) ?>"
                                           onchange="onFreinChange(this)">
                                    <span class="frein-cb-text"><?= h($frein['label']) ?></span>
                                    <?php if (!empty($frein['leviers'])): ?>
                                        <span class="frein-lev-count"><?= count($frein['leviers']) ?></span>
                                    <?php else: ?>
                                        <span class="frein-no-lev">–</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Panneau leviers communs (pleine largeur) ── -->
    <div class="leviers-panel" id="leviersPanel">
        <div class="leviers-panel-header">
            <span style="font-size:16px">✓</span>
            <span class="leviers-panel-title">Leviers d'action</span>
            <span class="leviers-panel-count" id="leviersCount">0</span>
        </div>
        <div style="padding:10px 18px 0;font-size:12px;color:#475569;line-height:1.4">
            <strong> Cliquez sur les leviers</strong> que vous jugez adéquats pour ce patient.
            Seuls les leviers <strong style="color:#10b981">sélectionnés</strong> seront enregistrés dans la prescription.
        </div>
        <div class="leviers-panel-body" id="leviersList"></div>
    </div>

</div><!-- /main-layout -->
</div><!-- /container -->

<script>
// ── Données injectées depuis PHP ──────────────────────────────────────────
const DATA = <?= $jsData ?>;
const FREINS    = DATA.freins;      // [{id, label, typeLabel, typeIcon, leviers:[]}]
const ACTIVITES = DATA.activites;   // [{id, label, pathos:[], adaptations:[]}]

// Index : freinId → leviers[]
const freinLeviers = {};
FREINS.forEach(f => { freinLeviers[f.id] = f.leviers || []; });

// État
const checked = new Set();           // freins cochés par le praticien
const selectedLeviers = new Set();   // leviers sélectionnés par le praticien (clic sur les chips)

// ── Fonction : sélectionner/désélectionner un levier au clic ──────────────
function toggleLevier(levierLabel, chipElement) {
    if (selectedLeviers.has(levierLabel)) {
        selectedLeviers.delete(levierLabel);
        chipElement.classList.remove('selected');
    } else {
        selectedLeviers.add(levierLabel);
        chipElement.classList.add('selected');
    }
    // Met à jour aussi les autres chips ayant le même libellé (s'il y a doublons sur plusieurs activités)
    document.querySelectorAll('.act-levier-chip').forEach(c => {
        if (c.dataset.levierName === levierLabel) {
            if (selectedLeviers.has(levierLabel)) c.classList.add('selected');
            else c.classList.remove('selected');
        }
    });
    // Met à jour le compteur des leviers sélectionnés (s'il existe)
    updateLeviersSelectionnesCount();
}

function updateLeviersSelectionnesCount() {
    const badge = document.getElementById('leviersSelectionnesBadge');
    if (badge) {
        badge.textContent = selectedLeviers.size;
        badge.style.display = selectedLeviers.size > 0 ? '' : 'none';
    }
}

function onFreinChange(cb) {
    const id  = cb.value;
    const row = document.getElementById('row-' + id);
    if (cb.checked) { checked.add(id);    row.classList.add('checked'); }
    else            { checked.delete(id); row.classList.remove('checked'); }
    update();
}

function resetFreins() {
    checked.clear();
    selectedLeviers.clear();  // on remet aussi les leviers à zéro
    document.querySelectorAll('.frein-cb').forEach(cb => {
        cb.checked = false;
        document.getElementById('row-' + cb.value)?.classList.remove('checked');
    });
    update();
}

function update() {
    const n = checked.size;

    // Badge compteur freins
    const badge = document.getElementById('freinsCountBadge');
    badge.textContent = n;
    badge.style.display = n > 0 ? 'inline-flex' : 'none';

    // Collecte tous les leviers des freins cochés (avec fréquence)
    const levierFreq = {}; // levier => nb freins qui l'ont
    checked.forEach(fid => {
        (freinLeviers[fid] || []).forEach(lev => {
            levierFreq[lev] = (levierFreq[lev] || 0) + 1;
        });
    });
    const allLeviers = new Set(Object.keys(levierFreq));

    // ── Mettre à jour chaque activité ──────────────────────────────────────
    ACTIVITES.forEach(act => {
        const card   = document.getElementById('act-' + act.id);
        const status = document.getElementById('status-' + act.id);
        const levBox = document.getElementById('leviers-' + act.id);

        if (!card) return;
        card.className = 'act-card';
        levBox.innerHTML = '';
        // Reset des styles inline éventuels du status (cas où on a coché puis décoché)
        status.style.display = '';
        status.style.background = '';
        status.style.color = '';
        status.style.border = '';

        if (n === 0) {
            // Aucun frein coché → état neutre
            card.classList.add('act-neutral');
            status.textContent = '—';
            return;
        }

        // Leviers de cette activité qui correspondent aux leviers collectés
        // On cherche dans le nom de l'activité si un levier la mentionne
        // (heuristique : si le levier contient le nom de l'activité ou vice-versa)
        const actNameLower = act.label.toLowerCase().replace(/\s+/g, '');
        const compatibleLeviers = [...allLeviers].filter(lev => {
            const levLower = lev.toLowerCase().replace(/\s+/g, '');
            return levLower.includes(actNameLower) || actNameLower.includes(levLower) || true;
            // Pour l'instant on montre tous les leviers (aucun lien act<->lev dans l'ontologie)
        });

        // Tous les leviers s'appliquent à toutes les activités (pas de lien dans l'ontologie)
        // On garde la carte neutre, seuls les chips leviers ressortent en vert
        if (allLeviers.size > 0) {
            card.classList.add('act-neutral');   // Pas de fond vert sur toute la carte
            status.textContent = '✓ Leviers disponibles';
            status.style.background = '#ecfdf5';
            status.style.color = '#047857';
            status.style.border = '1px solid #6ee7b7';

            // Afficher TOUS les leviers (pas de limite, pas de "+X leviers")
            const sorted = Object.entries(levierFreq)
                .sort(([,a],[,b]) => b - a);  // tri par fréquence (les plus communs en premier)

            sorted.forEach(([lev, freq]) => {
                const chip = document.createElement('span');
                chip.className = 'act-levier-chip';
                chip.dataset.levierName = lev;
                chip.textContent = prettyLabel(lev);
                if (freq > 1) chip.classList.add('levier-chip-common');
                // Si ce levier était déjà sélectionné par le praticien, on le marque
                if (selectedLeviers.has(lev)) chip.classList.add('selected');
                // Rendre cliquable
                chip.addEventListener('click', () => toggleLevier(lev, chip));
                chip.title = 'Cliquez pour sélectionner/désélectionner ce levier';
                levBox.appendChild(chip);
            });
        } else {
            card.classList.add('act-neutral');
            status.textContent = '';
            status.style.display = 'none';
        }
    });

    // ── Panneau leviers pleine largeur ────────────────────────────────────
    const panel     = document.getElementById('leviersPanel');
    const levList   = document.getElementById('leviersList');
    const levCount  = document.getElementById('leviersCount');

    if (allLeviers.size === 0) {
        panel.classList.remove('visible');
        return;
    }

    panel.classList.add('visible');
    levList.innerHTML = '';
    const totalLeviers = allLeviers.size;
    levCount.innerHTML = totalLeviers + ' disponible' + (totalLeviers > 1 ? 's' : '') +
                         ' · <span id="leviersSelectionnesBadge" style="background:#10b981;color:#fff;padding:1px 8px;border-radius:12px;font-size:10px;margin-left:4px;' +
                         (selectedLeviers.size === 0 ? 'display:none' : '') + '">' +
                         selectedLeviers.size + '</span> sélectionné' + (selectedLeviers.size > 1 ? 's' : '');

    // Trier : communs (≥2) en premier, puis alphabétique
    const sorted = Object.entries(levierFreq)
        .sort(([la, a], [lb, b]) => b - a || la.localeCompare(lb));

    let lastSection = null;
    sorted.forEach(([lev, freq]) => {
        const section = freq > 1 ? 'communs' : 'specifiques';
        if (section !== lastSection) {
            const lbl = document.createElement('div');
            lbl.style.cssText = 'width:100%;font-size:9px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-top:6px;margin-bottom:4px';
            lbl.textContent = freq > 1 ? '★ Leviers communs (à privilégier)' : 'Leviers spécifiques';
            levList.appendChild(lbl);
            lastSection = section;
        }
        const chip = document.createElement('span');
        chip.className = 'act-levier-chip' + (freq > 1 ? ' levier-chip-common' : '');
        chip.dataset.levierName = lev;
        chip.innerHTML = prettyLabel(lev) +
            (freq > 1 ? ' <span style="background:rgba(0,0,0,.08);padding:1px 5px;border-radius:8px;font-size:9px;margin-left:2px">' + freq + '</span>' : '');
        if (selectedLeviers.has(lev)) chip.classList.add('selected');
        chip.addEventListener('click', () => toggleLevier(lev, chip));
        chip.title = 'Cliquez pour sélectionner/désélectionner ce levier';
        levList.appendChild(chip);
    });
}

function prettyLabel(name) {
    return name.replace(/_/g, ' ').replace(/([A-Z])/g, ' $1').trim();
}

// Init
update();
</script>

<!-- ─────────────────────────────────────────────────────────────────── -->
<!--  MODULE 2 : ENREGISTREMENT DE LA PRESCRIPTION                       -->
<!-- ─────────────────────────────────────────────────────────────────── -->
<div id="continuer-section" style="max-width:1360px;margin:30px auto 40px;padding:0 20px">
    <div style="background:linear-gradient(135deg,#dbeafe,#eff6ff);border:1.5px solid #93c5fd;
                border-radius:18px;padding:24px 28px;display:flex;justify-content:space-between;
                align-items:center;gap:20px;flex-wrap:wrap;box-shadow:0 6px 16px rgba(37,99,235,.08)">
        <div style="flex:1;min-width:280px">
            <h3 style="margin:0 0 6px;color:#1d4ed8;font-size:20px">💾 Enregistrer la prescription</h3>
            <p style="margin:0;color:#475569;font-size:14px;line-height:1.5">
                Cliquez pour enregistrer définitivement la prescription dans le dossier du patient.
                Vous accéderez ensuite au formulaire de synthèse officiel.
            </p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" onclick="enregistrerPrescription(true)"
                    style="background:#fff;color:#1d4ed8;border:1.5px solid #93c5fd;
                           border-radius:12px;padding:12px 22px;font-size:14px;font-weight:700;
                           cursor:pointer;transition:.15s">
                ⏭ Enregistrer sans freins/leviers
            </button>
            <button type="button" id="btn-enregistrer-final" onclick="enregistrerPrescription(false)"
                    style="background:#2563eb;color:#fff;border:none;border-radius:12px;
                           padding:14px 26px;font-size:15px;font-weight:700;cursor:pointer;
                           box-shadow:0 4px 12px rgba(37,99,235,.3);transition:.15s">
                💾 Enregistrer la prescription
            </button>
        </div>
    </div>
    <div id="save-feedback" style="display:none;margin-top:18px"></div>
</div>

<script>
async function enregistrerPrescription(skip = false) {
    const btnFinal = document.getElementById('btn-enregistrer-final');
    const feedback = document.getElementById('save-feedback');

    // Confirmation gentille si on coche des freins mais aucun levier
    if (!skip && checked.size > 0 && selectedLeviers.size === 0) {
        const ok = confirm(
            'Vous avez identifié ' + checked.size + ' frein(s) mais n\'avez sélectionné aucun levier.\n\n' +
            'Voulez-vous enregistrer quand même ?\n' +
            'Astuce : cliquez sur les chips verts "+ Nom du levier" pour les sélectionner.'
        );
        if (!ok) return;
    }

    // 1) Sauvegarder les freins/leviers cochés en session
    if (!skip) {
        const freinsChecked = [...document.querySelectorAll('.frein-cb:checked')]
                                .map(el => el.value);

        //  Envoyer UNIQUEMENT les leviers que le praticien a sélectionnés
        // (ceux marqués comme .selected sur les chips)
        const leviersChecked = [...selectedLeviers];

        const fd = new FormData();
        freinsChecked.forEach(id => fd.append('freins[]', id));
        leviersChecked.forEach(id => fd.append('leviers[]', id));
        try {
            await fetch('freins_save.php', { method: 'POST', body: fd });
        } catch (e) { console.warn('Sauvegarde freins/leviers échouée :', e); }
    } else {
        try { await fetch('freins_save.php?skip=1', { method: 'POST' }); }
        catch (e) {}
    }

    // 2) Enregistrer la prescription dans Fuseki
    btnFinal.disabled = true;
    btnFinal.style.opacity = '0.7';
    btnFinal.textContent = '⏳ Enregistrement...';

    try {
        const res = await fetch('enregistrer_prescription.php', { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            // 3) Affichage d'un message de succès puis redirection vers le détail
            const prescriptionId = data.prescription_fragment;
            feedback.style.display = 'block';
            feedback.innerHTML = `
                <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);
                            border:2px solid #6ee7b7;border-radius:18px;padding:24px 28px;
                            color:#065f46;box-shadow:0 8px 24px rgba(5,150,105,.12);
                            animation:slideDown .4s ease;text-align:center">
                    <div style="font-size:48px;line-height:1;margin-bottom:8px">✅</div>
                    <h3 style="margin:0 0 6px;color:#047857;font-size:20px;font-weight:800">
                        Prescription enregistrée avec succès !
                    </h3>
                    <p style="margin:0;color:#065f46;font-size:13px">
                        ${data.nb_pathologies} pathologie(s) ·
                        ${data.nb_activites} activité(s) ·
                        ${data.nb_freins} frein(s) ·
                        ${data.nb_leviers} levier(s)
                    </p>
                    <p style="margin:14px 0 0;color:#065f46;font-size:13px;font-style:italic">
                        Redirection vers le détail de la prescription...
                    </p>
                </div>
                <style>
                    @keyframes slideDown {
                        from { opacity:0; transform:translateY(-10px); }
                        to { opacity:1; transform:translateY(0); }
                    }
                </style>
            `;
            btnFinal.style.display = 'none';
            const btnSkipEl = document.querySelector('[onclick*="enregistrerPrescription(true)"]');
            if (btnSkipEl) btnSkipEl.style.display = 'none';
            feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Redirection automatique vers prescription_detail.php après 1.5s
            setTimeout(() => {
                window.location.href = 'prescription_detail.php?id=' + encodeURIComponent(prescriptionId);
            }, 1500);
        } else {
            feedback.style.display = 'block';
            feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;
                                              padding:18px 22px;color:#7f1d1d;font-size:14px">
                <strong>❌ Échec :</strong> ${data.error || 'erreur inconnue'}</div>`;
            btnFinal.disabled = false;
            btnFinal.style.opacity = '1';
            btnFinal.textContent = '💾 Réessayer';
        }
    } catch (e) {
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;
                                          padding:18px 22px;color:#7f1d1d;font-size:14px">
            <strong>❌ Erreur réseau :</strong> ${e.message}</div>`;
        btnFinal.disabled = false;
        btnFinal.style.opacity = '1';
        btnFinal.textContent = '💾 Réessayer';
    }
}
</script>

</body>
</html>
