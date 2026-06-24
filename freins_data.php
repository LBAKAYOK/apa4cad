<?php
/**
 * APA4CAD - Module 2 : Logique métier de l'étape Freins/Leviers
 *
 * Extrait de freins.php pour pouvoir être inclus dans patient.php
 * (wizard SPA fusionné étape 3 + étape 4).
 *
 * Pré-requis : avoir $selected (tableau d'URIs de pathologies) avant l'include.
 *
 * Variables publiées :
 *   $freinsGrouped     [Type => [ ['id','label',...,'leviers'=>[...]], ... ]]
 *   $freinsFlat        liste plate des freins (pour le JS)
 *   $activitesJs       liste d'activités finales (id, label, pathos[], adaptations[])
 *   $finalRecos        [['activity'=>..., 'adaptations'=>[...], 'pathoLabels'=>[...]] ]
 *   $jsData            JSON ready pour le JS (freins + activites)
 *   $rapportUrl, $indexUrl
 */

if (!defined('FREINS_NS')) {
    define('FREINS_NS', 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#');
}
if (!defined('FREINS_FUSEKI')) {
    define('FREINS_FUSEKI', 'https://fuseki-apa4cad.onrender.com/mononto/query');
}

// ─── Helpers (préfixés F_ pour éviter les collisions avec patient.php) ───
if (!function_exists('F_sparql')) {
    function F_sparql(string $query): array {
        $url = FREINS_FUSEKI . '?query=' . urlencode($query) . '&output=json';
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'header' => "Accept: application/sparql-results+json\r\n",
            'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) return ['ok' => false, 'bindings' => []];
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['results']['bindings']))
            return ['ok' => false, 'bindings' => []];
        return ['ok' => true, 'bindings' => $data['results']['bindings']];
    }
}
if (!function_exists('F_localName')) {
    function F_localName(string $uri): string {
        if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
        if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
        return $uri;
    }
}
if (!function_exists('F_prettyLabel')) {
    function F_prettyLabel(string $name): string {
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        return trim((string)$name);
    }
}
if (!function_exists('F_categoryTitle')) {
    function F_categoryTitle(string $local): string {
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
            default => F_prettyLabel($local),
        };
    }
}

// ─── Chargement des recommandations pour une pathologie ───
function F_loadRecommendations(string $pathologyUri): array {
    $query = '
PREFIX ex:   <' . FREINS_NS . '>
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
                 owl:someValuesFrom ?inter .
    ?inter owl:intersectionOf ?innerList .
    ?innerList rdf:rest*/rdf:first ?elt .
    {
      ?elt a owl:Class .
      FILTER(isIRI(?elt))
      BIND(STRAFTER(STR(?elt), "#") AS ?nomActivite)
    }
    UNION
    {
      ?elt owl:onProperty ex:adaptee ;
           owl:someValuesFrom ?adap .
      FILTER(isIRI(?adap))
      BIND(STRAFTER(STR(?adap), "#") AS ?adaptation)
    }
  }
}';
    $result = F_sparql($query);
    if (!$result['ok']) return [];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $act = $row['nomActivite']['value'] ?? '';
        $ada = $row['adaptation']['value'] ?? '';
        if ($act !== '') {
            if (!isset($items[$act])) $items[$act] = ['activity' => $act, 'adaptations' => []];
        }
        if ($ada !== '') {
            // L'adaptation s'applique à toutes les activités courantes (héritage SPARQL)
            foreach ($items as $k => $it)
                if (!in_array($ada, $items[$k]['adaptations'], true))
                    $items[$k]['adaptations'][] = $ada;
        }
    }
    return array_values($items);
}

// ─── Chargement des contre-indications pour une pathologie ───
function F_loadContraindications(string $pathologyUri): array {
    $query = '
PREFIX ex:   <' . FREINS_NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?nomActivite
WHERE {
  VALUES ?patho { <' . $pathologyUri . '> }
  ?patho rdfs:subClassOf+ ?super .
  ?super rdfs:subClassOf ?expr .
  ?expr owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?restriction .
  ?restriction owl:onProperty ex:aPourContreIndication ;
               owl:someValuesFrom ?cible .
  FILTER(isIRI(?cible))
  BIND(STRAFTER(STR(?cible), "#") AS ?nomActivite)
}';
    $result = F_sparql($query);
    if (!$result['ok']) return [];
    $out = [];
    foreach ($result['bindings'] as $row) {
        $a = $row['nomActivite']['value'] ?? '';
        if ($a !== '' && !in_array($a, $out, true)) $out[] = $a;
    }
    return $out;
}

// ─── Détection des CI globales (classes parentes bloquant tout) ───
function F_loadBlockedByGenericCI(string $ciName): string {
    $genericRoots = ['ActivitePhysique'];
    if (in_array($ciName, $genericRoots, true)) return 'ALL';
    return 'NONE';
}

// ─── Chargement des freins et leviers ───
function F_loadFreinsAndLeviers(): array {
    $ns = FREINS_NS;
    $query = '
PREFIX ex:   <' . $ns . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?frein ?freinType ?levier
WHERE {
  ?frein rdfs:subClassOf ?anon .
  ?anon owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?freinType .
  FILTER(isIRI(?freinType))
  FILTER(STRSTARTS(STR(?freinType), "' . $ns . '"))
  ?freinType rdfs:subClassOf ex:Frein .
  FILTER(?freinType != ex:Frein)
  FILTER(STRSTARTS(STR(?frein), "' . $ns . '"))
  FILTER(?frein != ?freinType)
  OPTIONAL {
    ?list rdf:rest*/rdf:first ?restr .
    ?restr owl:onProperty ex:aPourLevier .
    {
      ?restr owl:someValuesFrom ?lev .
      FILTER(isIRI(?lev)) FILTER(STRSTARTS(STR(?lev), "' . $ns . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
    UNION
    {
      ?restr owl:someValuesFrom ?union . FILTER(isBlank(?union))
      ?union owl:unionOf/rdf:rest*/rdf:first ?lev .
      FILTER(isIRI(?lev)) FILTER(STRSTARTS(STR(?lev), "' . $ns . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
  }
}
ORDER BY ?freinType ?frein ?levier';
    $result = F_sparql($query);
    if (!$result['ok']) return [];

    $typeMeta = [
        'FreinPhysique'        => ['label' => 'Frein physique',        'order' => 1],
        'FreinPsychologique'   => ['label' => 'Frein psychologique',   'order' => 2],
        'FreinMotivationnel'   => ['label' => 'Frein motivationnel',   'order' => 3],
        'FreinSituationnel'    => ['label' => 'Frein situationnel',    'order' => 4],
        'FreinSocial'          => ['label' => 'Frein social',          'order' => 5],
        'FreinEnvironnemental' => ['label' => 'Frein environnemental', 'order' => 6],
    ];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $frein     = F_localName($row['frein']['value']     ?? '');
        $typeLocal = F_localName($row['freinType']['value'] ?? '');
        $levier    = $row['levier']['value'] ?? '';
        if ($frein === '' || isset($typeMeta[$frein])) continue;
        if (!isset($items[$frein])) {
            $m = $typeMeta[$typeLocal] ?? ['label' => F_prettyLabel($typeLocal), 'order' => 99];
            $items[$frein] = [
                'id'        => $frein,
                'label'     => F_prettyLabel($frein),
                'typeKey'   => $typeLocal,
                'typeLabel' => $m['label'],
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

// ════════════════════════════════════════════════════════════════════════════
// EXÉCUTION : construit toutes les variables nécessaires à partir de $selected
// ════════════════════════════════════════════════════════════════════════════

if (!isset($selected) || !is_array($selected) || empty($selected)) {
    // Aucune pathologie : on rend toutes les variables vides pour ne rien casser
    $freinsGrouped = [];
    $freinsFlat    = [];
    $finalRecos    = [];
    $activitesJs   = [];
    $jsData        = json_encode(['freins'=>[], 'activites'=>[]], JSON_UNESCAPED_UNICODE);
    $rapportUrl    = 'rapport.php';
    $indexUrl      = 'index.php';
    return;
}

// ─ Charger reco + CI pour chaque patho sélectionnée ─
$pathologyLabelsF = [];
$recoByPathoF     = [];
$contraByPathoF   = [];
foreach ($selected as $uri) {
    $pathologyLabelsF[$uri] = F_categoryTitle(F_localName($uri));
    $recoByPathoF[$uri]     = F_loadRecommendations($uri);
    $contraByPathoF[$uri]   = F_loadContraindications($uri);
}

// ─ Déduplication recommandations ─
$seenActsF   = [];
$finalRecos  = [];
$finalContraF = [];
foreach ($selected as $uri) {
    $lbl = $pathologyLabelsF[$uri];
    foreach ($recoByPathoF[$uri] as $item) {
        $act = $item['activity'];
        if (!isset($seenActsF[$act])) {
            $seenActsF[$act]     = count($finalRecos);
            $item['pathoLabels'] = [$lbl];
            $finalRecos[]        = $item;
        } else {
            $idx = $seenActsF[$act];
            foreach ($item['adaptations'] as $adap)
                if (!in_array($adap, $finalRecos[$idx]['adaptations'], true))
                    $finalRecos[$idx]['adaptations'][] = $adap;
            if (!in_array($lbl, $finalRecos[$idx]['pathoLabels'], true))
                $finalRecos[$idx]['pathoLabels'][] = $lbl;
        }
    }
    foreach ($contraByPathoF[$uri] as $c) {
        if (!isset($finalContraF[$c])) $finalContraF[$c] = [];
        if (!in_array($lbl, $finalContraF[$c], true)) $finalContraF[$c][] = $lbl;
    }
}

// ─ Filtrage CI globales ─
$globalCIBlocksF = [];
foreach ($selected as $uri) {
    foreach ($contraByPathoF[$uri] as $c) {
        $t = F_loadBlockedByGenericCI($c);
        if ($t === 'ALL' || $t === 'PARENT')
            $globalCIBlocksF[$uri][] = ['ci' => $c, 'type' => $t, 'label' => $pathologyLabelsF[$uri]];
    }
}
if (!empty($globalCIBlocksF)) {
    $ok = [];
    foreach ($finalRecos as $item) {
        $blocked = false;
        foreach ($globalCIBlocksF as $blocks)
            foreach ($blocks as $b)
                if ($b['type'] === 'ALL') { $blocked = true; break 2; }
        if (!$blocked) $ok[] = $item;
    }
    $finalRecos = $ok;
}

// ─ Charger les freins ─
$freinsGrouped = F_loadFreinsAndLeviers();

// ─ URLs ─
$rapportUrl = 'rapport.php?' . http_build_query(['pathologies' => $selected]);
$indexUrl   = 'index.php?'   . http_build_query(['pathologies' => $selected]);

// ─ Sérialiser pour le JS ─
$freinsFlat = [];
foreach ($freinsGrouped as $typeName => $freins)
    foreach ($freins as $f)
        $freinsFlat[] = $f;

$activitesJs = array_values(array_map(fn($r) => [
    'id'    => $r['activity'],
    'label' => F_prettyLabel($r['activity']),
    'pathos' => $r['pathoLabels'] ?? [],
    'adaptations' => array_map('F_prettyLabel', $r['adaptations'] ?? []),
], $finalRecos));

$jsData = json_encode([
    'freins'    => $freinsFlat,
    'activites' => $activitesJs,
], JSON_UNESCAPED_UNICODE);
