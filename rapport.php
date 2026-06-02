<?php
declare(strict_types=1);

require_once __DIR__ . '/patient_session.php';

// ─── DÉTECTION DU MODE EXPLORATION ────────────────────────────────────────
// Si on vient depuis welcome.php avec mode=explore OU si on est déjà en session.
if (isset($_GET['mode']) && $_GET['mode'] === 'explore') {
    $_SESSION['explore_mode'] = true;
}
$EXPLORE_MODE = !empty($_SESSION['explore_mode'] ?? false);

const FUSEKI_ENDPOINT = 'http://localhost:3030/mononto/query';
const NS = 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';

// ── Fonctions utilitaires ─────────────────────────────────────────────────

function sparqlQuery(string $query): array
{
    $url = FUSEKI_ENDPOINT . '?query=' . urlencode($query) . '&output=json';
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Accept: application/sparql-results+json\r\n",
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);
    $response   = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false) return ['ok' => false, 'error' => 'Fuseki inaccessible'];
    if ($statusLine !== '' && !str_contains($statusLine, '200'))
        return ['ok' => false, 'error' => 'HTTP: ' . $statusLine];
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']['bindings']))
        return ['ok' => false, 'error' => 'Réponse invalide'];
    return ['ok' => true, 'bindings' => $data['results']['bindings']];
}

function localName(string $uri): string
{
    if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
    if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
    return $uri;
}

function prettyLabel(string $name): string
{
    $name = str_replace('_', ' ', $name);
    $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    return trim((string)$name);
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function categoryTitle(string $local): string
{
    return match ($local) {
        'AffectionDeLongueDuree'         => 'Affections de longue durée',
        'PathologieCardiaque'            => 'Pathologies cardiaques',
        'PathologieDigestive'            => 'Pathologies digestives',
        'PathologieMusculosquelettique'  => 'Pathologies musculosquelettiques',
        'PathologieRespiratoire'         => 'Pathologies respiratoires',
        'PathologieCoronarienne'         => 'Pathologies coronariennes',
        'CardiopathiesInflammatoires'    => 'Cardiopathies inflammatoires',
        'CoronaropathieChronique'        => 'Coronaropathie chronique',
        'CoronaropathieFonctionnelle'    => 'Coronaropathie fonctionnelle',
        'SyndromeCoronarienAigu'         => 'Syndrome coronarien aigu',
        'Diabete'                        => 'Diabète',
        'Arthrose'                       => 'Arthrose',
        'AngorStable'                    => 'Angor stable',
        'AngorInstable'                  => 'Angor instable',
        'CoronaropathieAsymptomatique'   => 'Coronaropathie asymptomatique',
        'IschemieMyocardiqueStable'      => 'Ischémie myocardique stable',
        'SpasmeCoronarien'               => 'Spasme coronarien',
        'InfarctusDuMyocarde'            => 'Infarctus du myocarde',
        'Endocardite'                    => 'Endocardite',
        'Myocardite'                     => 'Myocardite',
        'Pericardite'                    => 'Péricardite',
        'Cancer'                         => 'Cancer',
        'Hypertension_arterielle'        => 'Hypertension artérielle',
        'Obesite'                        => 'Obésité',
        'DT1'                            => 'Diabète de type 1',
        'DT2'                            => 'Diabète de type 2',
        'ArthroseCervicale'              => 'Arthrose cervicale',
        'ArthroseEpaule'                 => 'Arthrose de l\'épaule',
        'ArthroseGenou'                  => 'Arthrose du genou',
        'ArthroseHanche'                 => 'Arthrose de la hanche',
        'Lombalgie'                      => 'Lombalgie',
        'Menisectomie'                   => 'Méniscectomie',
        'ApneeDuSommeil'                 => 'Apnée du sommeil',
        'BronchopneumopathieChroniqueObstructive' => 'BPCO',
        'Diastasis'                      => 'Diastasis',
        'Eventration'                    => 'Éventration',
        'HernieInguinale'                => 'Hernie inguinale',
        default => prettyLabel($local),
    };
}

function modalityLabel(string $prop): string
{
    return match ($prop) {
        'aPourIntensite'             => 'Intensité',
        'aPourFrequence'             => 'Fréquence',
        'aPourFrequenceHebdomadaire' => 'Fréq. hebdo.',
        'aPourDuree'                 => 'Durée (min)',
        'aPourDureeHebdomadaire'     => 'Durée hebdo. (min)',
        'aPourDureeParEtirement'     => 'Durée par étirement (s)',
        'aPourNbRepetitions'         => 'Répétitions',
        'aPourNbSeries'              => 'Séries',
        'aPourNbExercices'           => 'Exercices',
        'aPour1RM_Bas'               => 'Charge membres inférieurs',
        'aPour1RM_Bas_min'           => 'Charge membres inférieurs',
        'aPour1RM_Bas_max'           => 'Charge membres inférieurs',
        'aPour1RM_Haut'              => 'Charge membres supérieurs',
        'aPour1RM_Haut_min'          => 'Charge membres supérieurs',
        'aPour1RM_Haut_max'          => 'Charge membres supérieurs',
        default => prettyLabel($prop),
    };
}

// Groupe d'affichage pour ordonner les modalités
function modalityGroup(string $prop): int
{
    return match ($prop) {
        'aPourIntensite'             => 1,
        'aPourFrequence',
        'aPourFrequenceHebdomadaire' => 2,
        'aPourDuree',
        'aPourDureeHebdomadaire',
        'aPourDureeParEtirement'     => 3,
        'aPourNbExercices'           => 4,
        'aPourNbSeries'              => 5,
        'aPourNbRepetitions'         => 6,
        'aPour1RM_Bas',
        'aPour1RM_Bas_min',
        'aPour1RM_Bas_max',
        'aPour1RM_Haut',
        'aPour1RM_Haut_min',
        'aPour1RM_Haut_max'          => 7,
        default => 99,
    };
}

function modalityIcon(string $prop): string
{
    return match (true) {
        str_starts_with($prop, 'aPourIntensite')       => '',
        str_starts_with($prop, 'aPourFrequence')       => '',
        str_starts_with($prop, 'aPourDuree')           => '',
        str_starts_with($prop, 'aPourNbExercices')     => '',
        str_starts_with($prop, 'aPourNbSeries')        => '',
        str_starts_with($prop, 'aPourNbRepetitions')   => '',
        str_starts_with($prop, 'aPour1RM')             => '',
        default => '📊',
    };
}
#Axi
#Extrait les activités recommandées pour une pathologie donnée, avec leurs adaptations 
// ── Chargement des données ────────────────────────────────────────────────

function loadRecommendations(string $pathologyUri): array
{
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
    FILTER(?elt != ex:Pathologie)
    FILTER(?elt != ex:ActivitePhysique)
    FILTER(?elt != ex:Adaptation)
    FILTER(?elt != ex:Frein)
    FILTER(?elt != ex:DispositifMedical)
    FILTER(?elt != ex:Equipement_de_sport)
    BIND(STRAFTER(STR(?elt), "#") AS ?nomActivite)
    OPTIONAL {
      ?list2 rdf:rest*/rdf:first ?r2 .
      ?r2 owl:onProperty ex:aPourAdaptation ;
          owl:someValuesFrom ?ad .
      BIND(STRAFTER(STR(?ad), "#") AS ?adaptation)
    }
  }
}
ORDER BY ?nomActivite';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $grouped = [];
    foreach ($result['bindings'] as $row) {
        $act  = $row['nomActivite']['value'] ?? '';
        $adap = $row['adaptation']['value'] ?? '';
        if ($act === '') continue;
        if (!isset($grouped[$act])) $grouped[$act] = ['activity' => $act, 'adaptations' => []];
        if ($adap !== '' && !in_array($adap, $grouped[$act]['adaptations'], true))
            $grouped[$act]['adaptations'][] = $adap;
    }
    return array_values($grouped);
}

function loadContraindications(string $pathologyUri): array
{
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
}
ORDER BY ?nomElement';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $v = $row['nomElement']['value'] ?? '';
        if ($v !== '') $items[$v] = $v;
    }
    return array_values($items);
}

function loadModalities(string $pathologyUri): array
{
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX xsd:  <http://www.w3.org/2001/XMLSchema#>
SELECT DISTINCT ?prop ?valueName
WHERE {
  VALUES ?patho { <' . $pathologyUri . '> }
  VALUES ?targetProp {
    ex:aPourIntensite ex:aPourFrequence ex:aPourFrequenceHebdomadaire
    ex:aPourDuree ex:aPourDureeHebdomadaire ex:aPourDureeParEtirement
    ex:aPourNbRepetitions ex:aPourNbSeries ex:aPourNbExercices
    ex:aPour1RM_Bas_min ex:aPour1RM_Bas_max
    ex:aPour1RM_Haut_min ex:aPour1RM_Haut_max
  }
  ?patho rdfs:subClassOf+ ?super .
  ?super rdfs:subClassOf ?expr .
  ?expr owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?restriction .
  ?restriction owl:onProperty ?targetProp .
  BIND(STRAFTER(STR(?targetProp), "#") AS ?prop)
  {
    ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
    ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
    { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
    UNION
    { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
  }
}
ORDER BY ?prop ?valueName';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $prop = $row['prop']['value'] ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($prop === '' || $val === '') continue;
        $items[$prop][] = $val;
    }
    foreach ($items as $prop => &$vals) {
        $vals  = array_values(array_unique($vals));
        $mins  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'min:')));
        $maxs  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'max:')));
        $rest  = array_values(array_filter($vals, fn($v) => !str_starts_with($v, 'min:') && !str_starts_with($v, 'max:')));
        if (!empty($mins) || !empty($maxs)) {
            $minVal = !empty($mins) ? substr($mins[0], 4) : null;
            $maxVal = !empty($maxs) ? substr($maxs[0], 4) : null;
            $range  = ($minVal !== null && $maxVal !== null) ? $minVal . ' – ' . $maxVal : ($minVal ?? $maxVal);
            $vals = array_merge($rest, [$range]);
        }
    }
    unset($vals);
    return $items;
}

function loadSubActivities(array $activityLocalNames): array
{
    if (empty($activityLocalNames)) return [];
    $values = implode(' ', array_map(fn($n) => 'ex:' . $n, $activityLocalNames));
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?parentName ?subAct ?equipment
WHERE {
  VALUES ?parent { ' . $values . ' }
  BIND(STRAFTER(STR(?parent), "#") AS ?parentName)
  {
    ?subAct rdfs:subClassOf+ ?parent .
    FILTER(STRSTARTS(STR(?subAct), "' . NS . '"))
  }
  UNION
  {
    ?subAct rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?parent .
    FILTER(isIRI(?parent)) FILTER(STRSTARTS(STR(?subAct), "' . NS . '"))
  }
  OPTIONAL {
    {
      ?subAct rdfs:subClassOf ?anon2 .
      ?anon2 owl:intersectionOf/rdf:rest*/rdf:first ?r .
      ?r owl:onProperty ex:aBesoinDe ; owl:someValuesFrom ?eq .
      FILTER(isIRI(?eq)) FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
      BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
    }
    UNION
    {
      ?subAct rdfs:subClassOf ?anon2 .
      ?anon2 owl:intersectionOf/rdf:rest*/rdf:first ?r .
      ?r owl:onProperty ex:aBesoinDe ; owl:someValuesFrom ?eqBlank .
      FILTER(isBlank(?eqBlank))
      ?eqBlank owl:unionOf/rdf:rest*/rdf:first ?eq .
      FILTER(isIRI(?eq)) FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
      BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
    }
  }
  FILTER NOT EXISTS { ?child rdfs:subClassOf ?subAct . FILTER(STRSTARTS(STR(?child),"' . NS . '")) }
}
ORDER BY ?parentName ?subAct';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $skip  = ['ActivitePhysique','Pathologie','Adaptation','Frein','DispositifMedical','Equipement_de_sport'];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $parent  = $row['parentName']['value'] ?? '';
        $subUri  = $row['subAct']['value']     ?? '';
        $equip   = $row['equipment']['value']  ?? '';
        if ($parent === '' || $subUri === '') continue;
        $subName = localName($subUri);
        if (in_array($subName, $skip, true)) continue;
        $items[$parent][$subName] ??= [];
        if ($equip !== '' && !in_array($equip, $skip, true))
            $items[$parent][$subName][] = $equip;
    }
    foreach ($items as &$subs) {
        foreach ($subs as &$equips) $equips = array_values(array_unique($equips));
    }
    return $items;
}

/**
 * Charge les freins et leurs leviers depuis l'ontologie.
 * Retourne : [ 'FreinClass' => ['label'=>'...', 'type'=>'...', 'leviers'=>['Levier1','Levier2']] ]
 */
function loadModalitiesForActivities(array $activityLocalNames): array
{
    if (empty($activityLocalNames)) return [];
    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX xsd:  <http://www.w3.org/2001/XMLSchema#>
SELECT DISTINCT ?prop ?valueName
WHERE {
  VALUES ?activity { ' . $values . ' }
  VALUES ?targetProp {
    ex:aPourIntensite ex:aPourFrequence ex:aPourFrequenceHebdomadaire
    ex:aPourDuree ex:aPourDureeHebdomadaire ex:aPourDureeParEtirement
    ex:aPourNbRepetitions ex:aPourNbSeries ex:aPourNbExercices
    ex:aPour1RM_Bas_min ex:aPour1RM_Bas_max
    ex:aPour1RM_Haut_min ex:aPour1RM_Haut_max
  }
  BIND(STRAFTER(STR(?targetProp), "#") AS ?prop)
  {
    ?activity rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ?targetProp .
    {
      ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION
    {
      ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION
    {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION
      { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
  UNION
  # Via héritage de classe parente
  {
    ?activity rdfs:subClassOf+ ?parent .
    ?parent rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ?targetProp .
    {
      ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION
    {
      ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION
    {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION
      { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
}
ORDER BY ?prop ?valueName';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $prop = $row['prop']['value'] ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($prop === '' || $val === '') continue;
        $items[$prop][] = $val;
    }
    // Fusionner paires min:/max: en plage "X – Y"
    foreach ($items as $prop => &$vals) {
        $vals  = array_values(array_unique($vals));
        $mins  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'min:')));
        $maxs  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'max:')));
        $rest  = array_values(array_filter($vals, fn($v) => !str_starts_with($v, 'min:') && !str_starts_with($v, 'max:')));
        if (!empty($mins) || !empty($maxs)) {
            $minV = !empty($mins) ? substr($mins[0], 4) : null;
            $maxV = !empty($maxs) ? substr($maxs[0], 4) : null;
            $range = ($minV !== null && $maxV !== null) ? $minV . ' – ' . $maxV : ($minV ?? $maxV);
            $vals = array_merge($rest, [$range]);
        }
    }
    unset($vals);
    return $items;
}

function loadModalitiesPerActivity(array $activityLocalNames, array $pathologyUris = []): array
{
    if (empty($activityLocalNames)) return [];
    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));

    // Préparer la clause VALUES pour les pathologies (pour la 3e branche UNION)
    $pathoValues = !empty($pathologyUris)
        ? 'VALUES ?patho { <' . implode('> <', $pathologyUris) . '> }'
        : '';

    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX xsd:  <http://www.w3.org/2001/XMLSchema#>
SELECT DISTINCT ?actName ?prop ?valueName
WHERE {
  VALUES ?activity { ' . $values . ' }
  BIND(STRAFTER(STR(?activity), "#") AS ?actName)
  VALUES ?targetProp {
    ex:aPourIntensite ex:aPourFrequence ex:aPourFrequenceHebdomadaire
    ex:aPourDuree ex:aPourDureeHebdomadaire ex:aPourDureeParEtirement
    ex:aPourNbRepetitions ex:aPourNbSeries ex:aPourNbExercices
    ex:aPour1RM_Bas_min ex:aPour1RM_Bas_max
    ex:aPour1RM_Haut_min ex:aPour1RM_Haut_max
  }
  BIND(STRAFTER(STR(?targetProp), "#") AS ?prop)
  {
    # Branche 1 : modalité définie directement sur l\'activité
    ?activity rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ?targetProp .
    {
      ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION { ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName) }
    UNION { ?restriction owl:hasValue ?value . FILTER(isLiteral(?value))
      BIND(STR(?value) AS ?valueName) }
    UNION {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
  UNION
  {
    # Branche 2 : modalité définie sur une classe parente de l\'activité
    ?activity rdfs:subClassOf+ ?parent .
    ?parent rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restriction .
    ?restriction owl:onProperty ?targetProp .
    {
      ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION { ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName) }
    UNION { ?restriction owl:hasValue ?value . FILTER(isLiteral(?value))
      BIND(STR(?value) AS ?valueName) }
    UNION {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
  ' . (!empty($pathologyUris) ? '
  UNION
  {
    # Branche 3 : modalité définie au niveau PATHOLOGIE
    # (ex: Cancer dit "RenforcementMusculaire avec 1RM_Bas entre 50 et 60%")
    ' . $pathoValues . '
    ?patho rdfs:subClassOf+ ?superP .
    ?superP rdfs:subClassOf ?exprP .
    ?exprP owl:intersectionOf ?listP .
    ?listP rdf:rest*/rdf:first ?restrAct .
    ?restrAct owl:onProperty ex:aPourActiviteRecommandee ;
              owl:someValuesFrom ?actClass .

    # L\'activité ciblée doit être notre activité (ou une intersection contenant notre activité)
    {
      FILTER(?actClass = ?activity)
      ?actClass rdfs:subClassOf ?exprM .
      ?exprM owl:intersectionOf ?listM .
      ?listM rdf:rest*/rdf:first ?restriction .
    }
    UNION
    {
      # Cas où c\'est une classe intermédiaire qui combine l\'activité + des modalités
      ?actClass owl:intersectionOf ?innerList .
      ?innerList rdf:rest*/rdf:first ?innerElt .
      {
        ?innerElt a owl:Class .
        FILTER(isIRI(?innerElt) && ?innerElt = ?activity)
      }
      ?innerList rdf:rest*/rdf:first ?restriction .
      FILTER(isBlank(?restriction))
    }
    ?restriction owl:onProperty ?targetProp .
    {
      ?restriction owl:someValuesFrom ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName)
    }
    UNION { ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName) }
    UNION { ?restriction owl:hasValue ?value . FILTER(isLiteral(?value))
      BIND(STR(?value) AS ?valueName) }
    UNION {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
  ' : '') . '
}
ORDER BY ?actName ?prop ?valueName';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];

    $items = []; // actName => [prop => [vals]]
    foreach ($result['bindings'] as $row) {
        $act  = $row['actName']['value'] ?? '';
        $prop = $row['prop']['value']    ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($act === '' || $prop === '' || $val === '') continue;
        $items[$act][$prop][] = $val;
    }

    // Fusionner paires min:/max: en plage pour chaque activité
    foreach ($items as $act => &$props) {
        foreach ($props as $prop => &$vals) {
            $vals  = array_values(array_unique($vals));
            $mins  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'min:')));
            $maxs  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'max:')));
            $rest  = array_values(array_filter($vals, fn($v) => !str_starts_with($v, 'min:') && !str_starts_with($v, 'max:')));
            if (!empty($mins) || !empty($maxs)) {
                $minV  = !empty($mins) ? substr($mins[0], 4) : null;
                $maxV  = !empty($maxs) ? substr($maxs[0], 4) : null;
                $range = ($minV !== null && $maxV !== null) ? $minV . ' – ' . $maxV : ($minV ?? $maxV);
                $vals  = array_merge($rest, [$range]);
            }
        }
        unset($vals);
        // Trier les props par groupe logique
        uksort($props, fn($a,$b) => modalityGroup($a) <=> modalityGroup($b));
    }
    unset($props);
    return $items;
}

function loadFreinsAndLeviers(): array
{
    // Structure réelle dans l'ontologie :
    // FreinX subClassOf [intersectionOf(FreinType, restriction(aPourLevier some {union leviers}))]
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT DISTINCT ?frein ?freinType ?levier
WHERE {
  ?frein rdfs:subClassOf ?anon .
  ?anon owl:intersectionOf ?list .
  # Type de frein depuis la liste
  ?list rdf:rest*/rdf:first ?freinType .
  FILTER(isIRI(?freinType))
  FILTER(STRSTARTS(STR(?freinType), "' . NS . '"))
  ?freinType rdfs:subClassOf ex:Frein .
  FILTER(?freinType != ex:Frein)
  FILTER(STRSTARTS(STR(?frein), "' . NS . '"))
  FILTER(?frein != ?freinType)
  # Leviers — IRI direct ou union de classes
  OPTIONAL {
    ?list rdf:rest*/rdf:first ?restr .
    ?restr owl:onProperty ex:aPourLevier .
    {
      ?restr owl:someValuesFrom ?lev .
      FILTER(isIRI(?lev))
      FILTER(STRSTARTS(STR(?lev), "' . NS . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
    UNION
    {
      ?restr owl:someValuesFrom ?union .
      FILTER(isBlank(?union))
      ?union owl:unionOf/rdf:rest*/rdf:first ?lev .
      FILTER(isIRI(?lev))
      FILTER(STRSTARTS(STR(?lev), "' . NS . '"))
      BIND(STRAFTER(STR(?lev), "#") AS ?levier)
    }
  }
}
ORDER BY ?freinType ?frein ?levier';

    $result = sparqlQuery($query);
    if (!$result['ok']) return [];

    $typeLabels = [
        'FreinPhysique'        => ['label' => 'Frein physique',        'icon' => '🩺', 'order' => 1],
        'FreinPsychologique'   => ['label' => 'Frein psychologique',   'icon' => '🧠', 'order' => 2],
        'FreinMotivationnel'   => ['label' => 'Frein motivationnel',   'icon' => '💡', 'order' => 3],
        'FreinSituationnel'    => ['label' => 'Frein situationnel',    'icon' => '⏰', 'order' => 4],
        'FreinSocial'          => ['label' => 'Frein social',          'icon' => '👥', 'order' => 5],
        'FreinEnvironnemental' => ['label' => 'Frein environnemental', 'icon' => '🌍', 'order' => 6],
    ];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $frein     = localName($row['frein']['value']     ?? '');
        $typeLocal = localName($row['freinType']['value'] ?? '');
        $levier    = $row['levier']['value'] ?? '';
        if ($frein === '' || isset($typeLabels[$frein])) continue;
        if (!isset($items[$frein])) {
            $typeInfo = $typeLabels[$typeLocal] ?? ['label' => prettyLabel($typeLocal), 'icon' => '•', 'order' => 99];
            $items[$frein] = [
                'label'     => prettyLabel($frein),
                'typeKey'   => $typeLocal,
                'typeLabel' => $typeInfo['label'],
                'typeIcon'  => $typeInfo['icon'],
                'typeOrder' => $typeInfo['order'],
                'leviers'   => [],
            ];
        }
        if ($levier !== '' && !in_array($levier, $items[$frein]['leviers'], true))
            $items[$frein]['leviers'][] = $levier;
    }

    // Grouper par type, dans l'ordre canonique
    usort($items, fn($a, $b) => $a['typeOrder'] <=> $b['typeOrder'] ?: strcmp($a['label'], $b['label']));
    $grouped = [];
    foreach ($items as $fk => $data) {
        $grouped[$data['typeLabel']][$fk] = $data;
    }
    return $grouped;
}


// ── Récupération des pathologies sélectionnées ────────────────────────────

$selected = $_GET['pathologies'] ?? [];
if (!is_array($selected)) $selected = [$selected];
$selected = array_values(array_filter($selected, fn($v) => is_string($v) && $v !== ''));

if (empty($selected)) {
    header('Location: index.php');
    exit;
}

// ── Détection des CI génériques (classes parentes bloquant tout) ─────────
/**
 * Vérifie si une CI est une classe parente qui couvre toutes les activités.
 * Ex: "ActivitePhysique" bloque tout, "RenforcementMusculaire" bloque tous les renforcements.
 * Retourne les activités bloquées par cette CI générique, ou true si elle bloque tout.
 */
function loadBlockedByGenericCI(string $ciLocalName): string
{
    // Ces classes couvrent TOUTES les activités physiques
    $globalBlockers = ['ActivitePhysique'];
    if (in_array($ciLocalName, $globalBlockers, true)) return 'ALL';

    // Sinon vérifier si c'est une classe parente d'activités
    // (ex: RenforcementMusculaire bloquerait tous ses enfants)
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
        $nb = (int)$result['bindings'][0]['nb']['value'];
        if ($nb > 0) return 'PARENT'; // classe parente avec enfants
    }
    return 'SPECIFIC'; // CI spécifique sans sous-classes
}

/**
 * Récupère la liste des noms locaux des sous-classes (récursives) d'une classe CI.
 * Ex : pour 'MusculationLourde', retourne ['DeveloppeCouche', 'Crunch', ...]
 *
 * @param string $ciLocalName Nom local de la classe CI (ex: 'MusculationLourde')
 * @return array Liste des noms locaux des sous-classes (incluant la classe elle-même)
 */
function loadCISubclasses(string $ciLocalName): array
{
    static $cache = [];
    if (isset($cache[$ciLocalName])) return $cache[$ciLocalName];

    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT DISTINCT ?child
WHERE {
  {
    ?child rdfs:subClassOf+ ex:' . $ciLocalName . ' .
  } UNION {
    BIND(ex:' . $ciLocalName . ' AS ?child)
  }
  FILTER(STRSTARTS(STR(?child), "' . NS . '"))
}';
    $result = sparqlQuery($query);
    $subclasses = [];
    if ($result['ok']) {
        foreach ($result['bindings'] as $row) {
            $uri = $row['child']['value'] ?? '';
            if ($uri === '') continue;
            $local = localName($uri);
            if ($local !== '') $subclasses[] = $local;
        }
    }
    $cache[$ciLocalName] = $subclasses;
    return $subclasses;
}

// ── Chargement de toutes les données ─────────────────────────────────────

$pathologyLabels     = []; // uri => label
$recoByPatho         = []; // uri => [ ['activity'=>..., 'adaptations'=>[...]] ]
$contraByPatho       = []; // uri => ['CI1','CI2']
$modalsByPatho       = []; // uri => [ prop => [vals] ]
$subActsByPatho      = []; // uri => [ actName => [ subName => [equips] ] ]
$allActivities       = []; // act => true (pour dédup)
$allContra           = []; // ci => true
$mergedModalities    = []; // prop => [vals]

foreach ($selected as $uri) {
    $local = localName($uri);
    $pathologyLabels[$uri] = categoryTitle($local);

    $recos  = loadRecommendations($uri);
    $contra = loadContraindications($uri);
    $mods   = loadModalities($uri);

    $recoByPatho[$uri]    = $recos;
    $contraByPatho[$uri]  = $contra;
    $modalsByPatho[$uri]  = $mods;

    foreach ($recos  as $r)  $allActivities[$r['activity']] = true;
    foreach ($contra as $c)  $allContra[$c] = $c;
    foreach ($mods   as $prop => $vals)
        foreach ($vals as $v) $mergedModalities[$prop][] = $v;

    // Sous-activités
    $actNames = array_map(fn($r) => $r['activity'], $recos);
    $subActsByPatho[$uri] = loadSubActivities($actNames);

    // Modalités au niveau des activités (Borg, 1RM, fréquences, etc.)
    $actMods = loadModalitiesForActivities($actNames);
    foreach ($actMods as $prop => $vals) {
        foreach ($vals as $v) $mergedModalities[$prop][] = $v;
    }
}

// garder la contrainte la plus restrictive
// ── Fusion 1RM min/max en plage lisible ───────────────────────────────────
function merge1RM(array &$items): void
{
    foreach ($items as &$vals) {
        if (!is_array($vals)) continue;
        foreach ([
            'aPour1RM_Bas'  => ['aPour1RM_Bas_min',  'aPour1RM_Bas_max'],
            'aPour1RM_Haut' => ['aPour1RM_Haut_min', 'aPour1RM_Haut_max'],
        ] as $merged => $parts) {
            if (isset($vals[$parts[0]]) || isset($vals[$parts[1]])) {
                $min = $vals[$parts[0]][0] ?? null;
                $max = $vals[$parts[1]][0] ?? null;
                $range = ($min !== null && $max !== null) ? $min . ' – ' . $max . ' %'
                       : (($min ?? $max) . ' %');
                $vals[$merged] = [$range];
                unset($vals[$parts[0]], $vals[$parts[1]]);
            }
        }
    }
    unset($vals);
}
#restriciton trie par ordre croissant prends le plus faible 
function chooseRestrictive(array $values, string $type): array
{
    if ($type === 'aPourIntensite') {
        $rank = fn(string $v): int => match(true) {
            str_contains(strtolower($v), 'faible')  => 1,
            str_contains(strtolower($v), 'modere')  => 2,
            str_contains(strtolower($v), 'intense') => 4,
            default => 3,
        };
        usort($values, fn($a, $b) => $rank($a) <=> $rank($b));
        return [$values[0]];
    }
    if (in_array($type, ['aPourDuree','aPourDureeHebdomadaire','aPourFrequence','aPourFrequenceHebdomadaire','aPourNbRepetitions','aPourNbSeries','aPourNbExercices','aPour1RM_Bas_min','aPour1RM_Bas_max','aPour1RM_Haut_min','aPour1RM_Haut_max'], true)) {
        $withNum = [];
        foreach ($values as $v) if (preg_match('/(\d+)/', $v, $m)) $withNum[$v] = (int)$m[1];
        if (!empty($withNum)) { asort($withNum); return [array_key_first($withNum)]; }
    }
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return [$values[0]];
} # preg_match affiche tous et prends le plsu faible
# ici >1 pour plusieurs patho
if (count($selected) > 1) {
    foreach ($mergedModalities as $prop => $vals) {
        $mergedModalities[$prop] = chooseRestrictive(array_values(array_unique($vals)), $prop);
    }
} else {
    foreach ($mergedModalities as $prop => &$vals) {
        $vals = array_values(array_unique($vals));
    }
    unset($vals);
}

// Dédupliquer les recommandations : chaque activité n'est affichée qu'une fois
$seenActs  = [];
$seenContra = [];
$finalRecos  = []; // [ ['activity'=>..., 'adaptations'=>[...], 'pathoLabels'=>[...]] ]
$finalContra = []; // [ ci => [pathoLabels] ]

foreach ($selected as $uri) {
    $lbl = $pathologyLabels[$uri];
    foreach ($recoByPatho[$uri] as $item) {
        $act = $item['activity'];
        if (!isset($seenActs[$act])) {
            $seenActs[$act] = count($finalRecos);
            $item['pathoLabels'] = [$lbl];
            $finalRecos[] = $item;
        } else {
            $idx = $seenActs[$act];
            // Fusionner les adaptations
            foreach ($item['adaptations'] as $adap) {
                if (!in_array($adap, $finalRecos[$idx]['adaptations'], true))
                    $finalRecos[$idx]['adaptations'][] = $adap;
            }
            // Ajouter le label de pathologie
            if (!in_array($lbl, $finalRecos[$idx]['pathoLabels'], true))
                $finalRecos[$idx]['pathoLabels'][] = $lbl;
        }
    }
    foreach ($contraByPatho[$uri] as $c) {
        if (!isset($finalContra[$c])) $finalContra[$c] = [];
        if (!in_array($lbl, $finalContra[$c], true)) $finalContra[$c][] = $lbl;
    }
}

// ── Détection des blocages globaux par CI génériques ──────────────────────
// $globalCIBlocks[pathoUri][] = ['ci' => 'MusculationLourde', 'type' => 'ALL|PARENT|SPECIFIC', 'label' => 'Hypertension', 'subclasses' => [...]]
$globalCIBlocks = [];

foreach ($selected as $uri) {
    $lbl = $pathologyLabels[$uri];
    foreach ($contraByPatho[$uri] as $c) {
        $ciType = loadBlockedByGenericCI($c);
        // Pour PARENT et SPECIFIC, on précharge les sous-classes (incluant la CI elle-même)
        $subclasses = ($ciType !== 'ALL') ? loadCISubclasses($c) : [];
        $globalCIBlocks[$uri][] = [
            'ci'         => $c,
            'type'       => $ciType,
            'label'      => $lbl,
            'subclasses' => $subclasses,
        ];
    }
}

// Identifier les activités à retirer de finalRecos
if (!empty($globalCIBlocks)) {
    $blockedRecos  = []; // items bloqués avec raison
    $okFinalRecos  = []; // items conservés

    foreach ($finalRecos as $item) {
        $act = $item['activity'];
        $blockReasons = [];

        foreach ($globalCIBlocks as $uri => $blocks) {
            $pathoLbl = $pathologyLabels[$uri];
            foreach ($blocks as $block) {
                $isBlocked = false;
                $reasonCI  = $block['ci'];

                if ($block['type'] === 'ALL') {
                    // CI = ActivitePhysique → bloque toutes les activités
                    $isBlocked = true;
                } elseif ($block['type'] === 'PARENT' || $block['type'] === 'SPECIFIC') {
                    // Vérifier si l'activité figure parmi les sous-classes de la CI
                    // (la CI elle-même est incluse dans la liste retournée par loadCISubclasses)
                    if (in_array($act, $block['subclasses'], true)) {
                        $isBlocked = true;
                    }
                }

                if ($isBlocked) {
                    // Éviter les doublons de raisons (même patho + même CI)
                    $alreadyKnown = false;
                    foreach ($blockReasons as $r) {
                        if ($r['patho'] === $pathoLbl && $r['ci'] === $reasonCI) {
                            $alreadyKnown = true;
                            break;
                        }
                    }
                    if (!$alreadyKnown) {
                        $blockReasons[] = [
                            'patho' => $pathoLbl,
                            'ci'    => $reasonCI,
                            'type'  => $block['type'],
                        ];
                    }
                }
            }
        }

        if (!empty($blockReasons)) {
            $item['blockReasons'] = $blockReasons;
            $blockedRecos[] = $item;
        } else {
            $okFinalRecos[] = $item;
        }
    }

    // Remplacer finalRecos par la version filtrée
    $finalRecos = $okFinalRecos;
} else {
    $blockedRecos = [];
}

// Adaptations fusionnées
$mergedAdaptations = [];
foreach ($finalRecos as $item) {
    foreach (($item['adaptations'] ?? []) as $adap) {
        $mergedAdaptations[$adap] = $adap;
    }
}
sort($mergedAdaptations);

// Modalités par activité (pour affichage par activité dans le rapport)
// On passe les pathologies sélectionnées pour récupérer aussi les modalités
// définies au niveau patho (ex: Cancer dit "Renforcement avec 1RM_Bas=50-60%")
$modalitiesPerActivity = !empty($finalRecos)
    ? loadModalitiesPerActivity(array_map(fn($r) => $r['activity'], $finalRecos), $selected)
    : [];
merge1RM($modalitiesPerActivity);

// Freins et leviers
$freinsGrouped = loadFreinsAndLeviers();

// ── Séparation communes / spécifiques ────────────────────────────────────
// - Communes  : activités recommandées par >= 2 pathologies sélectionnées
// - Spécifiques : activités recommandées par UNE SEULE pathologie
// (Si une seule pathologie est sélectionnée, tout va en "communes".)
$nbSelected = count($selected);
$commonRecos     = []; // activités multi-pathos (ou patho unique sélectionnée)
$specificRecos   = []; // activités mono-patho (uniquement quand 2+ pathos sélectionnées)

foreach ($finalRecos as $item) {
    $nbPathos = count($item['pathoLabels'] ?? []);
    if ($nbSelected <= 1 || $nbPathos >= 2) {
        // Communes : recommandées par plusieurs pathologies sélectionnées
        $commonRecos[] = $item;
    } else {
        // Spécifiques : recommandées par une seule pathologie
        $specificRecos[] = $item;
    }
}

// URL retour
$backUrl = 'index.php?' . http_build_query(
    $EXPLORE_MODE
        ? ['pathologies' => $selected, 'mode' => 'explore']
        : ['pathologies' => $selected]
);

// ── Module 2 : stockage en session pour le parcours inversé ──────────────
// Patho + activités finales sont stockées pour être utilisées par les étapes suivantes
setSessionPathologies($selected);
$finalActivityUris = [];
// Pour patient.php : on stocke aussi la correspondance activité → pathos qui la recommandent
// Format : [ 'URI_activite' => ['Cancer', 'Angor stable'], ... ]
$activitiesWithPathos = [];
foreach ($finalRecos as $item) {
    if (!empty($item['activity'])) {
        $uri = NS . $item['activity'];
        $finalActivityUris[] = $uri;
        $activitiesWithPathos[$uri] = $item['pathoLabels'] ?? [];
    }
}
setSessionActivities($finalActivityUris);
$_SESSION['parcours_activites_pathos'] = $activitiesWithPathos;

// ── Module 2 : stocker les contre-indications RACINES en session ──────────
// On ne stocke QUE les CI explicitement déclarées ($finalContra),
// pas les activités bloquées en cascade ($blockedRecos) — celles-ci sont
// des CONSÉQUENCES, pas des CI à part entière.
// Cela garantit la cohérence entre rapport.php et patient.php.
$contraindications = [];
foreach ($finalContra as $ciName => $pathoLbls) {
    $contraindications[] = [
        'activity' => prettyLabel($ciName),
        'reasons'  => $pathoLbls,
    ];
}
setSessionContraindications($contraindications);

// On stocke aussi séparément les activités bloquées en cascade,
// pour que patient.php puisse expliquer pourquoi il n'y a rien à recommander.
$_SESSION['parcours_blocked_count'] = count($blockedRecos);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de recommandation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Variables ──────────────────────────────────────────────────────── */
        :root{
            --bg:#f0f4f8;--card:#fff;--line:#e2e8f0;--text:#0f172a;--muted:#64748b;
            --accent:#3b82f6;--accent-dark:#1d4ed8;
            --ok:#059669;--ok-bg:#ecfdf5;--ok-border:#6ee7b7;
            --warn:#d97706;--warn-bg:#fffbeb;
            --danger:#b91c1c;--danger-bg:#fef2f2;--danger-border:#fca5a5;
            --conflict:#7c3aed;--conflict-bg:#f5f3ff;--conflict-border:#c4b5fd;
            --radius:14px;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}

        /* ── Layout ─────────────────────────────────────────────────────────── */
        .container{max-width:1400px;margin:0 auto;padding:20px 16px 60px}

        /* ── Header ─────────────────────────────────────────────────────────── */
        .page-header{
            background:linear-gradient(110deg,#1e40af 0%,#3b82f6 60%,#60a5fa 100%);
            color:#fff;border-radius:var(--radius);padding:16px 24px;
            display:flex;align-items:center;gap:16px;
            box-shadow:0 4px 24px rgba(59,130,246,.3);
            margin-bottom:16px;
        }
        .page-header-icon{font-size:28px;flex-shrink:0}
        .page-header h1{font-size:18px;font-weight:800;letter-spacing:-.3px}
        .page-header p{font-size:12px;opacity:.85;margin-top:2px}
        .header-actions{margin-left:auto;display:flex;gap:8px;align-items:center;flex-shrink:0}
        .btn-back{
            background:rgba(255,255,255,.18);color:#fff;
            border:1.5px solid rgba(255,255,255,.4);border-radius:8px;
            padding:7px 14px;font-size:13px;font-weight:600;
            font-family:inherit;cursor:pointer;text-decoration:none;
            transition:background .15s;
        }
        .btn-back:hover{background:rgba(255,255,255,.28)}
        .btn-print{
            background:#fff;color:var(--accent);
            border:1.5px solid rgba(255,255,255,.6);border-radius:8px;
            padding:7px 14px;font-size:13px;font-weight:600;
            font-family:inherit;cursor:pointer;
            transition:all .15s;
        }
        .btn-print:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
        .btn-rapport{
            background:#059669;color:#fff;
            border:1.5px solid #059669;border-radius:8px;
            padding:7px 14px;font-size:13px;font-weight:600;
            font-family:inherit;cursor:pointer;text-decoration:none;
            transition:all .15s;display:inline-flex;align-items:center;gap:5px;
        }
        .btn-rapport:hover{background:#047857;border-color:#047857}
        .btn-attribuer-top:hover{background:#1d4ed8 !important;box-shadow:0 6px 16px rgba(37,99,235,.4) !important;transform:translateY(-1px)}

        /* ── Main 2-col layout ──────────────────────────────────────────────── */
        .main-layout{display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:start}

        /* ── Sidebar (pathologies sélectionnées) ────────────────────────────── */
        .sidebar{position:sticky;top:16px}
        .sidebar-title{
            font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
            color:var(--muted);margin-bottom:8px;padding:0 2px;
        }
        .patho-card{
            background:var(--card);border:1px solid var(--line);border-radius:10px;
            padding:12px 14px;margin-bottom:6px;
            box-shadow:var(--shadow);
            border-left:4px solid var(--accent);
            transition:border-color .15s;
        }
        .patho-card:hover{border-left-color:#60a5fa}
        .patho-card-name{font-weight:700;font-size:13px;color:var(--text)}
        .patho-card-meta{font-size:11px;color:var(--muted);margin-top:3px}
        .patho-pill{
            display:inline-flex;align-items:center;gap:3px;
            font-size:10px;font-weight:600;border-radius:4px;
            padding:1px 6px;margin-right:3px;margin-top:3px;border:1px solid;
        }
        .pill-ok{background:var(--ok-bg);color:var(--ok);border-color:var(--ok-border)}
        .pill-danger{background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border)}

        /* ── Contenu principal ──────────────────────────────────────────────── */
        .content{display:flex;flex-direction:column;gap:14px}

        .report-split{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px;align-items:start}
        .report-col{min-width:0}
        .report-col-stack{display:flex;flex-direction:column;gap:14px}
        .full-height{height:100%}
        .ci-grid-compact{grid-template-columns:1fr}
        .grouped-adaptations{gap:6px}
        .compact-empty{padding:12px;font-size:12px}

        /* ── Section cards ──────────────────────────────────────────────────── */
        .section-card{
            background:var(--card);border:1px solid var(--line);
            border-radius:var(--radius);box-shadow:var(--shadow);
            overflow:hidden;
        }
        .section-header{
            display:flex;align-items:center;gap:10px;
            padding:13px 18px;border-bottom:1px solid var(--line);
        }
        .section-header-reco{
            padding:15px 20px;
            border-left:4px solid var(--ok);
            background:linear-gradient(90deg,#f0fdf4 0%,#fff 60%);
        }
        .section-icon{font-size:18px;flex-shrink:0}
        .section-title{font-size:16px;font-weight:700;flex:1}
        .section-title-reco{font-size:13px;font-weight:800;letter-spacing:.3px;color:#064e3b}
        .section-count{
            font-size:11px;font-weight:700;
            background:#f1f5f9;border:1px solid var(--line);
            border-radius:20px;padding:2px 9px;color:var(--muted);
        }
        .section-body{padding:14px 18px}

        /* ── Recommandations (style index.php) ─────────────────────────────── */
        .reco-grid{display:flex;flex-direction:column;gap:10px}
        .reco-item{
            background:#f0fdf8;
            border:1px solid #a7f3d0;
            border-left:4px solid var(--ok);
            border-radius:10px;
            padding:12px 16px;
            transition:box-shadow .15s;
        }
        .reco-item:hover{box-shadow:0 2px 12px rgba(5,150,105,.12)}
        .reco-item-top{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap}
        .reco-name{font-weight:700;font-size:16px;color:#064e3b;flex:1}
        .reco-pathos{display:flex;flex-wrap:wrap;gap:3px}
        .patho-tag{
            font-size:10px;font-weight:600;background:#eff6ff;color:var(--accent-dark);
            border:1px solid #bfdbfe;border-radius:4px;padding:1px 6px;
        }
        .reco-adaptations{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
        .adapt-chip{
            display:inline-flex;align-items:center;gap:4px;
            font-size:11px;font-weight:600;
            background:#EAF3DE;color:#27500A;
            border:1px solid #97C459;border-radius:6px;padding:3px 9px;
        }
        .reco-mods{
            display:flex;flex-wrap:wrap;gap:5px;
            margin-top:8px;padding-top:8px;border-top:1px dashed #d1fae5;
        }
        .mod-chip{
            display:inline-flex;align-items:center;gap:4px;
            font-size:11px;background:#eff6ff;color:var(--accent-dark);
            border:1px solid #bfdbfe;border-radius:5px;padding:2px 8px;
        }
        .mod-chip strong{font-weight:700}
        .subacts-toggle{
            display:inline-flex;align-items:center;gap:4px;
            font-size:11px;font-weight:600;color:var(--accent);
            cursor:pointer;list-style:none;margin-top:6px;
            border:none;background:none;padding:0;font-family:inherit;
        }
        .subacts-toggle::-webkit-details-marker{display:none}
        .subacts-details[open] .subacts-toggle::before{transform:rotate(90deg)}
        .subacts-toggle::before{content:"▸";font-size:10px;transition:transform .15s}
        .subacts-grid-r{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:6px;margin-top:8px}
        .subact-r{background:#f8fafc;border:1px solid var(--line);border-radius:7px;padding:7px 9px}
        .subact-r-name{font-size:12px;font-weight:600;color:#1e3a5f;margin-bottom:4px}
        .subact-equips{display:flex;flex-wrap:wrap;gap:3px}
        .equip-chip{font-size:10px;font-weight:500;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:4px;padding:1px 5px}

        /* ── Contre-indications droite (style index.php) ───────────────────── */
        .ci-col-card{
            background:var(--card);border:1px solid var(--danger-border);
            border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
        }
        .col-header-r{
            display:flex;align-items:center;gap:8px;
            padding:12px 16px;font-size:13px;font-weight:800;
            letter-spacing:.3px;text-transform:uppercase;border-bottom:1px solid var(--line);
        }
        .col-header-ci-r{
            color:var(--danger);
            border-bottom:1px solid var(--danger-border);
            background:var(--danger-bg);
        }
        .ci-badge-r{
            margin-left:auto;font-size:11px;font-weight:700;
            background:var(--danger);color:#fff;
            border-radius:20px;padding:2px 8px;
        }
        .ci-blocked-note-r{
            background:#fff7ed;border-bottom:1px solid #fde68a;
            padding:7px 16px;font-size:11px;color:#92400e;
        }
        .ci-col-body-r{padding:10px 14px;display:flex;flex-direction:column;gap:6px}
        .ci-empty-r{font-size:12px;color:var(--muted);font-style:italic;margin:4px 0}
        .ci-row-r{
            display:flex;align-items:center;justify-content:space-between;gap:12px;
            background:var(--danger-bg);border:1px solid var(--danger-border);
            border-left:3px solid var(--danger);border-radius:8px;padding:10px 14px;
        }
        .ci-name-r2{font-size:13px;font-weight:700;color:var(--danger);line-height:1.3;flex:1;min-width:0}
        .ci-ptags-r{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end;flex-shrink:0}
        .ci-ptag-r{font-size:10px;color:#b91c1c;background:#fee2e2;border:1px solid #fca5a5;border-radius:4px;padding:2px 7px;font-weight:600;white-space:nowrap}

        /* ── Adaptations enrichies ─────────────────────────────────────────── */
        .adapt-subsection{margin-bottom:12px}
        .adapt-subsection:last-child{margin-bottom:0}
        .adapt-subsection-sep{padding-top:12px;border-top:1px dashed var(--line)}
        .adapt-sub-title{
            font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
            color:var(--muted);margin-bottom:8px;
            display:flex;align-items:center;gap:6px;
        }
        .adapt-sub-note{
            font-size:9px;font-weight:500;font-style:italic;
            letter-spacing:0;text-transform:none;
            background:#f1f5f9;border-radius:4px;padding:1px 5px;
            color:var(--muted);
        }
        .adapt-chips-row{display:flex;flex-wrap:wrap;gap:5px}

        /* Précautions globales */
        .global-precautions{
            background:#fffbeb;border:1px solid #fde68a;
            border-radius:8px;padding:8px 10px;margin-bottom:10px;
        }

        /* Blocs par activité */
        .act-param-block{
            padding:8px 0;border-bottom:1px solid var(--line);
        }
        .act-param-block:last-child{border-bottom:none;padding-bottom:0}
        .act-param-name{
            display:flex;align-items:center;gap:6px;
            font-size:13px;font-weight:700;color:var(--text);
            margin-bottom:6px;
        }
        .act-param-dot{color:var(--accent);font-size:11px;flex-shrink:0}
        .act-param-empty{
            font-size:11px;color:var(--muted);font-style:italic;
            padding-left:17px;
        }
        .act-param-adapts{display:flex;flex-wrap:wrap;gap:4px;padding-left:17px;margin-bottom:5px}
        .adapt-chip-sm{font-size:10px!important;padding:1px 6px!important}

        /* Lignes de paramètres par activité */
        .act-param-mods{
            display:flex;flex-direction:column;gap:3px;
        }
        /* Suggestion EAPA — prioritaire, bien visible (style index.php) */
        .act-mod-row-eapa{
            display:flex;align-items:center;justify-content:space-between;gap:8px;
            background:linear-gradient(90deg,#dcfce7,#f0fdf4)!important;
            border:1px solid #a7f3d0!important;
            border-left:4px solid #059669!important;
            border-radius:7px;margin-bottom:6px;
            padding:6px 10px!important;
        }
        .act-mod-key-eapa{
            color:#064e3b!important;font-weight:800!important;font-size:12px!important;
            flex:1;text-transform:uppercase;letter-spacing:.5px;
        }
        .act-mod-val-eapa{
            font-weight:800!important;color:#064e3b!important;
            background:#bbf7d0!important;border-radius:5px;
            padding:2px 9px!important;font-size:12px!important;
            border:1px solid #6ee7b7!important;
            white-space:normal;flex-shrink:0;
        }
        .act-mod-row{
            display:flex;align-items:center;gap:6px;
            padding:4px 8px;background:#f8fafc;border-radius:6px;
            border:1px solid var(--line);
        }
        .act-mod-row-morganne{
            background:#fbfcfd;border:1px dashed #cbd5e1;
            padding:3px 8px;margin-left:36px;
        }
        .act-mod-row-morganne .act-mod-key{font-size:11px;color:#475569;font-weight:600}
        .act-mod-row-morganne .act-mod-val{
            font-size:12px;font-weight:700;color:#334155;
            background:#f1f5f9;border:1px solid #cbd5e1;
        }
        .morganne-label{
            display:block;margin:6px 0 2px;
            font-size:11px;font-weight:700;
            color:#334155;
        }
        .act-mod-icon{font-size:12px;flex-shrink:0;width:16px;text-align:center}
        .act-mod-key{font-size:11px;font-weight:500;color:var(--muted);flex:1}
        .act-mod-val{
            display:flex;align-items:center;gap:3px;
            font-size:12px;font-weight:800;color:var(--accent-dark);
            background:#eff6ff;border:1px solid #bfdbfe;
            border-radius:5px;padding:2px 8px;white-space:nowrap;flex-shrink:0;
        }
        .mod-unit{font-size:10px;font-weight:400;color:var(--muted)}

        /* ── Freins & Leviers ───────────────────────────────────────────────── */
        .freins-intro{font-size:11px;color:var(--muted);margin-bottom:10px;font-style:italic}

        .frein-type-details{
            border:1px solid var(--line);border-radius:8px;
            margin-bottom:7px;overflow:hidden;
        }
        .frein-type-details:last-child{margin-bottom:0}
        .frein-type-summary{
            list-style:none;display:flex;align-items:center;gap:7px;
            padding:8px 11px;cursor:pointer;
            background:#f8fafc;transition:background .12s;user-select:none;
        }
        .frein-type-summary:hover{background:#eff6ff}
        .frein-type-details[open] .frein-type-summary{
            background:#eff6ff;border-bottom:1px solid var(--line);
        }
        .frein-type-summary::-webkit-details-marker{display:none}
        .frein-type-summary::before{
            content:"▸";font-size:9px;color:var(--muted);
            transition:transform .18s;flex-shrink:0;
        }
        .frein-type-details[open] .frein-type-summary::before{transform:rotate(90deg)}
        .frein-type-icon{font-size:13px}
        .frein-type-label{flex:1;font-size:12px;font-weight:700;color:var(--text)}
        .frein-type-count{
            font-size:10px;font-weight:700;
            background:#e2e8f0;color:var(--muted);
            border-radius:10px;padding:1px 6px;
        }
        .frein-type-body{
            display:flex;flex-direction:column;gap:5px;
            padding:7px 9px;background:var(--card);
        }

        .frein-item{
            background:#fafafa;border:1px solid var(--line);
            border-radius:6px;padding:7px 9px;
        }
        .frein-item-header{display:flex;align-items:center;gap:5px;margin-bottom:4px}
        .frein-dot{font-size:7px;color:#94a3b8;flex-shrink:0}
        .frein-name{font-weight:600;font-size:12px;color:var(--text);flex:1}
        .frein-levier-count{
            font-size:9px;font-weight:600;
            background:var(--ok-bg);color:var(--ok);
            border:1px solid var(--ok-border);border-radius:8px;padding:1px 5px;
            white-space:nowrap;
        }
        .leviers-chips{display:flex;flex-wrap:wrap;gap:3px}
        .levier-chip{
            display:inline-flex;align-items:center;gap:3px;
            font-size:10px;font-weight:500;
            background:var(--ok-bg);color:var(--ok);
            border:1px solid var(--ok-border);border-radius:4px;padding:2px 7px;
        }
        .no-levier{font-size:10px;color:var(--muted);font-style:italic;padding-left:12px}

        /* ── Modalités globales ─────────────────────────────────────────────── */
        .mods-global{display:flex;flex-wrap:wrap;gap:6px}
        .mod-global-chip{
            display:inline-flex;align-items:center;gap:5px;
            background:#eff6ff;color:var(--accent-dark);
            border:1px solid #bfdbfe;border-radius:6px;padding:4px 10px;
            font-size:12px;
        }
        .mod-global-chip strong{font-weight:700}

        /* ── Empty state ────────────────────────────────────────────────────── */
        .empty-state{
            text-align:center;padding:32px;color:var(--muted);
            font-size:13px;font-style:italic;
        }

        /* ── Bannière CI globale ─────────────────────────────────────────────── */
        /* Bandeau CI fin */
        .ci-global-banner{
            display:flex;align-items:center;gap:8px;
            background:#fff7ed;border-left:3px solid #fb923c;
            padding:7px 14px;margin-bottom:10px;
            font-size:12px;color:#92400e;flex-wrap:wrap;
        }
        .ci-global-icon{font-size:13px;flex-shrink:0}
        .ci-global-ci{
            font-weight:600;background:#fed7aa;color:#9a3412;
            border-radius:4px;padding:1px 6px;margin:0 2px;font-size:11px;
        }

        /* ── Bloc "aucune activité possible" ────────────────────────────────── */
        .no-reco-block{
            display:flex;flex-direction:column;align-items:center;
            gap:8px;padding:32px 20px;text-align:center;
            background:#fef2f2;border:1.5px solid #fca5a5;
            border-radius:10px;
        }
        .no-reco-icon{font-size:36px}
        .no-reco-title{font-weight:800;font-size:16px;color:var(--danger)}
        .no-reco-text{font-size:13px;color:#7f1d1d;max-width:420px;line-height:1.6}

        /* ── Activités bloquées (liste grisée) ──────────────────────────────── */
        .blocked-section{
            margin-top:14px;padding:12px 14px;
            background:#f8fafc;border:1px dashed #cbd5e1;
            border-radius:8px;
        }
        .blocked-section-title{
            font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
            color:#64748b;margin-bottom:8px;
        }
        .blocked-list{display:flex;flex-direction:column;gap:5px}
        .blocked-item{
            display:flex;align-items:center;gap:8px;flex-wrap:wrap;
            padding:5px 8px;background:#f1f5f9;border-radius:5px;
        }
        .blocked-name{
            font-size:12px;font-weight:600;color:#94a3b8;
            text-decoration:line-through;
        }
        .blocked-reason{
            font-size:11px;color:#ef4444;
            background:#fee2e2;border-radius:4px;padding:1px 6px;
        }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        @media (max-width:1100px){
            .report-split{grid-template-columns:1fr}
        }

        @media (max-width:900px){
            .main-layout{grid-template-columns:1fr}
            .sidebar{position:static}
            .ci-grid{grid-template-columns:1fr}
        }

        /* ── Print ──────────────────────────────────────────────────────────── */
        @media print{
            .header-actions .btn-back{display:none}
            body{background:#fff}
            .section-card,.patho-card{box-shadow:none;border:1px solid #ccc;break-inside:avoid}
        }

        /* ═══════════════════════════════════════════════════════════════════
           STEPPER 5 ÉTAPES (cohérent avec index.php / patient.php)
           ═══════════════════════════════════════════════════════════════════ */
        .stepper-bar{background:linear-gradient(135deg,#1d4ed8,#4b8df8);
                     border-radius:18px;padding:20px 24px;margin-bottom:24px;
                     box-shadow:0 10px 24px rgba(37,99,235,.18)}
        .stepper{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
        .step{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;
              background:rgba(255,255,255,.18);color:#fff;font-size:13px;font-weight:600;
              text-decoration:none;transition:.15s;font-family:inherit;border:none;cursor:pointer}
        .step-done{background:rgba(255,255,255,.28);opacity:.92}
        .step-done:hover{background:rgba(255,255,255,.38);opacity:1}
        .step-current{background:#fff;color:#1d4ed8;
                      box-shadow:0 4px 12px rgba(0,0,0,.15);transform:scale(1.06);
                      cursor:default}
        .step-todo{opacity:.55;cursor:default}
        .step-num{width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.32);
                  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px}
        .step-current .step-num{background:#2563eb;color:#fff}
        .step-done .step-num{background:rgba(255,255,255,.5);color:#1e40af}
        .step-sep{color:rgba(255,255,255,.6);font-size:14px;margin:0 2px}
        @media print{.stepper-bar{display:none}}
    </style>
</head>
<body>

<?php if ($EXPLORE_MODE): ?>
<!-- Bannière "Mode exploration" -->
<div style="position:sticky;top:0;z-index:1000;background:linear-gradient(90deg,#059669,#10b981);
            color:#fff;padding:10px 20px;font-size:13px;font-weight:600;
            display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;
            box-shadow:0 2px 8px rgba(5,150,105,.3)">
    <span style="display:flex;align-items:center;gap:8px">
        🔓 <strong>Mode exploration libre</strong> · lecture seule, prescription désactivée
    </span>
    <a href="index.php?exit_explore=1" style="background:rgba(255,255,255,.2);color:#fff;text-decoration:none;
       padding:5px 12px;border-radius:6px;font-size:12px;border:1px solid rgba(255,255,255,.3)">
        ✕ Quitter
    </a>
</div>
<?php endif; ?>

<div class="container">

    <!-- ── Stepper : 5 étapes (praticien) ou 2 étapes (exploration) ────── -->
    <div class="stepper-bar">
        <div class="stepper">
            <?php
            // URL pour revenir à l'étape Pathologies en conservant la sélection
            $stepBackParams = ['pathologies' => $selected];
            if ($EXPLORE_MODE) $stepBackParams['mode'] = 'explore';
            $stepIndexUrl = 'index.php?' . http_build_query($stepBackParams);
            ?>
            <a class="step step-done" href="<?= htmlspecialchars($stepIndexUrl, ENT_QUOTES, 'UTF-8') ?>">
                <span class="step-num">✓</span><span>Pathologies</span>
            </a>
            <span class="step-sep">→</span>
            <div class="step step-current">
                <span class="step-num">2</span><span>Recommandations</span>
            </div>
            <?php if (!$EXPLORE_MODE): ?>
            <span class="step-sep">→</span>
            <div class="step step-todo">
                <span class="step-num">3</span><span>Patient</span>
            </div>
            <span class="step-sep">→</span>
            <div class="step step-todo">
                <span class="step-num">4</span><span>Freins/Leviers</span>
            </div>
            <span class="step-sep">→</span>
            <div class="step step-todo">
                <span class="step-num">5</span><span>Résumé IA</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Header ──────────────────────────────────────────────────────── -->
    <header class="page-header">
        <div class="page-header-icon"></div>
        <div>
            <h1>Synthèse de recommandation</h1>
            <p>
    <?= count($selected) ?> pathologie<?= count($selected) > 1 ? 's' : '' ?> sélectionnée<?= count($selected) > 1 ? 's' : '' ?>
    · <?= count($finalRecos) ?> activité<?= count($finalRecos) > 1 ? 's' : '' ?> recommandée<?= count($finalRecos) > 1 ? 's' : '' ?>
    <?php if (!empty($blockedRecos)): ?>
        · <span style="opacity:.8"> <?= count($blockedRecos) ?> bloquée<?= count($blockedRecos)>1?'s':'' ?> (contre-indication globale)</span>
    <?php endif; ?>
    · <?= count($finalContra) ?> contre-indication<?= count($finalContra) > 1 ? 's' : '' ?>
</p>
        </div>
        <div class="header-actions">
            <a class="btn-back" href="<?= h($backUrl) ?>">← Retour</a>
            <button class="btn-print" onclick="window.print()">🖨️ Imprimer</button>
            <?php if (!$EXPLORE_MODE):
                // Si un patient est déjà sélectionné en session (cas : on vient de
                // patient_detail.php), on personnalise le bouton pour éviter l'ambiguïté
                // "Prescrire à un patient" qui laisse penser qu'on doit en choisir un.
                $sessionPatientName = '';
                if (!empty($_SESSION['patient_uri'] ?? '')) {
                    $sessionPatientName = trim(($_SESSION['patient_prenom'] ?? '') . ' ' .
                                               ($_SESSION['patient_nom'] ?? ''));
                }
                $btnLabel = $sessionPatientName !== ''
                    ? '👤 Continuer avec ' . htmlspecialchars($sessionPatientName, ENT_QUOTES, 'UTF-8') . ' →'
                    : '👤 Prescrire à un patient →';
            ?>
            <a class="btn-attribuer-top" href="patient.php?from=rapport"
               style="background:#2563eb;color:#fff;border:none;border-radius:10px;
                      padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer;
                      text-decoration:none;display:inline-flex;align-items:center;gap:6px;
                      box-shadow:0 4px 12px rgba(37,99,235,.3);transition:.15s">
                <?= $btnLabel ?>
            </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- ── Main 2-col layout ────────────────────────────────────────────── -->
    <div class="main-layout">

        <!-- ── COLONNE GAUCHE : Pathologies sélectionnées ───────────────── -->
        <aside class="sidebar">
            <div class="sidebar-title">Pathologies sélectionnées</div>

            <?php foreach ($selected as $uri): ?>
                <?php
                    $lbl     = $pathologyLabels[$uri];
                    $nReco   = count($recoByPatho[$uri] ?? []);
                    $nCI     = count($contraByPatho[$uri] ?? []);
                ?>
                <div class="patho-card">
                    <div class="patho-card-name"><?= h($lbl) ?></div>
                    <div class="patho-card-meta" style="margin-top:6px">
                        <?php if ($nReco > 0): ?>
                            <span class="patho-pill pill-ok"> <?= $nReco ?> activité<?= $nReco > 1 ? 's' : '' ?> recommandée<?= $nReco > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                        <?php if ($nCI > 0): ?>
                            <span class="patho-pill pill-danger"> <?= $nCI ?> contre-indiquée<?= $nCI > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </aside>

        <!-- ── COLONNE DROITE : Rapport final en 2 colonnes ─────────────── -->
        <div class="content">

            <div class="report-split">

                <!-- ── COLONNE GAUCHE : Recommandations finales ─────────── -->
                <div class="report-col">
                    <div class="section-card full-height">
                        <div class="section-header section-header-reco">
                            <span class="section-title section-title-reco">ACTIVITÉS RECOMMANDÉES</span>
                            <span class="section-count" style="background:var(--ok-bg);color:var(--ok);border-color:var(--ok-border)"><?= count($finalRecos) ?></span>
                        </div>
                        <div class="section-body">


                            <?php if (empty($finalRecos)): ?>
                                <?php if (!empty($globalCIBlocks)): ?>
                                    <div class="no-reco-block">
                                        <div class="no-reco-icon">🛑</div>
                                        <div class="no-reco-title">Aucune activité ne peut être recommandée</div>
                                        <div class="no-reco-text">
                                            En raison des contre-indications globales ci-dessus,
                                            aucune activité physique ne peut être prescrite pour cette combinaison de pathologies.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">Pas encore renseigné.</div>
                                <?php endif; ?>
                            <?php else: ?>

                                <?php /* ── Activités communes à toutes les pathologies ── */ ?>
                                <?php if (!empty($commonRecos)): ?>
                                    <?php if (!empty($specificRecos)): ?>
                                    <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ok);margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid var(--ok)">
                                        Activités communes
                                        <span style="font-size:10px;font-weight:600;background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok-border);border-radius:10px;padding:1px 7px;margin-left:6px"><?= count($commonRecos) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="reco-grid">
                                        <?php foreach ($commonRecos as $item): ?>
                                            <?php
                                                $act    = $item['activity'];
                                                $adapts = $item['adaptations'] ?? [];
                                                $actMods = $modalitiesPerActivity[$act] ?? [];
                                                $subActs = [];
                                                foreach ($selected as $uri) {
                                                    foreach ($subActsByPatho[$uri][$act] ?? [] as $sn => $eq)
                                                        if (!isset($subActs[$sn])) $subActs[$sn] = $eq;
                                                }
                                            ?>
                                            <div class="reco-item">
                                                <div class="reco-item-top">
                                                    <div class="reco-name"><?= h(prettyLabel($act)) ?></div>
                                                    <?php if (count($selected) > 1): ?>
                                                    <div class="reco-pathos">
                                                        <?php foreach ($item['pathoLabels'] ?? [] as $pl): ?>
                                                            <span class="patho-tag"><?= h($pl) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($adapts) || !empty($actMods)): ?>
                                                    <div class="act-param-mods" style="margin-top:6px">
                                                        <?php if (!empty($adapts)): ?>
                                                            <div class="act-mod-row act-mod-row-eapa">
                                                                <span class="act-mod-key act-mod-key-eapa">Suggestions EAPA</span>
                                                                <span class="act-mod-val act-mod-val-eapa"><?= h(implode(' — ', array_map('prettyLabel', $adapts))) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($actMods)): ?>
                                                        <span class="morganne-label">Suggestions Morganne</span>
                                                        <?php foreach ($actMods as $prop => $vals): ?>
                                                            <div class="act-mod-row act-mod-row-morganne">
                                                                <span class="act-mod-icon"><?= modalityIcon($prop) ?></span>
                                                                <span class="act-mod-key"><?= h(modalityLabel($prop)) ?></span>
                                                                <span class="act-mod-val"><?= h(implode(' / ', array_map('prettyLabel', $vals))) ?><?php if (str_starts_with($prop,'aPour1RM')): ?><span class="mod-unit"> %</span><?php endif; ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($subActs)): ?>
                                                    <details class="subacts-details">
                                                        <summary class="subacts-toggle"><?= count($subActs) ?> exercice<?= count($subActs)>1?'s':'' ?> spécifique<?= count($subActs)>1?'s':'' ?></summary>
                                                        <div class="subacts-grid-r">
                                                            <?php foreach ($subActs as $subName => $equips): ?>
                                                                <div class="subact-r">
                                                                    <div class="subact-r-name"><?= h(prettyLabel($subName)) ?></div>
                                                                    <?php if (!empty($equips)): ?>
                                                                        <div class="subact-equips">
                                                                            <?php foreach ($equips as $eq): ?>
                                                                                <span class="equip-chip">🎒 <?= h(prettyLabel($eq)) ?></span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span style="font-size:10px;color:var(--muted);font-style:italic">Sans matériel</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php /* ── Activités compatibles (recommandée par 1 patho, compatible avec les autres) ── */ ?>
                                <?php if (!empty($specificRecos)): ?>
                                    <div style="margin-top:18px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;padding-bottom:6px;border-bottom:2px solid #c4b5fd;margin-bottom:8px">
                                        Activités compatibles
                                        <span style="font-size:10px;font-weight:600;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;border-radius:10px;padding:1px 7px;margin-left:6px"><?= count($specificRecos) ?></span>
                                    </div>
                                    <div class="reco-grid">
                                        <?php foreach ($specificRecos as $item): ?>
                                            <?php
                                                $act      = $item['activity'];
                                                $adapts   = $item['adaptations'] ?? [];
                                                $actMods  = $modalitiesPerActivity[$act] ?? [];
                                                $recoLabels = $item['pathoLabels'] ?? [];

                                                // ── Calcul des pathologies COMPATIBLES ──
                                                // = pathos sélectionnées qui ne recommandent PAS l'activité,
                                                //   mais qui ne la bloquent pas non plus (sinon elle ne serait
                                                //   pas affichée ici, elle serait dans $blockedRecos).
                                                // On IGNORE les pathos "non renseignées" (pas de reco ET pas de CI
                                                //   dans l'ontologie) : on n'a pas le droit d'affirmer qu'elles sont
                                                //   compatibles puisqu'on ne sait rien sur elles.
                                                $compatiblesLabels = [];
                                                foreach ($selected as $selUri) {
                                                    $selLbl = $pathologyLabels[$selUri] ?? '';
                                                    if ($selLbl === '') continue;
                                                    if (in_array($selLbl, $recoLabels, true)) continue; // patho qui recommande
                                                    // Ignorer si la patho n'a aucune donnée (ni reco ni CI)
                                                    $hasData = !empty($recoByPatho[$selUri]) || !empty($contraByPatho[$selUri]);
                                                    if (!$hasData) continue;
                                                    $compatiblesLabels[] = $selLbl;
                                                }
                                                $compatiblesStr = implode(', ', $compatiblesLabels);

                                                $subActs  = [];
                                                foreach ($selected as $uri) {
                                                    foreach ($subActsByPatho[$uri][$act] ?? [] as $sn => $eq)
                                                        if (!isset($subActs[$sn])) $subActs[$sn] = $eq;
                                                }
                                            ?>
                                            <div class="reco-item" style="border-left-color:#c4b5fd">
                                                <div class="reco-item-top">
                                                    <div class="reco-name" style="color:#5b21b6"><?= h(prettyLabel($act)) ?></div>
                                                    <?php if ($compatiblesStr !== ''): ?>
                                                        <span class="patho-tag" style="background:#f5f3ff;color:#5b21b6;border-color:#ddd6fe" title="Pathologies avec lesquelles cette activité est compatible"><?= h($compatiblesStr) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($adapts) || !empty($actMods)): ?>
                                                    <div class="act-param-mods" style="margin-top:6px">
                                                        <?php if (!empty($adapts)): ?>
                                                            <div class="act-mod-row act-mod-row-eapa">
                                                                <span class="act-mod-key act-mod-key-eapa">Suggestions EAPA</span>
                                                                <span class="act-mod-val act-mod-val-eapa"><?= h(implode(' — ', array_map('prettyLabel', $adapts))) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($actMods)): ?>
                                                        <span class="morganne-label">Suggestions Morganne</span>
                                                        <?php foreach ($actMods as $prop => $vals): ?>
                                                            <div class="act-mod-row act-mod-row-morganne">
                                                                <span class="act-mod-icon"><?= modalityIcon($prop) ?></span>
                                                                <span class="act-mod-key"><?= h(modalityLabel($prop)) ?></span>
                                                                <span class="act-mod-val"><?= h(implode(' / ', array_map('prettyLabel', $vals))) ?><?php if (str_starts_with($prop,'aPour1RM')): ?><span class="mod-unit"> %</span><?php endif; ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($subActs)): ?>
                                                    <details class="subacts-details">
                                                        <summary class="subacts-toggle"><?= count($subActs) ?> exercice<?= count($subActs)>1?'s':'' ?> spécifique<?= count($subActs)>1?'s':'' ?></summary>
                                                        <div class="subacts-grid-r">
                                                            <?php foreach ($subActs as $subName => $equips): ?>
                                                                <div class="subact-r">
                                                                    <div class="subact-r-name"><?= h(prettyLabel($subName)) ?></div>
                                                                    <?php if (!empty($equips)): ?>
                                                                        <div class="subact-equips">
                                                                            <?php foreach ($equips as $eq): ?>
                                                                                <span class="equip-chip">🎒 <?= h(prettyLabel($eq)) ?></span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span style="font-size:10px;color:var(--muted);font-style:italic">Sans matériel</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>

                        <!-- ── Activités bloquées par CI globale ─────────── -->
                        <?php if (!empty($blockedRecos)): ?>
                        <div class="blocked-section">
                            <div class="blocked-section-title">
                                 <?= count($blockedRecos) ?> activité<?= count($blockedRecos)>1?'s':'' ?> supprimée<?= count($blockedRecos)>1?'s':'' ?> des recommandations
                            </div>
                            <div class="blocked-list">
                                <?php foreach ($blockedRecos as $bItem): ?>
                                    <div class="blocked-item">
                                        <span class="blocked-name"><?= h(prettyLabel($bItem['activity'])) ?></span>
                                        <?php foreach (($bItem['blockReasons'] ?? []) as $reason): ?>
                                            <span class="blocked-reason">bloqué par <?= h($reason['patho']) ?> (contre-indique : <?= h(prettyLabel($reason['ci'])) ?>)</span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── COLONNE DROITE : CI (rouge) + Adaptations & Paramètres ── -->
                <div class="report-col report-col-stack">

                    <!-- ── Contre-indications — style index.php ─────────── -->
                    <div class="ci-col-card">
                        <div class="col-header-r col-header-ci-r">
                             ACTIVITÉS CONTRE-INDIQUÉES
                            <?php if (!empty($finalContra)): ?>
                                <span class="ci-badge-r"><?= count($finalContra) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($blockedRecos)): ?>
                            <div class="ci-blocked-note-r">
                                 <?= count($blockedRecos) ?> activité<?= count($blockedRecos)>1?'s':'' ?> retirée<?= count($blockedRecos)>1?'s':'' ?> des recommandations
                            </div>
                        <?php endif; ?>
                        <div class="ci-col-body-r">
                            <?php if (empty($finalContra)): ?>
                                <p class="ci-empty-r">Aucune contre-indication formelle.</p>
                            <?php else: ?>
                                <?php foreach ($finalContra as $c => $pathoLbls): ?>
                                    <div class="ci-row-r">
                                        <div class="ci-name-r2"><?= h(prettyLabel($c)) ?></div>
                                        <?php if (count($selected) > 1 && !empty($pathoLbls)): ?>
                                            <div class="ci-ptags-r">
                                                <?php foreach ($pathoLbls as $pl): ?>
                                                    <span class="ci-ptag-r"><?= h($pl) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif (count($selected) === 1): ?>
                                            <div class="ci-ptags-r">
                                                <span class="ci-ptag-r"><?= h($pathologyLabels[$selected[0]] ?? '') ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>



                </div>

            </div>
        </div><!-- /content -->
    </div><!-- /main-layout -->
</div><!-- /container -->

<script>
// Accordéons exercices
document.querySelectorAll('.subacts-details').forEach(d => {
    d.addEventListener('toggle', () => {
        const body = d.querySelector('.subacts-grid-r');
        if (body && d.open) body.style.animation = 'fadeIn .2s ease';
    });
});
</script>
<style>
@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
</style>

</body>
</html>