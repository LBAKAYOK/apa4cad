<?php
/**
 * APA4CAD - Module 2 : Détail enrichi d'une prescription (v2)
 *
 * Cette version utilise les MÊMES fonctions SPARQL que rapport.php pour
 * garantir un affichage identique de Suggestion EAPA + Suggestion Morganne.
 *
 * Les fonctions sont copiées intégralement depuis rapport.php pour éviter
 * la duplication d'effort de mise à jour.
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/praticien_session.php';

// ─── Vérification login praticien ────────────────────────────────────────
if (!isPraticienLoggedIn()) {
    header('Location: login_praticien.php');
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
//  FONCTIONS COPIÉES DE rapport.php
// ═════════════════════════════════════════════════════════════════════════

const FUSEKI_ENDPOINT_DETAIL = 'https://fuseki-apa4cad.onrender.com/mononto/query';
const NS_DETAIL = 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';

function sparqlQueryR(string $query): array {
    $url = FUSEKI_ENDPOINT_DETAIL . '?query=' . urlencode($query) . '&output=json';
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/sparql-results+json\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['ok' => false, 'bindings' => []];
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']['bindings']))
        return ['ok' => false, 'bindings' => []];
    return ['ok' => true, 'bindings' => $data['results']['bindings']];
}

function localNameR(string $uri): string {
    if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
    if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
    return $uri;
}

function prettyLabelR(string $name): string {
    $name = str_replace('_', ' ', $name);
    $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    return trim((string)$name);
}

function hR(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function categoryTitleR(string $local): string {
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
        default => prettyLabelR($local),
    };
}

function modalityLabelR(string $prop): string {
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
        default => prettyLabelR($prop),
    };
}

function modalityGroupR(string $prop): int {
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

/** Récupère les activités recommandées + adaptations pour une pathologie (copie de rapport.php) */
function loadRecommendationsR(string $pathologyUri): array {
    $query = '
PREFIX ex:   <' . NS_DETAIL . '>
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

    $result = sparqlQueryR($query);
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

/** Récupère les modalités par activité (Suggestion Morganne) - copie de rapport.php */
function loadModalitiesPerActivityR(array $activityLocalNames): array {
    if (empty($activityLocalNames)) return [];
    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));
    $query = '
PREFIX ex:   <' . NS_DETAIL . '>
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
    UNION {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
  UNION
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
    UNION { ?restriction owl:hasValue ?value . FILTER(isIRI(?value))
      BIND(STRAFTER(STR(?value), "#") AS ?valueName) }
    UNION {
      ?restriction owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
      { ?facet xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?facet xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) }
    }
  }
}
ORDER BY ?actName ?prop ?valueName';

    $result = sparqlQueryR($query);
    if (!$result['ok']) return [];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $act  = $row['actName']['value'] ?? '';
        $prop = $row['prop']['value']    ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($act === '' || $prop === '' || $val === '') continue;
        $items[$act][$prop][] = $val;
    }

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

        // ─── Fusion 1RM_Bas_min/max et 1RM_Haut_min/max en plage avec unité % ───
        foreach ([
            'aPour1RM_Bas'  => ['aPour1RM_Bas_min',  'aPour1RM_Bas_max'],
            'aPour1RM_Haut' => ['aPour1RM_Haut_min', 'aPour1RM_Haut_max'],
        ] as $merged => $parts) {
            if (isset($props[$parts[0]]) || isset($props[$parts[1]])) {
                $min = $props[$parts[0]][0] ?? null;
                $max = $props[$parts[1]][0] ?? null;
                $range = ($min !== null && $max !== null) ? $min . ' – ' . $max . ' %'
                       : (($min ?? $max) . ' %');
                $props[$merged] = [$range];
                unset($props[$parts[0]], $props[$parts[1]]);
            }
        }

        uksort($props, fn($a,$b) => modalityGroupR($a) <=> modalityGroupR($b));
    }
    unset($props);
    return $items;
}

function formatDateD(string $iso): string {
    if ($iso === '') return '—';
    try { return (new DateTime($iso))->format('d/m/Y à H:i'); }
    catch (Exception $e) { return $iso; }
}

// ═════════════════════════════════════════════════════════════════════════
//  TRAITEMENT
// ═════════════════════════════════════════════════════════════════════════

$id = trim($_GET['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    die('ID de prescription manquant.');
}

$prescriptionUri = NS_DETAIL . $id;

// Requête principale
$mainQuery = sparqlPrefixes() . "
    SELECT ?patient ?nom ?prenom ?age ?dossier ?genreLabel ?date
           ?praticien ?pratPrenom ?pratNom WHERE {
        <$prescriptionUri> a ex:Prescription .
        OPTIONAL { <$prescriptionUri> ex:concerne ?patient .
                   OPTIONAL { ?patient ex:aPourNom ?nom }
                   OPTIONAL { ?patient ex:aPourPrenom ?prenom }
                   OPTIONAL { ?patient ex:aPourAge ?age }
                   OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
                   OPTIONAL { ?patient ex:aPourGenre ?genre .
                              BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
        }
        OPTIONAL { <$prescriptionUri> ex:aPourDate ?date }
        OPTIONAL { <$prescriptionUri> ex:prescritPar ?praticien .
                   OPTIONAL { ?praticien ex:aPourPrenom ?pratPrenom }
                   OPTIONAL { ?praticien ex:aPourNom    ?pratNom }
        }
    }
";
$mainRows = sparqlQueryR($mainQuery)['bindings'] ?? [];
if (empty($mainRows)) {
    http_response_code(404);
    die('Prescription introuvable : ' . hR($id));
}
$m = $mainRows[0];

// Comments (résumé IA + CI)
$commentsQuery = sparqlPrefixes() . "
    SELECT ?comment WHERE {
        <$prescriptionUri> rdfs:comment ?comment .
        FILTER(lang(?comment) = \"fr\")
    }
";
$resumeText = '';
$contraindications = [];
$freinsList = [];
$leviersList = [];
foreach (sparqlQueryR($commentsQuery)['bindings'] ?? [] as $r) {
    $txt = $r['comment']['value'] ?? '';
    if ($txt === '') continue;
    if (str_starts_with($txt, '[CI]')) {
        $clean = trim(substr($txt, 4));
        $parts = preg_split('/\s+—\s+bloquée par\s+/u', $clean, 2);
        $contraindications[] = [
            'activity' => trim($parts[0] ?? $clean),
            'reasons'  => trim($parts[1] ?? ''),
        ];
    } elseif (str_starts_with($txt, '[FREIN]')) {
        $freinLabel = trim(substr($txt, 7));
        if ($freinLabel !== '' && !in_array($freinLabel, $freinsList, true)) {
            $freinsList[] = $freinLabel;
        }
    } elseif (str_starts_with($txt, '[LEVIER]')) {
        $levierLabel = trim(substr($txt, 8));
        if ($levierLabel !== '' && !in_array($levierLabel, $leviersList, true)) {
            $leviersList[] = $levierLabel;
        }
    } else {
        $resumeText = ($resumeText === '') ? $txt : ($resumeText . "\n\n" . $txt);
    }
}

$presc = [
    'uri'         => $prescriptionUri,
    'id'          => $id,
    'date'        => $m['date']['value']       ?? '',
    'resume'      => $resumeText,
    'patient_uri' => $m['patient']['value']    ?? '',
    'nom'         => $m['nom']['value']        ?? '',
    'prenom'      => $m['prenom']['value']     ?? '',
    'age'         => $m['age']['value']        ?? '',
    'dossier'     => $m['dossier']['value']    ?? '',
    'genre'       => $m['genreLabel']['value'] ?? '',
    'praticien_uri'    => $m['praticien']['value']  ?? '',
    'praticien_prenom' => $m['pratPrenom']['value'] ?? '',
    'praticien_nom'    => $m['pratNom']['value']    ?? '',
];

// Nom affiché du praticien (avec fallback)
$praticienDisplay = trim($presc['praticien_prenom'] . ' ' . $presc['praticien_nom']);
if ($praticienDisplay === '') {
    $praticienDisplay = 'Auteur non spécifié';
    $praticienHasInfo = false;
} else {
    $praticienHasInfo = true;
}

// Pathologies du patient
$pathologies = [];
if ($presc['patient_uri'] !== '') {
    $pathoQuery = sparqlPrefixes() . "
        SELECT DISTINCT ?patho WHERE {
            <{$presc['patient_uri']}> ex:aPourPathologie ?patho .
        }
    ";
    foreach (sparqlQueryR($pathoQuery)['bindings'] ?? [] as $r) {
        $uri = $r['patho']['value'];
        $local = localNameR($uri);
        $pathologies[] = [
            'uri'   => $uri,
            'local' => $local,
            'label' => categoryTitleR($local),
        ];
    }
}

// Charger pour chaque pathologie : recos (avec adaptations) du rapport.php
$recsByPatho = [];
foreach ($pathologies as $p) {
    $recsByPatho[$p['uri']] = loadRecommendationsR($p['uri']); // [['activity'=>..., 'adaptations'=>[...]]]
}

// Activités prescrites (ex:contient)
$actsQuery = sparqlPrefixes() . "
    SELECT DISTINCT ?activiteDerivee ?activiteType WHERE {
        <$prescriptionUri> ex:contient ?activiteDerivee .
        OPTIONAL {
            ?activiteDerivee rdf:type ?activiteType .
            FILTER(?activiteType != owl:NamedIndividual)
        }
    }
";
$prescribedActivities = [];
$seenActs = [];
foreach (sparqlQueryR($actsQuery)['bindings'] ?? [] as $r) {
    $derivedUri = $r['activiteDerivee']['value'] ?? '';
    $typeUri    = $r['activiteType']['value'] ?? '';
    if ($derivedUri === '' || isset($seenActs[$derivedUri])) continue;
    $seenActs[$derivedUri] = true;

    $localType = $typeUri !== '' ? localNameR($typeUri) : localNameR($derivedUri);
    $prescribedActivities[] = [
        'derived'  => $derivedUri,
        'local'    => $localType,
        'label'    => prettyLabelR($localType),
    ];
}

// Charger les modalités Morganne pour TOUTES les activités prescrites
$allActivityNames = array_map(fn($a) => $a['local'], $prescribedActivities);
$modalitiesPerAct = loadModalitiesPerActivityR($allActivityNames);

// ── Charger les LEVIERS SPÉCIFIQUES par activité (nouveau format) ─────────
// Format : un triplet `<activiteDerivee> ex:aPourLevierApplique <levier>` par couple
// On indexe sur l'URI dérivée pour les associer aux bonnes activités prescrites
$leviersByDerivedUri = [];
if (!empty($prescribedActivities)) {
    $derivedUris = array_values(array_map(fn($a) => $a['derived'], $prescribedActivities));
    $valuesList = '<' . implode('> <', $derivedUris) . '>';
    $lvQuery = "
        PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX ex:   <" . NS_DETAIL . ">
        SELECT ?act ?levier WHERE {
            VALUES ?act { $valuesList }
            ?act ex:aPourLevierApplique ?levier .
        }
    ";
    foreach (sparqlQueryR($lvQuery)['bindings'] ?? [] as $r) {
        $actUri = $r['act']['value']    ?? '';
        $lvUri  = $r['levier']['value'] ?? '';
        if ($actUri === '' || $lvUri === '') continue;
        $lvLabel = prettyLabelR(localNameR($lvUri));
        if (!isset($leviersByDerivedUri[$actUri])) $leviersByDerivedUri[$actUri] = [];
        if (!in_array($lvLabel, $leviersByDerivedUri[$actUri], true))
            $leviersByDerivedUri[$actUri][] = $lvLabel;
    }
}

// Pour chaque activité prescrite, fusionner adaptations + sources + modalités + leviers spécifiques
foreach ($prescribedActivities as &$act) {
    $adaptations = [];
    $sources     = [];
    foreach ($pathologies as $p) {
        foreach ($recsByPatho[$p['uri']] ?? [] as $rec) {
            if ($rec['activity'] === $act['local']) {
                if (!in_array($p['label'], $sources, true)) $sources[] = $p['label'];
                foreach ($rec['adaptations'] as $a) {
                    $aPretty = prettyLabelR($a);
                    if (!in_array($aPretty, $adaptations, true)) $adaptations[] = $aPretty;
                }
            }
        }
    }
    $act['adaptations']  = $adaptations;
    $act['source_patho'] = $sources;
    $act['modalities']   = $modalitiesPerAct[$act['local']] ?? [];
    // Leviers spécifiques à CETTE activité (vide si vieille prescription au format global)
    $act['leviers_appliques'] = $leviersByDerivedUri[$act['derived']] ?? [];
}
unset($act);

$patientName = trim($presc['prenom'] . ' ' . $presc['nom']) ?: '(patient anonyme)';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Prescription - <?= hR($patientName) ?> - APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}
button{font-family:inherit;cursor:pointer}

/* TOPBAR */
.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 0}
.topbar-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:32px}
.topbar-brand{font-weight:700;font-size:17px;color:#1d4ed8;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#1d4ed8;border-radius:2px;display:inline-block}
.topbar-nav{display:flex;gap:6px;margin-left:auto}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#475569;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:#f1f5f9;color:#1e293b}
.topbar-nav a.active{background:#eff6ff;color:#1d4ed8;font-weight:600}

.app{max-width:1200px;margin:0 auto;padding:32px 24px 80px}

/* BANNIÈRE */
.banner{background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;
        border-radius:18px;padding:30px 34px;margin-bottom:28px;
        box-shadow:0 14px 28px rgba(37,99,235,.18)}
.banner .crumbs{font-size:12px;opacity:.85;margin-bottom:8px}
.banner .crumbs a{color:#fff;opacity:.9}
.banner .crumbs .sep{margin:0 6px;opacity:.6}
.banner h1{margin:0 0 6px;font-size:28px;font-weight:700;letter-spacing:-0.02em}
.banner .prat-line{font-size:14px;color:rgba(255,255,255,.92);margin-bottom:14px;
                     display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.banner .prat-line .prat-label{opacity:.8;font-weight:400}
.banner .prat-line .prat-name{color:#fff;font-weight:700;
                                background:rgba(255,255,255,.12);
                                border:1px solid rgba(255,255,255,.2);
                                padding:3px 12px;border-radius:50px;font-size:13px}
.banner .prat-line .prat-name-missing{font-style:italic;opacity:.7;background:transparent;border-style:dashed}
.banner .meta{display:flex;gap:14px;margin-top:4px;font-size:14px;flex-wrap:wrap}
.banner .meta span,.banner .meta a{display:inline-flex;align-items:center;gap:6px;
                                    background:rgba(255,255,255,.18);padding:5px 12px;
                                    border-radius:999px;border:1px solid rgba(255,255,255,.3);
                                    color:#fff;text-decoration:none;font-weight:500}
.banner .meta a:hover{background:rgba(255,255,255,.32)}
.banner .actions{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}
.banner .actions .btn-action{background:#fff;color:#1d4ed8;border:none;border-radius:10px;
                              padding:9px 18px;font-weight:700;font-size:13px;
                              text-decoration:none;display:inline-block;transition:.15s}
.banner .actions .btn-action:hover{box-shadow:0 4px 12px rgba(0,0,0,.15);transform:translateY(-1px)}
.banner .actions .btn-action.btn-primary{background:#10b981;color:#fff;
                                          box-shadow:0 4px 12px rgba(16,185,129,.3)}
.banner .actions .btn-action.btn-primary:hover{background:#059669;
                                                box-shadow:0 6px 16px rgba(16,185,129,.4)}
.banner .actions .btn-action.btn-new{background:rgba(255,255,255,.18);color:#fff;
                                      border:1.5px solid rgba(255,255,255,.4);margin-left:auto}
.banner .actions .btn-action.btn-new:hover{background:rgba(255,255,255,.28);
                                            border-color:rgba(255,255,255,.6)}

/* CARTES */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
      padding:24px 26px;box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:18px}
.card h2{margin:0 0 18px;font-size:16px;font-weight:700;color:#1e293b;
         letter-spacing:-0.01em;display:flex;align-items:center;gap:8px}
.card h2 .count{color:#9ca3af;font-weight:400;font-size:14px}

/* Cards compactes en sidebar (gauche) */
.card-compact{padding:18px 20px;margin-bottom:14px}
.card-compact h3{margin:0 0 12px;font-size:13px;font-weight:800;
                  color:#374151;text-transform:uppercase;letter-spacing:.5px;
                  display:flex;align-items:center;gap:6px}
.card-compact h3 .count{color:#9ca3af;font-weight:500;font-size:12px;text-transform:none;letter-spacing:0}

/* Layout 2 colonnes : sidebar à gauche (compact) + content à droite (large) */
.main-layout{display:grid;grid-template-columns:300px 1fr;gap:22px;align-items:start}
@media(max-width:1000px){.main-layout{grid-template-columns:1fr}}

.sidebar{position:sticky;top:16px}
.main-content{min-width:0}

.grid-top{display:grid;grid-template-columns:1fr 1.4fr;gap:22px;margin-bottom:22px}
@media(max-width:900px){.grid-top{grid-template-columns:1fr}}

.info-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;gap:10px}
.info-row:last-child{border-bottom:none}
.info-row .lbl{color:#6b7280;flex-shrink:0}
.info-row .val{color:#1e293b;font-weight:600;text-align:right}
.info-row .dossier{font-family:ui-monospace,monospace;background:#f1f5f9;
                    padding:3px 8px;border-radius:5px;font-size:12px}

.patho-list{display:flex;flex-wrap:wrap;gap:6px}
.patho-tag{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;
            border-radius:999px;padding:5px 12px;font-weight:600;font-size:12px}

/* Cards colorées par catégorie */
.card-warn{border-left:4px solid #f59e0b;background:linear-gradient(to right,#fffbeb,#fff)}
.card-warn h3{color:#92400e}
.card-success{border-left:4px solid #10b981;background:linear-gradient(to right,#ecfdf5,#fff)}
.card-success h3{color:#047857}
.card-danger{border-left:4px solid #ef4444;background:linear-gradient(to right,#fef2f2,#fff)}
.card-danger h3{color:#b91c1c}

/* Listes à puces compactes */
.bullet-list{margin:0;padding:0;list-style:none}
.bullet-list li{padding:7px 0 7px 22px;font-size:13px;color:#1e293b;
                 border-bottom:1px solid #f1f5f9;position:relative;line-height:1.4}
.bullet-list li:last-child{border-bottom:none}
.bullet-list li::before{content:"•";position:absolute;left:6px;color:#9ca3af;font-weight:700}

.card-intro{font-size:12px;color:#6b7280;font-style:italic;margin:0 0 10px}

.ci-list-compact{margin:0;padding:0;list-style:none}
.ci-list-compact li{padding:9px 0;border-bottom:1px solid #fee2e2;font-size:13px}
.ci-list-compact li:last-child{border-bottom:none}
.ci-list-compact .ci-act{font-weight:700;color:#7f1d1d;display:block}
.ci-list-compact .ci-reasons{font-size:11px;color:#991b1b;margin-top:2px}

.empty-sm{color:#9ca3af;font-style:italic;font-size:12px;padding:6px 0}
.empty{color:#9ca3af;font-style:italic;font-size:14px;padding:20px;text-align:center}

.act-card-full{background:#f0fdf4;border:1.5px solid #6ee7b7;border-radius:12px;
               padding:18px 20px;margin-bottom:14px}
.act-card-full .act-header{margin-bottom:14px}
.act-card-full .act-title{font-weight:800;color:#065f46;font-size:17px}
.act-card-full .act-sources{font-size:11px;color:#475569;margin-top:4px}
.act-card-full .act-sources strong{color:#475569}

.suggest-eapa{background:linear-gradient(90deg,#dcfce7,#f0fdf4);
              border:1px solid #a7f3d0;border-left:4px solid #059669;
              border-radius:8px;padding:10px 14px;margin-bottom:12px;
              display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.suggest-eapa-label{color:#064e3b;font-weight:800;font-size:11px;
                     text-transform:uppercase;letter-spacing:.5px}
.suggest-eapa-val{font-weight:800;color:#064e3b;background:#bbf7d0;border-radius:5px;
                   padding:3px 10px;font-size:12px;border:1px solid #6ee7b7}

.morganne-section{margin-top:10px}
.morganne-label{display:block;margin:8px 0 8px;font-size:11px;font-weight:700;color:#334155}
.morganne-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;
               margin-left:14px}
.morganne-row{display:flex;align-items:center;justify-content:space-between;gap:8px;
              background:#fbfcfd;border:1px dashed #cbd5e1;border-radius:6px;padding:6px 10px}
.morganne-key{font-size:11px;color:#475569;font-weight:600;flex:1}
.morganne-val{font-size:12px;font-weight:700;color:#334155;background:#f1f5f9;
              border:1px solid #cbd5e1;border-radius:5px;padding:2px 8px;flex-shrink:0}

/* Activités contre-indiquées : ligne plate (nom à gauche, patho à droite) */
.ci-list{display:flex;flex-direction:column;gap:8px}
.ci-row{display:flex;align-items:center;justify-content:space-between;gap:12px;
         background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;
         border-radius:8px;padding:10px 14px}
.ci-row .ci-act-name{font-size:13px;font-weight:700;color:#b91c1c;flex:1;min-width:0}
.ci-row .ci-patho-tag{font-size:11px;color:#b91c1c;background:#fee2e2;border:1px solid #fca5a5;
                       border-radius:5px;padding:3px 9px;font-weight:600;white-space:nowrap;flex-shrink:0}

/* Freins du patient (jaune/orange) */
.freins-list{display:flex;flex-direction:column;gap:8px}
.frein-row{display:flex;align-items:center;gap:12px;
            background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #b45309;
            border-radius:8px;padding:10px 14px}
.frein-row .frein-icon{font-size:18px;flex-shrink:0}
.frein-row .frein-name{font-size:13px;font-weight:700;color:#78350f;flex:1}

/* Leviers de motivation (vert) */
.leviers-list{display:flex;flex-direction:column;gap:8px}
.levier-row{display:flex;align-items:center;gap:12px;
             background:#f0fdf4;border:1px solid #86efac;border-left:3px solid #047857;
             border-radius:8px;padding:10px 14px}
.levier-row .levier-icon{font-size:18px;flex-shrink:0}
.levier-row .levier-name{font-size:13px;font-weight:700;color:#065f46;flex:1}

/* Bandeau explicatif (pour freins/leviers) */
.section-intro{font-size:13px;color:#6b7280;margin:0 0 14px;line-height:1.5}

.summary-box{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;
              padding:18px 22px;line-height:1.6;font-size:14px;white-space:pre-wrap}

.empty{color:#9ca3af;font-style:italic;padding:14px;text-align:center}

@media print{
    .topbar{display:none}
    body{background:#fff}
    .banner{background:#1d4ed8 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .banner .actions{display:none}
    .card{box-shadow:none;border:1px solid #ccc;page-break-inside:avoid}
}

/* ─── Bloc "Leviers appliqués" à l'intérieur de chaque activité ─── */
.leviers-appliques{
    margin-top:14px;padding:12px 14px;
    background:linear-gradient(135deg,#f0fdf4,#ecfdf5);
    border:1px solid #a7f3d0;border-left:4px solid #10b981;
    border-radius:10px;
}
.leviers-appliques-title{
    display:flex;align-items:center;gap:8px;
    font-size:11px;font-weight:800;color:#065f46;
    text-transform:uppercase;letter-spacing:.5px;
    margin-bottom:8px;
}
.leviers-appliques-icon{
    width:20px;height:20px;border-radius:50%;
    background:#10b981;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:800;
}
.leviers-appliques-chips{
    display:flex;flex-wrap:wrap;gap:6px;
}
.levier-chip-applied{
    display:inline-flex;align-items:center;gap:4px;
    background:#fff;border:1.5px solid #6ee7b7;color:#065f46;
    font-size:12px;font-weight:600;
    border-radius:14px;padding:4px 12px;
}
.levier-chip-applied::before{content:"✓";color:#10b981;font-weight:800}
@media print{
    .leviers-appliques{background:#fff !important;border:1px solid #ccc !important}
    .levier-chip-applied{background:#fff !important}
}
</style>
</head>
<body>

<div class="app">

    <section class="banner">
        <div class="crumbs">
            <a href="prescriptions.php">Mes prescriptions</a><span class="sep">›</span>
            <?php if ($presc['patient_uri'] !== ''):
                $patientFrag = localNameR($presc['patient_uri']); ?>
                <a href="patient_detail.php?id=<?= urlencode($patientFrag) ?>"><?= hR($patientName) ?></a>
            <?php else: ?>
                <span><?= hR($patientName) ?></span>
            <?php endif; ?>
            <span class="sep">›</span>
            <span>Prescription du <?= hR(formatDateD($presc['date'])) ?></span>
        </div>
        <h1>Prescription du <?= hR(formatDateD($presc['date'])) ?></h1>
        <div class="prat-line">
            👤 <span class="prat-label">Prescrit par :</span>
            <strong class="prat-name<?= $praticienHasInfo ? '' : ' prat-name-missing' ?>">
                <?= hR($praticienDisplay) ?>
            </strong>
        </div>
        <div class="meta">
            <?php if ($presc['patient_uri'] !== ''):
                $patientFrag = localNameR($presc['patient_uri']); ?>
                <a href="patient_detail.php?id=<?= urlencode($patientFrag) ?>" title="Ouvrir le dossier patient">
                    👤 <?= hR($patientName) ?>
                </a>
            <?php else: ?>
                <span>👤 <?= hR($patientName) ?></span>
            <?php endif; ?>
            <?php if ($presc['age'] !== ''): ?><span> <?= hR($presc['age']) ?> ans</span><?php endif; ?>
            <?php if ($presc['genre'] !== ''): ?><span> <?= hR($presc['genre']) ?></span><?php endif; ?>
            <?php if ($presc['dossier'] !== ''): ?><span> <?= hR($presc['dossier']) ?></span><?php endif; ?>
        </div>
        <div class="actions">
            <a href="resume.php?prescription_id=<?= urlencode($id) ?>" class="btn-action btn-primary">
                📋 Voir le formulaire de synthèse
            </a>
            <a href="prescriptions.php" class="btn-action">← Mes prescriptions</a>
            <?php if ($presc['patient_uri'] !== ''): ?>
                <a href="patient_detail.php?id=<?= urlencode(localNameR($presc['patient_uri'])) ?>" class="btn-action">
                    📁 Dossier patient
                </a>
            <?php endif; ?>
            <a href="javascript:window.print()" class="btn-action">🖨️ Imprimer</a>
            <a href="index.php?restart=1" class="btn-action btn-new">
                ➕ Nouvelle prescription
            </a>
        </div>
    </section>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <!-- Stepper "lecture seule" : toutes les étapes validées (✓ vert)    -->
    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <style>
    .stepper-read{display:flex;align-items:center;justify-content:center;gap:6px;
                   background:#fff;border:1px solid #e5e7eb;border-radius:14px;
                   padding:14px 18px;margin-bottom:22px;flex-wrap:wrap;
                   box-shadow:0 1px 3px rgba(15,23,42,.04)}
    .stepper-read-tag{font-size:10px;font-weight:800;color:#059669;
                       background:#dcfce7;border:1px solid #6ee7b7;
                       padding:4px 10px;border-radius:50px;
                       text-transform:uppercase;letter-spacing:.6px;margin-right:8px}
    .stepper-read-step{display:flex;align-items:center;gap:8px;padding:7px 14px;
                         border-radius:50px;background:#dcfce7;color:#065f46;
                         border:1px solid #6ee7b7;font-size:13px;font-weight:700;
                         transition:.15s}
    .stepper-read-check{width:18px;height:18px;border-radius:50%;background:#10b981;color:#fff;
                          display:flex;align-items:center;justify-content:center;
                          font-size:11px;font-weight:800;flex-shrink:0}
    .stepper-read-arrow{color:#94a3b8;font-size:14px;margin:0 2px}
    @media(max-width:740px){
        .stepper-read{padding:12px}
        .stepper-read-step{font-size:11px;padding:5px 10px}
        .stepper-read-tag{margin-right:0;margin-bottom:4px}
    }
    @media print {.stepper-read{display:none}}
    </style>
    <div class="stepper-read">
        <span class="stepper-read-tag">✓ Prescription enregistrée</span>
        <span class="stepper-read-step"><span class="stepper-read-check">✓</span>Pathologies</span>
        <span class="stepper-read-arrow">→</span>
        <span class="stepper-read-step"><span class="stepper-read-check">✓</span>Recommandations</span>
        <span class="stepper-read-arrow">→</span>
        <span class="stepper-read-step"><span class="stepper-read-check">✓</span>Patient</span>
        <span class="stepper-read-arrow">→</span>
        <span class="stepper-read-step"><span class="stepper-read-check">✓</span>Freins/Leviers</span>
        <span class="stepper-read-arrow">→</span>
        <span class="stepper-read-step"><span class="stepper-read-check">✓</span>Résumé IA</span>
    </div>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <!-- LAYOUT 2 COLONNES : Infos contextuelles | Activités prescrites    -->
    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <div class="main-layout">

      <!-- ─────────── COLONNE GAUCHE : Contexte clinique ─────────── -->
      <aside class="sidebar">

        <!-- Pathologies -->
        <div class="card card-compact">
            <h3>🩺 Pathologies <span class="count">(<?= count($pathologies) ?>)</span></h3>
            <?php if (empty($pathologies)): ?>
                <div class="empty-sm">Aucune pathologie</div>
            <?php else: ?>
                <div class="patho-list">
                    <?php foreach ($pathologies as $p): ?>
                        <span class="patho-tag"><?= hR($p['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Freins -->
        <?php if (!empty($freinsList)): ?>
        <div class="card card-compact card-warn">
            <h3>⚠️ Freins identifiés <span class="count">(<?= count($freinsList) ?>)</span></h3>
            <ul class="bullet-list">
                <?php foreach ($freinsList as $frein): ?>
                    <li><?= hR($frein) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Contre-indications -->
        <?php if (!empty($contraindications)): ?>
        <div class="card card-compact card-danger">
            <h3>⛔ Contre-indications <span class="count">(<?= count($contraindications) ?>)</span></h3>
            <p class="card-intro">Écartées en raison des pathologies du patient.</p>
            <ul class="ci-list-compact">
                <?php foreach ($contraindications as $ci): ?>
                    <li>
                        <span class="ci-name"><?= hR($ci['activity']) ?></span>
                        <?php if (!empty($ci['reasons'])): ?>
                            <span class="ci-reason"><?= hR($ci['reasons']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

      </aside>

      <!-- ─────────── COLONNE DROITE : Activités prescrites ─────────── -->
      <main class="main-content">

        <div class="card">
            <h2> Activités prescrites
                <span class="count">(<?= count($prescribedActivities) ?>)</span>
            </h2>
            <?php if (empty($prescribedActivities)): ?>
                <div class="empty">Aucune activité enregistrée pour cette prescription.</div>
            <?php else: ?>
                <?php foreach ($prescribedActivities as $act): ?>
                    <div class="act-card-full">
                        <div class="act-header">
                            <div class="act-title"> <?= hR($act['label']) ?></div>
                            <?php if (!empty($act['source_patho'])): ?>
                                <div class="act-sources">
                                    <strong>Recommandée par :</strong>
                                    <?= hR(implode(', ', $act['source_patho'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($act['adaptations'])): ?>
                            <div class="suggest-eapa">
                                <span class="suggest-eapa-label">Suggestions EAPA</span>
                                <?php foreach ($act['adaptations'] as $adap): ?>
                                    <span class="suggest-eapa-val"><?= hR($adap) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($act['modalities'])): ?>
                            <div class="morganne-section">
                                <span class="morganne-label">Suggestions Morganne</span>
                                <div class="morganne-grid">
                                    <?php foreach ($act['modalities'] as $prop => $vals):
                                        $displayVal = implode(' / ', array_map('prettyLabelR', $vals));
                                    ?>
                                        <div class="morganne-row">
                                            <span class="morganne-key"><?= hR(modalityLabelR($prop)) ?></span>
                                            <span class="morganne-val"><?= hR($displayVal) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($act['leviers_appliques'])): ?>
                            <div class="leviers-appliques">
                                <div class="leviers-appliques-title">
                                    <span class="leviers-appliques-icon">✓</span>
                                    Leviers appliqués à cette activité
                                </div>
                                <div class="leviers-appliques-chips">
                                    <?php foreach ($act['leviers_appliques'] as $levier): ?>
                                        <span class="levier-chip-applied"><?= hR($levier) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

      </main>
    </div>
    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->

    <!-- ━━━ RÉSUMÉ IA (interactif) ━━━ -->
    <div class="card" id="resume-card">
        <h2 style="display:flex;justify-content:space-between;align-items:center;gap:12px">
            <span> RÉSUMÉ IA</span>
            <button id="btn-generer-resume"
                    onclick="genererResume()"
                    style="background:#2563eb;color:#fff;border:none;border-radius:9px;
                           padding:9px 18px;font-weight:700;font-size:13px;cursor:pointer;
                           transition:.15s">
                <?= $presc['resume'] !== '' ? '↻ Regénérer le résumé' : ' Générer le résumé IA' ?>
            </button>
        </h2>

        <!-- Zone d'état (loading, erreur) -->
        <div id="resume-status" style="display:none;padding:14px 18px;border-radius:10px;
                                        margin-bottom:14px;font-size:13px"></div>

        <!-- Zone du résumé -->
        <div id="resume-content">
            <?php if ($presc['resume'] !== ''): ?>
                <div class="summary-box" id="resume-text"><?= hR($presc['resume']) ?></div>
            <?php else: ?>
                <div class="summary-box" id="resume-text" style="text-align:center;color:#6b7280;font-style:italic">
                    Aucun résumé IA n'a encore été généré pour cette prescription.
                    <br><br>
                    Cliquez sur <strong>«  Générer le résumé IA »</strong> pour créer un compte rendu
                    structuré à partir des données enregistrées (patient, pathologies, activités, freins/leviers, contre-indications).
                </div>
            <?php endif; ?>
        </div>
    </div>

<script>
async function genererResume() {
    const btn = document.getElementById('btn-generer-resume');
    const status = document.getElementById('resume-status');
    const textBox = document.getElementById('resume-text');

    // Confirmation si regénération
    const hasExisting = <?= $presc['resume'] !== '' ? 'true' : 'false' ?>;
    if (hasExisting && !confirm('Regénérer le résumé remplacera celui existant. Continuer ?')) {
        return;
    }

    // UI : loading
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'wait';
    btn.textContent = '⏳ Génération en cours...';
    status.style.display = 'block';
    status.style.background = '#eff6ff';
    status.style.color = '#1d4ed8';
    status.style.border = '1px solid #bfdbfe';
    status.innerHTML = '⏳ <strong>Génération en cours...</strong> Cela peut prendre 30 à 90 secondes selon votre matériel.';

    try {
        const response = await fetch('generer_resume.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                prescription_id: <?= json_encode($id) ?>
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Erreur inconnue');
        }

        // Affiche le résumé
        textBox.textContent = data.resume;
        textBox.style.textAlign = 'left';
        textBox.style.color = '';
        textBox.style.fontStyle = '';

        // Succès
        const seconds = (data.duration_ms / 1000).toFixed(1);
        status.style.background = '#ecfdf5';
        status.style.color = '#047857';
        status.style.border = '1px solid #a7f3d0';
        status.innerHTML = `✅ <strong>Résumé généré en ${seconds}s</strong> · ${data.nb_activites} activité(s), ${data.nb_freins} frein(s), ${data.nb_leviers} levier(s), ${data.nb_ci} CI`;

        btn.textContent = '↻ Regénérer le résumé';
        setTimeout(() => { status.style.display = 'none'; }, 6000);

    } catch (err) {
        status.style.background = '#fef2f2';
        status.style.color = '#b91c1c';
        status.style.border = '1px solid #fca5a5';
        status.innerHTML = '❌ <strong>Erreur :</strong> ' + (err.message || err);
        btn.textContent = hasExisting ? '↻ Réessayer' : ' Réessayer';
    } finally {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
}
</script>

</div>
</body>
</html>
