<?php
declare(strict_types=1);

require_once __DIR__ . '/patient_session.php';
require_once __DIR__ . '/praticien_session.php';
require_once __DIR__ . '/sparql_update.php'; // pour ex:ExclusionPathologieConsultation

// ─── DÉTECTION DU MODE EXPLORATION (lecture seule, sans prescription) ─────
if (isset($_GET['mode']) && $_GET['mode'] === 'explore') {
    $_SESSION['explore_mode'] = true;
}
if (isset($_GET['exit_explore'])) {
    unset($_SESSION['explore_mode']);
    header('Location: welcome.php');
    exit;
}
$EXPLORE_MODE = !empty($_SESSION['explore_mode'] ?? false);

// ─── REDIRECTION VERS WELCOME au premier accès ────────────────────────────
// On redirige vers welcome.php uniquement si :
//   - aucun paramètre GET n'est présent
//   - aucun parcours en session
//   - le mode exploration n'est pas actif
//   - l'utilisateur n'a pas explicitement demandé d'aller à l'index (from_welcome=1)
$_isFirstAccess = empty($_GET)
              && empty($_SESSION['parcours_pathologies'] ?? [])
              && !$EXPLORE_MODE
              && empty($_SESSION['welcome_seen'] ?? false);
if ($_isFirstAccess) {
    header('Location: welcome.php');
    exit;
}
if (isset($_GET['from_welcome'])) {
    $_SESSION['welcome_seen'] = true;
}

// ─── VÉRIFICATION DU LOGIN PRATICIEN ──────────────────────────────────────
// Si on n'est PAS en mode exploration, le login praticien est obligatoire.
// (Le mode exploration reste accessible librement pour les démos / formations.)
if (!$EXPLORE_MODE && !isPraticienLoggedIn()) {
    header('Location: login_praticien.php');
    exit;
}

// Si l'utilisateur clique sur "Recommencer", on vide la session de parcours
if (isset($_GET['restart'])) {
    clearParcours();
    header('Location: index.php' . ($EXPLORE_MODE ? '?mode=explore' : ''));
    exit;
}

const FUSEKI_ENDPOINT = 'https://fuseki-apa4cad.onrender.com/mononto/query';
const NS = 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';

function sparqlQuery(string $query): array
{
    $url = FUSEKI_ENDPOINT . '?query=' . urlencode($query) . '&output=json';

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/sparql-results+json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';

    if ($response === false) {
        return ['ok' => false, 'error' => "Impossible de contacter Fuseki. Vérifie que Fuseki est lancé sur http://localhost:3030."];
    }

    if ($statusLine !== '' && !str_contains($statusLine, '200')) {
        return ['ok' => false, 'error' => 'Fuseki a renvoyé une erreur HTTP : ' . $statusLine, 'raw' => $response];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']['bindings'])) {
        return ['ok' => false, 'error' => "Réponse invalide reçue depuis Fuseki.", 'raw' => $response];
    }

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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function categoryTitle(string $local): string
{
    return match ($local) {
        // Racines (groupes non-sélectionnables)
        'AffectionDeLongueDuree'         => 'Affections de longue durée',
        'PathologieCardiaque'            => 'Pathologies cardiaques',
        'PathologieDigestive'            => 'Pathologies digestives',
        'PathologieMusculosquelettique'  => 'Pathologies musculosquelettiques',
        'PathologieRespiratoire'         => 'Pathologies respiratoires',
        // Intermédiaires cardiaques
        'PathologieCoronarienne'         => 'Pathologies coronariennes',
        'CardiopathiesInflammatoires'    => 'Cardiopathies inflammatoires',
        'CardiopathiesStructurelle'      => 'Cardiopathies structurelles',
        'CoronaropathieChronique'        => 'Coronaropathie chronique',
        'CoronaropathieFonctionnelle'    => 'Coronaropathie fonctionnelle',
        'SyndromeCoronarienAigu'         => 'Syndrome coronarien aigu',
        // Intermédiaires ALD
        'Diabete'                        => 'Diabète',
        // Intermédiaires musculosquelettiques
        'Arthrose'                       => 'Arthrose',
        // Feuilles cardiaques coronariennes
        'AngorStable'                    => 'Angor stable',
        'AngorInstable'                  => 'Angor instable',
        'CoronaropathieAsymptomatique'   => 'Coronaropathie asymptomatique',
        'IschemieMyocardiqueStable'      => 'Ischémie myocardique stable',
        'SpasmeCoronarien'               => 'Spasme coronarien',
        'InfarctusDuMyocarde'            => 'Infarctus du myocarde',
        // Cardiopathies inflammatoires
        'Endocardite'                    => 'Endocardite',
        'Myocardite'                     => 'Myocardite',
        'Pericardite'                    => 'Péricardite',
        // ALD feuilles
        'Cancer'                         => 'Cancer',
        'Hypertension_arterielle'        => 'Hypertension artérielle',
        'Obesite'                        => 'Obésité',
        'DT1'                            => 'Diabète de type 1',
        'DT2'                            => 'Diabète de type 2',
        // Musculosquelettiques feuilles
        'ArthroseCervicale'              => 'Arthrose cervicale',
        'ArthroseEpaule'                 => 'Arthrose de l\'épaule',
        'ArthroseGenou'                  => 'Arthrose du genou',
        'ArthroseHanche'                 => 'Arthrose de la hanche',
        'Lombalgie'                      => 'Lombalgie',
        'Menisectomie'                   => 'Méniscectomie',
        // Respiratoires feuilles
        'ApneeDuSommeil'                 => 'Apnée du sommeil',
        'BronchopneumopathieChroniqueObstructive' => 'BPCO',
        // Digestives feuilles
        'Diastasis'                      => 'Diastasis',
        'Eventration'                    => 'Éventration',
        'HernieInguinale'                => 'Hernie inguinale',
        // Actes médicaux
        'PontageAortoCoronarien'         => 'Pontage aorto-coronarien',
        'AngioplastieAvecStent'          => 'Angioplastie avec stent',
        default => prettyLabel($local),
    };
}

/**
 * Construit la hiérarchie complète des pathologies sous forme d'arbre récursif.
 * Utilise les relations rdfs:subClassOf directes ET via intersection OWL.
 */
function anyDescendantSelected(array $node, array $selected): bool
{
    foreach ($node['children'] as $child) {
        if (in_array($child['uri'], $selected, true)) return true;
        if (anyDescendantSelected($child, $selected)) return true;
    }
    return false;
}

function loadHierarchy(): array
{
    // Récupère toutes les paires parent-enfant directes dans le namespace
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT DISTINCT ?child ?parent
WHERE {
  {
    # Héritage direct vers classe nommée
    ?child rdfs:subClassOf ?parent .
    FILTER(isIRI(?parent))
    FILTER(STRSTARTS(STR(?child),  "' . NS . '"))
    FILTER(STRSTARTS(STR(?parent), "' . NS . '"))
    FILTER(?child != ?parent)
  }
  UNION
  {
    # Héritage via intersection OWL
    ?child rdfs:subClassOf ?anon .
    FILTER(isBlank(?anon))
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?parent .
    FILTER(isIRI(?parent))
    FILTER(STRSTARTS(STR(?child),  "' . NS . '"))
    FILTER(STRSTARTS(STR(?parent), "' . NS . '"))
    FILTER(?child != ?parent)
  }
}
';

    $result = sparqlQuery($query);
    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'], 'roots' => [], 'all' => []];
    }

    // Adjacence : parent → {enfant → true}
    $childrenOf = [];
    foreach ($result['bindings'] as $row) {
        $child  = $row['child']['value']  ?? '';
        $parent = $row['parent']['value'] ?? '';
        if ($child === '' || $parent === '' || $child === $parent) continue;
        $childrenOf[$parent][$child] = true;
    }

    // Les 5 racines (groupes non-sélectionnables affiché comme titres)
    $topRootUris = [
        NS . 'AffectionDeLongueDuree',
        NS . 'PathologieCardiaque',
        NS . 'PathologieDigestive',
        NS . 'PathologieMusculosquelettique',
        NS . 'PathologieRespiratoire',
    ];

    $all          = [];   // uri => ['uri','local','label']  pour les sélectionnables
    $globalSeen   = [];   // évite les doublons dans l'arbre

    // Constructeur récursif
    $buildNode = function(string $uri, bool $isTopRoot) use (
        &$buildNode, &$childrenOf, &$all, &$globalSeen, $topRootUris
    ): ?array {
        if (isset($globalSeen[$uri]) && !$isTopRoot) return null;
        $globalSeen[$uri] = true;

        $local = localName($uri);
        $label = categoryTitle($local);

        // Enfants triés par URI puis relabelisés
        $rawChildren = array_keys($childrenOf[$uri] ?? []);
        sort($rawChildren);

        $childNodes = [];
        foreach ($rawChildren as $childUri) {
            if (!str_starts_with($childUri, NS)) continue;
            $cn = $buildNode($childUri, false);
            if ($cn !== null) $childNodes[] = $cn;
        }
        usort($childNodes, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

        if (!$isTopRoot) {
            $all[$uri] = ['uri' => $uri, 'local' => $local, 'label' => $label];
        }

        return [
            'uri'       => $uri,
            'local'     => $local,
            'label'     => $label,
            'children'  => $childNodes,
            'isTopRoot' => $isTopRoot,
        ];
    };

    $roots = [];
    foreach ($topRootUris as $rootUri) {
        $node = $buildNode($rootUri, true);
        if ($node !== null) $roots[] = $node;
    }
    usort($roots, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

    return ['ok' => true, 'roots' => $roots, 'all' => $all];
}

/**
 * Rendu récursif d'un nœud de l'arbre des pathologies.
 *  - Racine (isTopRoot) : <details class="root"> sans checkbox
 *  - Nœud intermédiaire avec enfants : <details class="sub-root"> avec checkbox
 *  - Feuille : <div class="path-item"> avec checkbox
 */
function renderTreeNode(array $node, array $selected, int $depth = 0): void
{
    $uri        = $node['uri'];
    $label      = $node['label'];
    $hasChildren = !empty($node['children']);
    $isTopRoot  = $node['isTopRoot'] ?? false;
    $isSelected = in_array($uri, $selected, true);
    $openAttr   = ($isSelected || anyDescendantSelected($node, $selected)) ? ' open' : '';

    $sl  = h(strtolower($label));   // pour data-label (recherche)
    $hl  = h($label);
    $hu  = h($uri);
    $chk = $isSelected ? ' checked' : '';

    if ($isTopRoot) {
        // ── Groupe racine : pas de checkbox ──────────────────────────────
        echo "<details class=\"root\"$openAttr>\n";
        echo "  <summary>$hl</summary>\n";
        echo "  <div class=\"sublist\">\n";
        foreach ($node['children'] as $child) {
            renderTreeNode($child, $selected, $depth + 1);
        }
        echo "  </div>\n";
        echo "</details>\n";

    } elseif ($hasChildren) {
        // ── Nœud intermédiaire : PAS de checkbox, uniquement accordéon ───
        // L'utilisateur doit dérouler pour accéder aux sous-pathologies
        echo "<details class=\"sub-root\" data-label=\"$sl\"$openAttr>\n";
        echo "  <summary class=\"sub-summary\">\n";
        echo "    <span class=\"sub-name\">$hl</span>\n";
        echo "    <span class=\"sub-arrow\">›</span>\n";
        echo "  </summary>\n";
        echo "  <div class=\"sublist sublist-nested\">\n";
        foreach ($node['children'] as $child) {
            renderTreeNode($child, $selected, $depth + 1);
        }
        echo "  </div>\n";
        echo "</details>\n";

    } else {
        // ── Feuille : checkbox simple ────────────────────────────────────
        echo "<div class=\"path-item\" data-label=\"$sl\">\n";
        echo "  <label>\n";
        echo "    <input class=\"auto-submit\" type=\"checkbox\" name=\"pathologies[]\" value=\"$hu\"$chk>\n";
        echo "    <span>$hl</span>\n";
        echo "  </label>\n";
        echo "</div>\n";
    }
}

function loadRecommendationsForPathology(string $pathologyUri): array
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
ORDER BY ?nomActivite
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    // Grouper par activité : une seule entrée par activité, toutes ses adaptations dans un tableau
    $grouped = [];
    foreach ($result['bindings'] as $row) {
        $activity   = $row['nomActivite']['value'] ?? '';
        $adaptation = $row['adaptation']['value'] ?? '';
        if ($activity === '') continue;
        if (!isset($grouped[$activity])) {
            $grouped[$activity] = ['activity' => $activity, 'adaptations' => []];
        }
        if ($adaptation !== '' && !in_array($adaptation, $grouped[$activity]['adaptations'], true)) {
            $grouped[$activity]['adaptations'][] = $adaptation;
        }
    }
    return ['ok' => true, 'items' => array_values($grouped)];
}

function loadContraindicationsForPathology(string $pathologyUri): array
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
ORDER BY ?nomElement
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $value = $row['nomElement']['value'] ?? '';
        if ($value !== '') $items[$value] = $value;
    }
    return ['ok' => true, 'items' => array_values($items)];
}

function loadModalitiesForPathology(string $pathologyUri): array
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
    ex:aPourIntensite
    ex:aPourFrequence
    ex:aPourFrequenceHebdomadaire
    ex:aPourDuree
    ex:aPourDureeHebdomadaire
    ex:aPourDureeParEtirement
    ex:aPourNbRepetitions
    ex:aPourNbSeries
    ex:aPourNbExercices
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
    # someValuesFrom IRI (ex: BorgFaible, SansImpact...)
    ?restriction owl:someValuesFrom ?value .
    FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    # hasValue IRI (ex: BorgModere)
    ?restriction owl:hasValue ?value .
    FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    # hasValue littéral (ex: 1RM = 60.0, fréquence = "par semaine")
    ?restriction owl:hasValue ?value .
    FILTER(isLiteral(?value))
    BIND(STR(?value) AS ?valueName)
  }
  UNION
  {
    # someValuesFrom datatype range : min/max entiers
    ?restriction owl:someValuesFrom ?dt .
    FILTER(isBlank(?dt))
    ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
    {
      ?facet xsd:minInclusive ?minVal .
      BIND(CONCAT("min:", STR(?minVal)) AS ?valueName)
    }
    UNION
    {
      ?facet xsd:maxInclusive ?maxVal .
      BIND(CONCAT("max:", STR(?maxVal)) AS ?valueName)
    }
  }
}
ORDER BY ?prop ?valueName
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $prop = $row['prop']['value'] ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($prop === '' || $val === '') continue;
        $items[$prop][] = $val;
    }

    // Fusionner les paires min:/max: en une plage lisible "X – Y"
    foreach ($items as $prop => &$vals) {
        $vals  = array_values(array_unique($vals));
        $mins  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'min:')));
        $maxs  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'max:')));
        $rest  = array_values(array_filter($vals, fn($v) => !str_starts_with($v, 'min:') && !str_starts_with($v, 'max:')));
        if (!empty($mins) || !empty($maxs)) {
            $minVal = !empty($mins) ? substr($mins[0], 4) : null;
            $maxVal = !empty($maxs) ? substr($maxs[0], 4) : null;
            $range  = ($minVal !== null && $maxVal !== null)
                ? $minVal . ' – ' . $maxVal
                : ($minVal ?? $maxVal);
            $vals = array_merge($rest, [$range]);
        }
    }
    unset($vals);

    return ['ok' => true, 'items' => $items];
}

function loadEquipmentForActivities(array $activityLocalNames): array
{
    if (empty($activityLocalNames)) return ['ok' => true, 'items' => []];

    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));

    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>

SELECT DISTINCT ?actName ?equipment
WHERE {
  VALUES ?activity { ' . $values . ' }
  BIND(STRAFTER(STR(?activity), "#") AS ?actName)
  {
    # Equipement direct (IRI simple)
    ?activity rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?r .
    ?r owl:onProperty ex:aBesoinDe ;
       owl:someValuesFrom ?eq .
    FILTER(isIRI(?eq))
    FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
    BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
  }
  UNION
  {
    # Equipement via union de classes
    ?activity rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?r .
    ?r owl:onProperty ex:aBesoinDe ;
       owl:someValuesFrom ?eqBlank .
    FILTER(isBlank(?eqBlank))
    ?eqBlank owl:unionOf/rdf:rest*/rdf:first ?eq .
    FILTER(isIRI(?eq))
    FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
    BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
  }
}
ORDER BY ?actName ?equipment
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    // Classes à exclure (pas des équipements réels)
    $skip = ['ActivitePhysique', 'Pathologie', 'Adaptation', 'Frein',
             'DispositifMedical', 'ActiviteEndurance', 'ActiviteIntensiveHaute',
             'Equipement_de_sport'];
    $items = [];
    foreach ($result['bindings'] as $row) {
        $act   = $row['actName']['value']   ?? '';
        $equip = $row['equipment']['value'] ?? '';
        if ($act === '' || $equip === '') continue;
        if (in_array($equip, $skip, true)) continue;
        $items[$act][] = $equip;
    }
    foreach ($items as &$vals) {
        $vals = array_values(array_unique($vals));
    }
    unset($vals);
    return ['ok' => true, 'items' => $items];
}

function loadPrecautionsForPathology(string $pathologyUri): array
{
    $query = '
PREFIX ex:   <' . NS . '>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>

SELECT DISTINCT ?precaution
WHERE {
  VALUES ?patho { <' . $pathologyUri . '> }
  {
    # aPourAdaptation direct sur la pathologie
    ?patho rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?r .
    ?r owl:onProperty ex:aPourAdaptation ;
       owl:someValuesFrom ?val .
    FILTER(isIRI(?val))
    BIND(STRAFTER(STR(?val), "#") AS ?precaution)
  }
  UNION
  {
    # aPourAdaptation via héritage
    ?patho rdfs:subClassOf+ ?super .
    ?super rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?r .
    ?r owl:onProperty ex:aPourAdaptation ;
       owl:someValuesFrom ?val .
    FILTER(isIRI(?val))
    BIND(STRAFTER(STR(?val), "#") AS ?precaution)
  }
}
ORDER BY ?precaution
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    $items = [];
    foreach ($result['bindings'] as $row) {
        $v = $row['precaution']['value'] ?? '';
        if ($v !== '') $items[$v] = $v;
    }
    return ['ok' => true, 'items' => array_values($items)];
}

function loadModalitiesForActivities(array $activityLocalNames): array
{
    if (empty($activityLocalNames)) return ['ok' => true, 'items' => []];

    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));

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
    ex:aPourIntensite
    ex:aPourFrequence
    ex:aPourFrequenceHebdomadaire
    ex:aPourDuree
    ex:aPourDureeHebdomadaire
    ex:aPourDureeParEtirement
    ex:aPourNbRepetitions
    ex:aPourNbSeries
    ex:aPourNbExercices
    ex:aPour1RM_Bas_min ex:aPour1RM_Bas_max
    ex:aPour1RM_Haut_min ex:aPour1RM_Haut_max
  }
  BIND(STRAFTER(STR(?targetProp), "#") AS ?prop)

  ?activity rdfs:subClassOf ?expr .
  ?expr owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?restriction .
  ?restriction owl:onProperty ?targetProp .

  {
    ?restriction owl:someValuesFrom ?value .
    FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    ?restriction owl:hasValue ?value .
    FILTER(isIRI(?value))
    BIND(STRAFTER(STR(?value), "#") AS ?valueName)
  }
  UNION
  {
    ?restriction owl:hasValue ?value .
    FILTER(isLiteral(?value))
    BIND(STR(?value) AS ?valueName)
  }
  UNION
  {
    ?restriction owl:someValuesFrom ?dt .
    FILTER(isBlank(?dt))
    ?dt owl:withRestrictions/rdf:rest*/rdf:first ?facet .
    {
      ?facet xsd:minInclusive ?minVal .
      BIND(CONCAT("min:", STR(?minVal)) AS ?valueName)
    }
    UNION
    {
      ?facet xsd:maxInclusive ?maxVal .
      BIND(CONCAT("max:", STR(?maxVal)) AS ?valueName)
    }
  }
}
ORDER BY ?actName ?prop
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    $items = []; // actName => [ prop => [values] ]
    foreach ($result['bindings'] as $row) {
        $act  = $row['actName']['value']   ?? '';
        $prop = $row['prop']['value']      ?? '';
        $val  = $row['valueName']['value'] ?? '';
        if ($act === '' || $prop === '' || $val === '') continue;
        $items[$act][$prop][] = $val;
    }

    // Fusionner min:/max: en plage lisible
    foreach ($items as &$props) {
        foreach ($props as $prop => &$vals) {
            $vals  = array_values(array_unique($vals));
            $mins  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'min:')));
            $maxs  = array_values(array_filter($vals, fn($v) => str_starts_with($v, 'max:')));
            $rest  = array_values(array_filter($vals, fn($v) => !str_starts_with($v, 'min:') && !str_starts_with($v, 'max:')));
            if (!empty($mins) || !empty($maxs)) {
                $minVal = !empty($mins) ? substr($mins[0], 4) : null;
                $maxVal = !empty($maxs) ? substr($maxs[0], 4) : null;
                $range  = ($minVal !== null && $maxVal !== null)
                    ? $minVal . ' – ' . $maxVal
                    : ($minVal ?? $maxVal);
                $vals = array_merge($rest, [$range]);
            }
        }
        unset($vals);
    }
    unset($props);

    return ['ok' => true, 'items' => $items];
}

function loadSubActivitiesForActivities(array $activityLocalNames): array
{
    if (empty($activityLocalNames)) return ['ok' => true, 'items' => []];

    $values = implode("\n    ", array_map(fn($n) => 'ex:' . $n, $activityLocalNames));

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
    # sous-activité via subClassOf direct
    ?subAct rdfs:subClassOf+ ?parent .
    FILTER(STRSTARTS(STR(?subAct), "' . NS . '"))
  }
  UNION
  {
    # sous-activité via intersection OWL (1 saut)
    ?subAct rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?parent .
    FILTER(isIRI(?parent))
    FILTER(STRSTARTS(STR(?subAct), "' . NS . '"))
  }
  UNION
  {
    # sous-activité via intermédiaire + intersection
    ?mid rdfs:subClassOf+ ?parent .
    FILTER(STRSTARTS(STR(?mid), "' . NS . '"))
    ?subAct rdfs:subClassOf ?anon .
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?mid .
    FILTER(isIRI(?mid))
    FILTER(STRSTARTS(STR(?subAct), "' . NS . '"))
  }

  OPTIONAL {
    {
      ?subAct rdfs:subClassOf ?anon2 .
      ?anon2 owl:intersectionOf/rdf:rest*/rdf:first ?r .
      ?r owl:onProperty ex:aBesoinDe ;
         owl:someValuesFrom ?eq .
      FILTER(isIRI(?eq))
      FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
      BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
    }
    UNION
    {
      ?subAct rdfs:subClassOf ?anon2 .
      ?anon2 owl:intersectionOf/rdf:rest*/rdf:first ?r .
      ?r owl:onProperty ex:aBesoinDe ;
         owl:someValuesFrom ?eqBlank .
      FILTER(isBlank(?eqBlank))
      ?eqBlank owl:unionOf/rdf:rest*/rdf:first ?eq .
      FILTER(isIRI(?eq))
      FILTER(STRSTARTS(STR(?eq), "' . NS . '"))
      BIND(STRAFTER(STR(?eq), "#") AS ?equipment)
    }
  }

  # Exclure les nœuds intermédiaires qui ont eux-mêmes des sous-classes
  FILTER NOT EXISTS {
    ?child rdfs:subClassOf ?subAct .
    FILTER(STRSTARTS(STR(?child), "' . NS . '"))
  }
  FILTER NOT EXISTS {
    ?child rdfs:subClassOf ?anon3 .
    ?anon3 owl:intersectionOf/rdf:rest*/rdf:first ?subAct .
    FILTER(isIRI(?subAct))
    FILTER(STRSTARTS(STR(?child), "' . NS . '"))
  }
}
ORDER BY ?parentName ?subAct
';

    $result = sparqlQuery($query);
    if (!$result['ok']) return ['ok' => false, 'items' => []];

    $skip = ['ActivitePhysique','Pathologie','Adaptation','Frein','DispositifMedical','Equipement_de_sport'];
    $items = []; // parentName => [ subActName => [equip1, equip2...] ]

    foreach ($result['bindings'] as $row) {
        $parent = $row['parentName']['value'] ?? '';
        $subUri = $row['subAct']['value']     ?? '';
        $equip  = $row['equipment']['value']  ?? '';
        if ($parent === '' || $subUri === '') continue;

        $subName = localName($subUri);
        if (in_array($subName, $skip, true)) continue;

        $items[$parent][$subName] ??= [];
        if ($equip !== '' && !in_array($equip, $skip, true)) {
            $items[$parent][$subName][] = $equip;
        }
    }
    // Dédupliquer équipements
    foreach ($items as &$subs) {
        foreach ($subs as &$equips) {
            $equips = array_values(array_unique($equips));
        }
        unset($equips);
    }
    unset($subs);

    return ['ok' => true, 'items' => $items];
}



// ── Fusion 1RM min/max en plage lisible ───────────────────────────────────
function merge1RM(array &$items): void
{
    foreach ($items as &$vals) {
        if (!is_array($vals)) continue;
        foreach (['aPour1RM_Bas' => ['aPour1RM_Bas_min','aPour1RM_Bas_max'],
                  'aPour1RM_Haut'=> ['aPour1RM_Haut_min','aPour1RM_Haut_max']] as $merged => $parts) {
            if (isset($vals[$parts[0]]) || isset($vals[$parts[1]])) {
                $min = $vals[$parts[0]][0] ?? null;
                $max = $vals[$parts[1]][0] ?? null;
                if ($min !== null && $max !== null)
                    $vals[$merged] = [$min . ' – ' . $max . ' %'];
                elseif ($min !== null)
                    $vals[$merged] = [$min . ' %'];
                elseif ($max !== null)
                    $vals[$merged] = [$max . ' %'];
                unset($vals[$parts[0]], $vals[$parts[1]]);
            }
        }
    }
    unset($vals);
}
function chooseRestrictiveValue(array $values, string $type): array
{

    if ($type === 'aPourIntensite') {
        $rank = function(string $v): int {
            $x = strtolower($v);
            if (str_contains($x, 'pas')) return 0;
            if (str_contains($x, 'faible')) return 1;
            if (str_contains($x, 'modere')) return 2;
            if (str_contains($x, 'intense')) return 4;
            return 3;
        };
        usort($values, fn($a, $b) => $rank($a) <=> $rank($b));
        return [$values[0]];
    }

    if (in_array($type, ['aPourDuree','aPourDureeHebdomadaire','aPourFrequence','aPourFrequenceHebdomadaire'], true)) {
        $withNum = [];
        foreach ($values as $v) {
            if (preg_match('/(\d+)/', $v, $m)) $withNum[$v] = (int)$m[1];
        }
        if (!empty($withNum)) {
            asort($withNum);
            return [array_key_first($withNum)];
        }
    }

    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return [$values[0]];
}

function modalityLabel(string $prop): string
{
    return match ($prop) {
        'aPourIntensite'             => 'Intensité',
        'aPourFrequence'             => 'Fréquence',
        'aPourFrequenceHebdomadaire' => 'Fréquence hebdomadaire',
        'aPourDuree'                 => 'Durée',
        'aPourDureeHebdomadaire'     => 'Durée hebdomadaire',
        'aPourUniteDuree'            => 'Unité de durée',
        'aPourNbRepetitions'         => 'Nb répétitions',
        'aPourNbSeries'              => 'Nb séries',
        'aPourNbExercices'           => 'Nb exercices',
        'aPourDureeParEtirement'     => 'Durée par étirement (s)',
        'aPour1RM_Bas'               => 'Charge membres inférieurs',
        'aPour1RM_Bas_min'           => 'Charge membres inférieurs',
        'aPour1RM_Bas_max'           => 'Charge membres inférieurs',
        'aPour1RM_Haut'              => 'Charge membres supérieurs',
        'aPour1RM_Haut_min'          => 'Charge membres supérieurs',
        'aPour1RM_Haut_max'          => 'Charge membres supérieurs',
        default => prettyLabel($prop),
    };
}

$tree = loadHierarchy();
$roots = $tree['roots'] ?? [];
$allPathologies = $tree['all'] ?? [];

// ─── Récupération des pathologies à pré-cocher ────────────────────────
// Priorité 1 : URL ?pathologies=... (navigation normale, retour depuis le rapport)
// Priorité 2 : Session $_SESSION['parcours_pathologies'] (venue depuis un dossier patient)
$selected = $_GET['pathologies'] ?? [];
if (!is_array($selected)) $selected = [$selected];
$selected = array_values(array_filter($selected, fn($v) => is_string($v) && $v !== ''));

// Si on vient d'un dossier patient (paramètre ?from_patient=XXX), on hydrate depuis la session
$fromPatientId = $_GET['from_patient'] ?? '';
if ($fromPatientId !== '' && empty($selected) && !empty($_SESSION['parcours_pathologies'] ?? [])) {
    $selected = $_SESSION['parcours_pathologies'];
}

// ─── NOUVEAU : suivi des pathos initialement pré-cochées (pour exclusion ultérieure) ───
// Quand on arrive depuis un patient (?from_patient=XXX), on mémorise la liste ORIGINALE
// des pathos pré-cochées. Si l'utilisateur en décoche après, on demandera un motif.
if ($fromPatientId !== '' && !empty($selected)) {
    // Première arrivée depuis le patient : on snapshote la liste
    if (empty($_SESSION['index_original_prechecked'] ?? [])) {
        $_SESSION['index_original_prechecked'] = $selected;
    }
} elseif (empty($_GET['pathologies']) && empty($selected)) {
    // Sortie complète (plus aucune patho cochée) : reset du snapshot
    unset($_SESSION['index_original_prechecked']);
}
$originalPrechecked = $_SESSION['index_original_prechecked'] ?? [];

// ─── NOUVEAU : enregistrement des motifs d'exclusion reçus en GET ───
// Format attendu : ?excluded_pathos[<uri>]=<motif>
$excludedReceived = $_GET['excluded_pathos'] ?? [];
if (is_array($excludedReceived) && !empty($excludedReceived)
    && !empty($_SESSION['patient_uri'] ?? '')) {
    $patientUriForExcl = $_SESSION['patient_uri'];
    $patientLocal = (str_contains($patientUriForExcl, '#'))
        ? substr($patientUriForExcl, strrpos($patientUriForExcl, '#') + 1) : 'Patient';
    $now = date('Y-m-d\TH:i:s');

    foreach ($excludedReceived as $excludedUri => $motif) {
        $excludedUri = (string)$excludedUri;
        $motif       = trim((string)$motif);
        if ($excludedUri === '' || $motif === '') continue;
        if (!str_starts_with($excludedUri, ONTO_NAMESPACE)) continue; // sécurité

        $pathoLocal = (str_contains($excludedUri, '#'))
            ? substr($excludedUri, strrpos($excludedUri, '#') + 1) : 'Patho';
        $eventFrag = "Exclusion_{$patientLocal}_{$pathoLocal}_" . date('YmdHis')
                      . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $eventUri  = ONTO_NAMESPACE . $eventFrag;
        $motifEsc  = str_replace('"', '\\"', $motif);

        // INSERT direct (sparql_update doit être disponible)
        if (function_exists('sparqlUpdate')) {
            $insert = sparqlPrefixes() . " INSERT DATA {
                <$eventUri> rdf:type owl:NamedIndividual ;
                            rdf:type ex:ExclusionPathologieConsultation ;
                            ex:concerneExclusionPatient <$patientUriForExcl> ;
                            ex:concerneExclusionPathologie <$excludedUri> ;
                            ex:aPourDateExclusion \"$now\"^^xsd:dateTime ;
                            ex:aPourMotifExclusion \"$motifEsc\"@fr .
            }";
            @sparqlUpdate($insert);
        }
        // Retirer la patho exclue de la session courante pour pas qu'elle revienne
        if (!empty($_SESSION['index_original_prechecked'])) {
            $_SESSION['index_original_prechecked'] = array_values(
                array_filter($_SESSION['index_original_prechecked'], fn($u) => $u !== $excludedUri)
            );
        }
    }
}

$resultsByPathology    = [];
$contraByPathology     = [];
$modalitiesByPathology = [];
$modalitiesByActivity  = [];
$equipmentByPathology  = [];
$precautionsByPathology = [];
$subActivitiesByPathology = [];
$commonActivities   = [];
$mergedContra       = [];
$mergedAdaptations  = [];
$mergedModalities   = [];
$selectedCount = count($selected);

if (!empty($selected)) {
    $activitySets = [];

    foreach ($selected as $pathologyUri) {
        $data  = loadRecommendationsForPathology($pathologyUri);
        $contra = loadContraindicationsForPathology($pathologyUri);
        $modal  = loadModalitiesForPathology($pathologyUri);
        $prec   = loadPrecautionsForPathology($pathologyUri);

        $resultsByPathology[$pathologyUri]     = $data['items']  ?? [];
        $contraByPathology[$pathologyUri]      = $contra['items'] ?? [];
        $modalitiesByPathology[$pathologyUri]  = $modal['items'] ?? [];
        $precautionsByPathology[$pathologyUri] = $prec['items']  ?? [];
        $activitySets[$pathologyUri]           = array_map(fn($x) => $x['activity'], $resultsByPathology[$pathologyUri]);

        // Charger les équipements pour les activités recommandées
        $actNames = $activitySets[$pathologyUri];
        $equip = loadEquipmentForActivities($actNames);
        $equipmentByPathology[$pathologyUri] = $equip['items'] ?? [];

        // Charger les modalités par activité
        $actMods = loadModalitiesForActivities($actNames);
        $actModItems = $actMods['items'] ?? [];
        merge1RM($actModItems);
        $modalitiesByActivity[$pathologyUri] = $actModItems;

        // Charger les sous-activités spécifiques avec équipements
        $subActs = loadSubActivitiesForActivities($actNames);
        $subActivitiesByPathology[$pathologyUri] = $subActs['items'] ?? [];

        foreach ($contraByPathology[$pathologyUri] as $c) $mergedContra[$c] = $c;
        foreach ($resultsByPathology[$pathologyUri] as $r) {
            foreach ($r['adaptations'] ?? [] as $adap) {
                $mergedAdaptations[$adap] = $adap;
            }
        }
        foreach ($modalitiesByPathology[$pathologyUri] as $prop => $vals) {
            foreach ($vals as $v) $mergedModalities[$prop][] = $v;
        }
    }

    if ($selectedCount >= 2) {
        $common = null;
        foreach ($activitySets as $activities) {
            $activities = array_values(array_unique($activities));
            $common = $common === null ? $activities : array_values(array_intersect($common, $activities));
        }
        sort($common);
        $commonActivities = $common ?? [];

        foreach ($mergedModalities as $prop => $vals) {
            $mergedModalities[$prop] = chooseRestrictiveValue($vals, $prop);
        }
    } else {
        foreach ($mergedModalities as $prop => $vals) {
            $mergedModalities[$prop] = array_values(array_unique($vals));
        }
    }

    $mergedContra = array_values($mergedContra);
    sort($mergedContra);
    $mergedAdaptations = array_values($mergedAdaptations);
    sort($mergedAdaptations);
}

$contraCount = count($mergedContra);

// ── Détection des conflits croisés ──────────────────────────────────────────
// Une activité est en conflit si elle est recommandée par une pathologie
// ET contre-indiquée par une autre pathologie sélectionnée.
// $blockedByPathology[uri_patho_recommande][activite] = [label_patho_contraindique, ...]
$blockedByPathology = [];
$allBlockedActivities = []; // activité => [label_patho, ...]

if ($selectedCount >= 2) {
    foreach ($selected as $recUri) {
        foreach ($resultsByPathology[$recUri] ?? [] as $item) {
            $act = $item['activity'];
            foreach ($selected as $contraUri) {
                if ($contraUri === $recUri) continue;
                if (in_array($act, $contraByPathology[$contraUri] ?? [], true)) {
                    $contraLabel = isset($allPathologies[$contraUri])
                        ? $allPathologies[$contraUri]['label']
                        : prettyLabel(localName($contraUri));
                    $blockedByPathology[$recUri][$act][] = $contraLabel;
                    $allBlockedActivities[$act][] = $contraLabel;
                }
            }
        }
    }
    foreach ($allBlockedActivities as &$labels) {
        $labels = array_values(array_unique($labels));
    }
    unset($labels);

    // Retirer les activités bloquées des activités communes
    $commonActivities = array_values(
        array_filter($commonActivities, fn($a) => !isset($allBlockedActivities[$a]))
    );
}

$hasContraIndications = !empty($mergedContra);
$hasConflicts         = !empty($allBlockedActivities);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Recommandation d’activités à partir de l’ontologie</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{--bg:#f4f7fb;--card:#fff;--line:#d7e0eb;--text:#1e293b;--muted:#6b7280;--accent:#2563eb;--ok:#047857;--ok-bg:#ecfdf5;--warn:#b45309;--warn-bg:#fff7ed;--alert:#b91c1c;--alertbg:#fff1f2;--danger:#7f1d1d;--danger-bg:#fef2f2;--danger-border:#fca5a5;--conflict:#6b21a8;--conflict-bg:#faf5ff;--conflict-border:#c4b5fd}
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text)}
        .container{max-width:1360px;margin:0 auto;padding:28px 20px 44px}
        .hero{background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;border-radius:22px;padding:32px;box-shadow:0 18px 34px rgba(37,99,235,.18);text-align:center}
        .hero h1{margin:0 0 10px;font-size:36px;line-height:1.2;text-align:center}
        .hero p{margin:0 auto;max-width:980px;font-size:17px;line-height:1.55;text-align:center}
        .layout{margin-top:24px;display:grid;grid-template-columns:420px 1fr;gap:22px;align-items:start}
        main{padding:0 12px}
        .card,.panel,.stat{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:0 10px 22px rgba(15,23,42,.05)}
        .card{padding:22px}.panel{padding:20px;margin-bottom:16px}.stat{padding:20px}
        .card h2{margin:0 0 10px;font-size:24px}.panel h3{margin:0 0 14px;font-size:22px}
        .muted{color:var(--muted);line-height:1.55;font-size:14px}
        .search{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:12px;font-size:14px;margin:12px 0 12px}
        .searchAlert{display:none;border:1px solid #fecaca;background:var(--alertbg);color:var(--alert);border-radius:12px;padding:12px 14px;margin:0 0 12px;font-size:14px}
        .tree{max-height:560px;overflow:auto;padding-right:8px}
        details.root{border:1px solid var(--line);border-radius:14px;background:#fbfdff;margin-bottom:12px;padding:10px 12px}
        details.root summary{cursor:pointer;font-weight:800;color:var(--accent);list-style:none}
        details.root summary::-webkit-details-marker{display:none}
        .sublist{display:grid;gap:8px;margin-top:12px}
        .path-item{border:1px solid var(--line);background:#fff;border-radius:12px;padding:10px 12px}
        .path-item label{display:flex;gap:10px;align-items:center;cursor:pointer;font-size:15px}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:14px}
        button,.secondary{border:none;border-radius:14px;padding:14px 18px;cursor:pointer;font-weight:700;font-size:15px;text-decoration:none;display:inline-block}
        button{background:var(--accent);color:#fff}.secondary{background:#eef2f7;color:var(--text)}
        .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}
        .stat .value{font-size:40px;font-weight:800;color:var(--accent);line-height:1}.stat .label{margin-top:10px;color:var(--muted);font-size:14px}
        .stat.stat-danger .value{color:var(--alert)}.stat.stat-danger{border-color:var(--danger-border);background:var(--danger-bg)}
        .stat.stat-conflict .value{color:var(--conflict)}.stat.stat-conflict{border-color:var(--conflict-border);background:var(--conflict-bg)}
        .badge{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:6px 10px;margin-right:8px;margin-bottom:6px}
        .badge.ok{background:var(--ok-bg);color:var(--ok)}.badge.warn{background:var(--warn-bg);color:var(--warn)}
        .badge.danger{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border)}
        .badge.conflict{background:var(--conflict-bg);color:var(--conflict);border:1px solid var(--conflict-border)}
        .list{display:grid;gap:12px;list-style:none;margin:0;padding:0}
        .item{border:1px solid var(--line);background:#fbfdff;border-radius:14px;padding:14px}
        .item-title{font-weight:800;font-size:17px;margin-bottom:6px}
        .item.item-blocked{border-color:var(--danger-border);background:var(--danger-bg);opacity:.92}
        .item.item-blocked .item-title{color:var(--danger)}
        .item.item-contra{border-color:var(--danger-border);background:var(--danger-bg)}
        .empty{border:1px solid #fde68a;background:#fffbea;color:#92400e;border-radius:14px;padding:16px}
        /* Bannière d'alerte contre-indications */
        .alert-banner{border-radius:16px;padding:18px 20px;margin-bottom:16px;display:flex;gap:14px;align-items:flex-start}
        .alert-banner .alert-icon{font-size:24px;flex-shrink:0;margin-top:2px}
        .alert-banner .alert-body strong{display:block;font-size:16px;margin-bottom:6px}
        .alert-banner .alert-body p{margin:0;font-size:14px;line-height:1.55}
        .alert-banner .contra-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
        .alert-banner.is-contra{border:2px solid var(--danger-border);background:var(--danger-bg);color:var(--danger)}
        .alert-banner.is-conflict{border:2px solid var(--conflict-border);background:var(--conflict-bg);color:var(--conflict)}
        /* Section contra-indications mise en valeur */
        .contra-section{background:var(--danger-bg);border:1.5px solid var(--danger-border);border-radius:14px;padding:14px 16px;margin-top:14px}
        .contra-section h4{margin:0 0 10px;font-size:16px;color:var(--danger);display:flex;align-items:center;gap:8px}
        .focus{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:16px;margin-bottom:16px}
        .focus strong{display:block;margin-bottom:6px}
        .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .footnote{color:var(--muted);font-size:13px;margin-top:10px;line-height:1.5}
        .section-divider{display:flex;align-items:center;gap:20px;margin:32px 0 28px}
        .section-divider-patho{margin:20px 0 4px}
        .divider-label-patho{background:#f1f5f9;border-color:#cbd5e1;color:#475569;font-size:12px;padding:5px 14px}
        .section-divider-patho .divider-line{background:linear-gradient(90deg,transparent,#cbd5e1,transparent)}
        /* Équipements */
        .equip-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0}
        .equip-label{font-size:12px;font-weight:700;color:#475569;white-space:nowrap}
        .badge.equip{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:11px}
        /* Modalités par activité */
        .act-modalities{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0}
        .act-mod-item{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .act-mod-label{font-size:11px;font-weight:700;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:3px 8px;white-space:nowrap}
        /* Sous-activités */
        .subacts-block{margin-top:12px;padding-top:10px;border-top:1px dashed #e2e8f0}
        .subacts-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:8px}
        .subacts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}
        .subact-card{background:#f8faff;border:1px solid #dbeafe;border-radius:10px;padding:10px 12px}
        .subact-name{font-size:13px;font-weight:700;color:#1e3a5f;margin-bottom:6px}
        .subact-equips{display:flex;flex-wrap:wrap;gap:4px}
        .subact-noequip{font-size:11px;color:#6b7280;font-style:italic}
        /* Précautions */
        .precautions-section{background:#fffbeb;border:1.5px solid #fcd34d;border-radius:14px;padding:14px 16px;margin-top:14px}
        .precautions-section h4{margin:0 0 10px;font-size:15px;color:#92400e;display:flex;align-items:center;gap:6px}
        .badge.precaution{background:#fef3c7;color:#78350f;border:1px solid #fcd34d}
        /* Modalités section */
        .modalities-section{background:#f8faff;border:1.5px solid #bfdbfe;border-radius:14px;padding:14px 16px;margin-top:14px}
        .modalities-section h4{margin:0 0 12px;font-size:15px;color:#1e40af;display:flex;align-items:center;gap:6px}
        .divider-line{flex:1;height:2px;background:linear-gradient(90deg,transparent,#c7d7f0,transparent);border-radius:2px}
        .divider-label{white-space:nowrap;font-size:15px;font-weight:700;color:#4b7bb5;background:#e8f0fb;border:1px solid #c7d7f0;border-radius:999px;padding:10px 28px;letter-spacing:.6px;text-transform:uppercase;box-shadow:0 2px 6px rgba(75,123,181,.08)}

        /* ── Feature 1: PDF export ─────────────────────────────────────────── */
        .btn-pdf{background:#fff;color:var(--accent);border:2px solid var(--accent);border-radius:12px;padding:7px 14px;font-weight:700;font-size:13px;cursor:pointer;transition:.15s;white-space:nowrap}
        .btn-pdf:hover{background:var(--accent);color:#fff}

        /* ── Dashboard bar ─────────────────────────────────────────────────── */
        .dashboard-bar{
            background:var(--card);border:1px solid var(--line);border-radius:18px;
            box-shadow:0 10px 22px rgba(15,23,42,.05);
            padding:14px 18px;margin-bottom:16px;
            display:flex;flex-direction:column;gap:10px;
        }

        /* Ligne d'indicateurs chiffrés */
        .db-indicators{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .db-stat{
            display:flex;align-items:baseline;gap:5px;
            background:#f1f5f9;border:1px solid var(--line);
            border-radius:10px;padding:6px 12px;
        }
        .db-stat.db-stat-ok{background:var(--ok-bg);border-color:#6ee7b7}
        .db-stat.db-stat-danger{background:var(--danger-bg);border-color:var(--danger-border)}
        .db-stat.db-stat-conflict{background:var(--conflict-bg);border-color:var(--conflict-border)}
        .db-val{font-size:22px;font-weight:800;color:var(--accent);line-height:1}
        .db-stat.db-stat-ok .db-val{color:var(--ok)}
        .db-stat.db-stat-danger .db-val{color:var(--danger)}
        .db-stat.db-stat-conflict .db-val{color:var(--conflict)}
        .db-lbl{font-size:12px;color:var(--muted);font-weight:500}
        .db-pdf-btn{margin-left:auto}

        /* Lignes chips */
        .db-row{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;padding:6px 0;border-top:1px dashed #e2e8f0}
        .db-row-label{
            font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
            white-space:nowrap;padding:3px 9px;border-radius:6px;border:1px solid;margin-top:1px;
            flex-shrink:0;
        }
        .db-row-ok{background:var(--ok-bg);color:var(--ok);border-color:#6ee7b7}
        .db-row-danger{background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border)}
        .db-row-conflict{background:var(--conflict-bg);color:var(--conflict);border-color:var(--conflict-border)}
        .db-row-mod{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}

        /* Chips */
        .db-chips{display:flex;flex-wrap:wrap;gap:5px;align-items:center;flex:1}
        .chip{
            display:inline-block;font-size:12px;font-weight:600;
            border-radius:6px;padding:3px 9px;border:1px solid;cursor:default;
            white-space:nowrap;
        }
        .chip-ok{background:#f0fdf8;color:#065f46;border-color:#a7f3d0}
        .chip-danger{background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border)}
        .chip-conflict{background:var(--conflict-bg);color:var(--conflict);border-color:var(--conflict-border);cursor:help}
        .chip-mod{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;font-weight:500}
        .chip-mod strong{font-weight:700;margin-right:3px}
        .db-empty{font-size:12px;color:var(--muted);font-style:italic}
        .db-footnote{font-size:11px;color:var(--muted);font-style:italic;margin-top:3px;width:100%;padding-left:0}
        @media print{
            .card,.actions,.search,.searchAlert,.btn-pdf,.section-divider,.stats{display:none!important}
            .layout{display:block}
            .panel{box-shadow:none;border:1px solid #ccc;page-break-inside:avoid;margin-bottom:12px}
            .hero{background:#1d4ed8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;border-radius:0;padding:16px}
            body{background:#fff}
        }
        /* ── Feature 2: Nouveau badge ──────────────────────────────────────── */
        .badge-new{display:inline-block;font-size:10px;font-weight:800;background:#7c3aed;color:#fff;border-radius:6px;padding:2px 7px;margin-left:8px;vertical-align:middle;letter-spacing:.5px;animation:popIn .3s ease}
        @keyframes popIn{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
        /* ── Accordéon par pathologie (header) ────────────────────────────── */
        .panel-accordion{padding:0;overflow:hidden}
        .patho-details{width:100%}
        .patho-summary{list-style:none;display:flex;align-items:center;gap:10px;padding:16px 20px;cursor:pointer;border-bottom:1px solid var(--line);transition:background .15s}
        .patho-summary::-webkit-details-marker{display:none}
        .patho-summary:hover{background:#f8faff}
        .patho-details[open] .patho-summary{border-bottom-color:var(--line);background:#f0f6ff}
        .patho-summary-title{font-size:20px;font-weight:800;flex:1;text-align:center}
        .patho-summary-meta{font-size:13px;color:var(--muted);display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:center}
        .meta-reco{color:var(--ok);font-weight:600}
        .meta-sep{color:#cbd5e1}
        .contra-badge-sm{color:var(--alert);font-weight:700}
        .conflict-badge-sm{color:var(--conflict);font-weight:700}
        .patho-toggle-icon::before{content:"▶";font-size:11px;color:var(--muted);transition:transform .2s}
        .patho-details[open] .patho-toggle-icon::before{transform:rotate(90deg)}
        .patho-toggle-label{font-size:13px;font-weight:600;color:var(--accent);background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:3px 12px;flex-shrink:0;font-size:0}
        .patho-toggle-label::before{font-size:13px}
        details.patho-details[open] .patho-toggle-label::before{content:"Fermer"}
        details.patho-details:not([open]) .patho-toggle-label::before{content:"Ouvrir"}

        /* ── État vide pleine largeur ──────────────────────────────────────── */
        .patho-empty-full{padding:16px 20px;color:var(--muted);font-size:14px}

        /* ── Alerte conflit (barre pleine largeur) ─────────────────────────── */
        .conflict-alert-bar{
            display:flex;align-items:flex-start;gap:10px;
            margin:14px 20px 0;padding:12px 14px;
            background:var(--conflict-bg);border:1.5px solid var(--conflict-border);
            border-radius:12px;font-size:13px;color:var(--conflict);line-height:1.5;
        }

        /* ── Grille 2 colonnes (recommandations | contre-indications) ──────── */
        .patho-grid{
            display:grid;
            grid-template-columns:1fr 260px;
            gap:0;
            padding:0;
        }
        .patho-col{padding:16px 20px}
        .patho-col-reco{border-right:1px solid var(--line)}
        .patho-col-ci{background:#fef9f9}

        /* En-têtes de colonnes */
        .col-header{
            font-size:11px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;
            padding-bottom:10px;margin-bottom:12px;border-bottom:2px solid;
        }
        .col-header-reco{color:var(--ok);border-color:var(--ok)}
        .col-header-ci{color:var(--danger);border-color:var(--danger)}

        /* Texte "colonne vide" */
        .col-empty{color:var(--muted);font-size:13px;font-style:italic;margin:0}

        /* ── Cartes d'activités recommandées ───────────────────────────────── */
        .reco-list{display:flex;flex-direction:column;gap:8px}
        .reco-card{
            background:#f0fdf8;border:1px solid #a7f3d0;
            border-radius:10px;padding:10px 12px;
        }
        .reco-name{font-weight:700;font-size:14px;color:#065f46;margin-bottom:4px}
        .reco-adaptations{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
        .adapt-badge{
            margin:0!important;font-size:11px!important;
            background:#EAF3DE!important;color:#27500A!important;
            border:1px solid #97C459!important;border-radius:6px!important;
            padding:3px 9px!important;font-weight:600!important;
        }
        .reco-mods{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-top:6px;padding-top:6px;border-top:1px dashed #a7f3d0}

        /* Tableau de modalités en colonnes */
        .reco-mods-table{
            display:flex;flex-direction:column;gap:3px;
            margin-top:8px;padding-top:8px;
            border-top:1px dashed #d1fae5;
        }
        /* Suggestions EAPA — prioritaire, bien visible */
        .reco-mod-row-eapa{
            background:linear-gradient(90deg,#dcfce7,#f0fdf4);
            border-radius:7px;margin-bottom:6px;
            border-left:4px solid #059669;
            padding:6px 10px!important;
        }
        .reco-mod-key-eapa{
            color:#064e3b!important;font-weight:800!important;font-size:12px!important;
            text-transform:uppercase;letter-spacing:.5px;
        }
        .reco-mod-val-eapa{
            font-weight:800!important;color:#064e3b!important;
            background:#bbf7d0!important;border-radius:5px;
            padding:2px 9px!important;font-size:12px!important;
            border:1px solid #6ee7b7!important;
        }

        .reco-mod-row{
            display:flex;align-items:center;justify-content:space-between;
            padding:4px 8px;background:#f8fafc;border-radius:6px;
            border:1px solid var(--line);gap:8px;
        }
        .reco-mod-row-morganne{
            background:#fbfcfd;border:1px dashed #cbd5e1;
            padding:3px 8px;
        }
        .reco-mod-row-morganne .reco-mod-key{font-size:11px;color:#475569;font-weight:600}
        .reco-mod-row-morganne .reco-mod-val{
            font-size:12px;font-weight:700;color:#334155;
            background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 8px;
        }
        .reco-mod-key{
            font-size:11px;font-weight:600;color:var(--muted);flex:1;
        }
        .reco-mod-val{
            font-size:12px;font-weight:700;color:#1e3a8a;
            background:#dbeafe;border:1px solid #93c5fd;
            border-radius:5px;padding:2px 8px;white-space:nowrap;
        }

        /* Exercices spécifiques (accordéon dans la carte) */
        .subacts-details{margin-top:6px}
        .subacts-summary{font-size:12px;font-weight:600;color:#1e40af;cursor:pointer;list-style:none;padding:2px 0}
        .subacts-summary::-webkit-details-marker{display:none}

        /* ── Précautions & modalités (bas de col gauche) ───────────────────── */
        .col-extra-block{margin-top:14px;padding-top:12px;border-top:1px dashed #e2e8f0}
        .col-sub-header{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748b;margin-bottom:8px}
        .mods-grid{display:flex;flex-direction:column;gap:6px}
        .mod-entry{display:flex;align-items:center;flex-wrap:wrap;gap:4px}
        .mod-key{font-size:11px;font-weight:700;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:2px 8px;white-space:nowrap}

        /* ── Contre-indications (colonne droite) ───────────────────────────── */
        .ci-list{display:flex;flex-direction:column;gap:6px}
        .ci-item{
            display:flex;align-items:center;gap:8px;
            background:var(--danger-bg);border:1px solid var(--danger-border);
            border-radius:8px;padding:8px 10px;
        }
        .ci-icon{font-size:14px;flex-shrink:0}
        .ci-name{font-size:13px;font-weight:600;color:var(--danger);line-height:1.3}

        /* ── Responsive ────────────────────────────────────────────────────── */
        @media (max-width:980px){
            .layout{grid-template-columns:1fr}
            .stats{grid-template-columns:1fr}
            .grid2{grid-template-columns:1fr}
            .hero h1{font-size:30px}
            .patho-grid{grid-template-columns:1fr}
            .patho-col-reco{border-right:none;border-bottom:1px solid var(--line)}
            .patho-col-ci{background:none}
        }

        /* ── Arbre imbriqué (sous-catégories) ──────────────────────────────── */
        details.sub-root{
            border:1px solid var(--line);border-radius:10px;background:#fff;
            margin-bottom:6px;overflow:hidden;
        }
        details.sub-root summary.sub-summary{
            list-style:none;display:flex;align-items:center;
            padding:9px 12px;cursor:pointer;
            font-weight:700;font-size:14px;color:#334155;
            user-select:none;transition:background .12s;gap:8px;
        }
        details.sub-root summary.sub-summary::-webkit-details-marker{display:none}
        details.sub-root summary.sub-summary:hover{background:#f0f6ff}
        details.sub-root[open] summary.sub-summary{background:#f0f6ff}

        /* Nom de la pathologie (nœud parent — non sélectionnable) */
        .sub-name{flex:1;font-size:14px;font-weight:700;color:#334155}

        /* Flèche d'expansion */
        .sub-arrow{
            font-size:16px;color:var(--muted);font-weight:400;
            transition:transform .2s;flex-shrink:0;line-height:1;
        }
        details.sub-root[open] .sub-arrow{transform:rotate(90deg)}
        .sublist-nested{
            padding:6px 6px 8px 16px;
            border-top:1px solid var(--line);background:#f8faff;
            display:grid;gap:6px;
        }
            @keyframes pulse {
            0%,100% { opacity:1; }
            50%      { opacity:.4; }
        }
        #voiceBtn.listening {
            background:#fee2e2;border-color:#f87171;
            animation:pulse .8s infinite;
            box-shadow:0 0 0 4px rgba(239,68,68,.15);
        }
</style>
</head>
<body>

<?php if (!$EXPLORE_MODE && isPraticienLoggedIn()):
    // Calcul des initiales pour l'avatar
    $_pPrenom  = $_SESSION[PRATICIEN_SESSION_PRENOM] ?? '';
    $_pNom     = $_SESSION[PRATICIEN_SESSION_NOM]    ?? '';
    $_initials = strtoupper(mb_substr($_pPrenom, 0, 1) . mb_substr($_pNom, 0, 1));
?>
<!-- ━━━━━ Topbar praticien (style pro) ━━━━━ -->
<style>
.prat-topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:11px 0;
              box-shadow:0 1px 4px rgba(15,23,42,.04);position:sticky;top:0;z-index:500}
.prat-topbar-inner{max-width:1200px;margin:0 auto;padding:0 24px;
                    display:flex;align-items:center;gap:24px}

/* Logo APA4CAD à gauche */
.prat-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:#0f172a;font-weight:700;font-size:16px}
.prat-logo-icon{width:32px;height:32px;border-radius:8px;
                background:linear-gradient(135deg,#1d4ed8,#3b82f6);
                color:#fff;display:flex;align-items:center;justify-content:center;
                font-weight:800;font-size:14px;box-shadow:0 4px 10px rgba(29,78,216,.3)}

/* Nav centrale */
.prat-nav{display:flex;gap:4px;margin-left:8px}
.prat-nav a{display:flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;
            text-decoration:none;color:#475569;font-weight:600;font-size:13px;transition:.15s}
.prat-nav a:hover{background:#f1f5f9;color:#1d4ed8}
.prat-nav a.active{background:#eff6ff;color:#1d4ed8}
.prat-nav-icon{font-size:14px}

/* Spacer */
.prat-spacer{flex:1}

/* Bouton accueil vert */
.prat-home-btn{background:linear-gradient(135deg,#10b981,#059669);color:#fff;
                padding:8px 16px;border-radius:9px;text-decoration:none;font-weight:700;font-size:13px;
                display:flex;align-items:center;gap:7px;
                box-shadow:0 4px 10px rgba(5,150,105,.3);transition:.15s}
.prat-home-btn:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(5,150,105,.4)}

/* Avatar dropdown */
.prat-account{position:relative}
.prat-avatar-btn{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;
                  padding:5px 12px 5px 5px;border-radius:50px;cursor:pointer;font-family:inherit;
                  font-size:13px;color:#1e293b;font-weight:600;transition:.15s}
.prat-avatar-btn:hover{background:#f1f5f9;border-color:#cbd5e1}
.prat-avatar{width:32px;height:32px;border-radius:50%;
              background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;
              display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px}
.prat-avatar-chevron{font-size:9px;color:#94a3b8;transition:.2s}
.prat-account.open .prat-avatar-chevron{transform:rotate(180deg)}

/* Menu dropdown */
.prat-menu{position:absolute;top:calc(100% + 8px);right:0;background:#fff;
            border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.15);
            min-width:240px;padding:6px;display:none;z-index:600;animation:dropdownIn .15s ease-out}
.prat-account.open .prat-menu{display:block}
@keyframes dropdownIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

.prat-menu-head{padding:14px 14px 12px;border-bottom:1px solid #f1f5f9;margin-bottom:6px}
.prat-menu-name{font-size:13px;font-weight:700;color:#0f172a;line-height:1.2}
.prat-menu-role{font-size:11px;color:#7c3aed;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

.prat-menu a, .prat-menu button{display:flex;align-items:center;gap:10px;padding:9px 12px;
                                  text-decoration:none;color:#1e293b;font-size:13px;font-weight:500;
                                  border-radius:7px;transition:.1s;width:100%;
                                  background:none;border:none;text-align:left;cursor:pointer;font-family:inherit}
.prat-menu a:hover, .prat-menu button:hover{background:#f8fafc;color:#1d4ed8}
.prat-menu-icon{font-size:14px;width:18px;text-align:center}
.prat-menu-divider{height:1px;background:#f1f5f9;margin:6px 0}
.prat-menu .logout-item{color:#dc2626}
.prat-menu .logout-item:hover{background:#fef2f2;color:#dc2626}
</style>

<div class="prat-topbar">
    <div class="prat-topbar-inner">
        <a href="welcome.php" class="prat-logo">
            <span class="prat-logo-icon">A</span>
            APA4CAD
        </a>
        <nav class="prat-nav">
            <a href="index.php?from_welcome=1" class="active">
                <span class="prat-nav-icon">🩺</span> Prescrire
            </a>
            <a href="patient.php">
                <span class="prat-nav-icon">👥</span> Patients
            </a>
        </nav>
        <div class="prat-spacer"></div>
        <a href="welcome.php" class="prat-home-btn" title="Retour à l'accueil">
            🏠 Accueil
        </a>
        <div class="prat-account" id="prat-account">
            <button type="button" class="prat-avatar-btn" onclick="document.getElementById('prat-account').classList.toggle('open')">
                <span class="prat-avatar"><?= htmlspecialchars($_initials, ENT_QUOTES) ?></span>
                <span><?= htmlspecialchars(currentPraticienName(), ENT_QUOTES) ?></span>
                <span class="prat-avatar-chevron">▼</span>
            </button>
            <div class="prat-menu">
                <div class="prat-menu-head">
                    <div class="prat-menu-name"><?= htmlspecialchars(currentPraticienName(), ENT_QUOTES) ?></div>
                    <div class="prat-menu-role">Praticien</div>
                </div>
                <a href="prescriptions.php">
                    <span class="prat-menu-icon">📋</span> Mes prescriptions
                </a>
                <a href="patient.php">
                    <span class="prat-menu-icon">👥</span> Gestion des patients
                </a>
                <div class="prat-menu-divider"></div>
                <a href="logout_praticien.php" class="logout-item">
                    <span class="prat-menu-icon">✕</span> Se déconnecter
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Fermer le dropdown au clic ailleurs
document.addEventListener('click', function(e) {
    const acc = document.getElementById('prat-account');
    if (acc && !acc.contains(e.target)) acc.classList.remove('open');
});
</script>
<?php endif; ?>

<?php if ($EXPLORE_MODE): ?>
<!-- Bannière "Mode exploration" -->
<div style="position:sticky;top:0;z-index:1000;background:linear-gradient(90deg,#059669,#10b981);
            color:#fff;padding:10px 20px;font-size:13px;font-weight:600;
            display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;
            box-shadow:0 2px 8px rgba(5,150,105,.3)">
    <span style="display:flex;align-items:center;gap:8px">
        🔓 <strong>Mode exploration libre</strong> · lecture seule, prescription désactivée
    </span>
    <a href="?exit_explore=1" style="background:rgba(255,255,255,.2);color:#fff;text-decoration:none;
       padding:5px 12px;border-radius:6px;font-size:12px;border:1px solid rgba(255,255,255,.3);
       transition:.15s" onmouseover="this.style.background='rgba(255,255,255,.3)'"
       onmouseout="this.style.background='rgba(255,255,255,.2)'">
        ✕ Quitter
    </a>
</div>
<?php endif; ?>
<div class="container">

    <?php
    // ─── BANDEAU CONTEXTE : Nouvelle consultation pour un patient existant ───
    if ($fromPatientId !== ''):
        $bp_nom    = $_SESSION['patient_nom']    ?? '';
        $bp_prenom = $_SESSION['patient_prenom'] ?? '';
        $bp_age    = $_SESSION['patient_age']    ?? '';
        $bp_doss   = $_SESSION['patient_dossier'] ?? '';
        $bp_genre  = $_SESSION['patient_genre']  ?? '';
        $bp_full   = trim($bp_prenom . ' ' . $bp_nom) ?: 'Patient';
        $nbPrechecked = count($selected);
    ?>
    <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);
                border:1.5px solid #93c5fd;border-radius:14px;
                padding:16px 22px;margin:18px auto 0;max-width:1360px;
                display:flex;align-items:center;gap:16px;flex-wrap:wrap;
                box-shadow:0 2px 8px rgba(37,99,235,.08)">
        <div style="background:#2563eb;color:#fff;border-radius:10px;
                    padding:10px 14px;display:flex;align-items:center;gap:8px">
            <span style="font-size:18px">📋</span>
            <span style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase">
                Nouvelle consultation
            </span>
        </div>
        <div style="flex:1;min-width:200px">
            <div style="font-weight:700;color:#1e293b;font-size:15px">
                <?= h($bp_full) ?>
                <?php if ($bp_age !== ''): ?><span style="color:#6b7280;font-weight:500;font-size:13px"> · <?= h($bp_age) ?> ans</span><?php endif; ?>
                <?php if ($bp_genre !== ''): ?><span style="color:#6b7280;font-weight:500;font-size:13px"> · <?= h($bp_genre) ?></span><?php endif; ?>
            </div>
            <div style="font-size:12px;color:#475569;margin-top:2px">
                <?php if ($bp_doss !== ''): ?>
                    <span style="font-family:ui-monospace,monospace;background:#fff;padding:1px 7px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px">
                        <?= h($bp_doss) ?>
                    </span> ·
                <?php endif; ?>
                <strong style="color:#1d4ed8"><?= $nbPrechecked ?> pathologie<?= $nbPrechecked > 1 ? 's' : '' ?> du dossier</strong> pré-cochée<?= $nbPrechecked > 1 ? 's' : '' ?> ·
                ajustez si nécessaire pour cette consultation
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="patient_detail.php?id=<?= urlencode($fromPatientId) ?>"
               style="background:#fff;color:#1d4ed8;border:1.5px solid #93c5fd;
                      border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;
                      text-decoration:none;transition:.15s">
                ← Retour au dossier
            </a>
            <a href="index.php?restart=1"
               style="background:#fff;color:#6b7280;border:1.5px solid #d1d5db;
                      border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;
                      text-decoration:none;transition:.15s"
               onclick="return confirm('Annuler la consultation en cours et démarrer un parcours sans patient ?')">
                ✕ Annuler
            </a>
        </div>
    </div>
    <?php endif; ?>

    <section class="hero">
        <h1>Recommandation d’activités en fonction des pathologies</h1>
        <p></p>
        <?php renderParcoursStepper(1); ?>
    </section>

    <div class="layout">
        <aside class="card">
            <h2>Liste des pathologies</h2>
            <p class="muted">Selectionner une ou plusieurs pathologies.</p>

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <input type="text" id="searchPathology" class="search" style="flex:1;margin-bottom:0"
                       placeholder="Rechercher une pathologie...">
                <button type="button" id="voiceBtn" onclick="startVoice()"
                        title="Recherche vocale"
                        style="flex-shrink:0;width:40px;height:40px;border-radius:50%;border:2px solid var(--line);
                               background:#fff;cursor:pointer;font-size:18px;display:flex;align-items:center;
                               justify-content:center;transition:all .2s;box-shadow:0 1px 4px rgba(0,0,0,.08)">
                    🎤
                </button>
            </div>
            <div id="voiceStatus" style="display:none;font-size:11px;color:#3b82f6;font-weight:600;margin-bottom:4px;
                                          text-align:center;animation:pulse 1s infinite">
                🔴 Écoute en cours... parlez maintenant
            </div>
            <div id="searchAlert" class="searchAlert">Aucune pathologie ne correspond à cette recherche.</div>

            <form method="get" id="pathologyForm">
                <div class="actions" style="margin-top:0;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--line)">
                    <a class="secondary" href="index.php">Réinitialiser</a>
                </div>

                <div class="tree" id="treeContainer">
                    <?php foreach ($roots as $root): ?>
                        <?php renderTreeNode($root, $selected); ?>
                    <?php endforeach; ?>
                </div>
            </form>
        </aside>

        <main>
            <!-- ── Tableau de bord compact ────────────────────────────────── -->
            <?php if (!empty($selected)): ?>
            <div class="dashboard-bar">

                <!-- Indicateurs -->
                <div class="db-indicators">
                    <div class="db-stat">
                        <span class="db-val"><?= $selectedCount ?></span>
                        <span class="db-lbl">Pathologie<?= $selectedCount > 1 ? 's' : '' ?></span>
                    </div>
                    <?php if ($selectedCount > 1): ?>
                    <div class="db-stat db-stat-ok">
                        <span class="db-val"><?= count($commonActivities) ?></span>
                        <span class="db-lbl">Activité<?= count($commonActivities) > 1 ? 's' : '' ?> commune<?= count($commonActivities) > 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($contraCount > 0): ?>
                    <div class="db-stat db-stat-danger">
                        <span class="db-val"><?= $contraCount ?></span>
                        <span class="db-lbl">Activité<?= $contraCount > 1 ? 's' : '' ?> contre-indiquée<?= $contraCount > 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($hasConflicts): ?>
                    <div class="db-stat db-stat-conflict">
                        <span class="db-val"><?= count($allBlockedActivities) ?></span>
                        <span class="db-lbl">Conflit<?= count($allBlockedActivities) > 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="db-pdf-btn">
                        <button type="button" class="btn-pdf" onclick="window.print()">🖨️ PDF</button>
                    </div>
                </div><!-- /db-indicators -->

                <!-- Deux cartes séparées empilées -->
                <?php if ($selectedCount > 1 || $hasContraIndications): ?>
                <div style="border-top:1px dashed #e2e8f0;padding-top:10px;display:flex;flex-direction:column;gap:8px">

                    <?php if ($selectedCount > 1): ?>
                    <div style="background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 14px">
                        <div style="font-size:11px;font-weight:700;color:var(--ok);text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px">
                            Activités communes
                        </div>
                        <?php if (empty($commonActivities)): ?>
                            <span style="font-size:12px;color:var(--muted);font-style:italic"><?= $hasConflicts ? 'Aucune (voir conflits)' : 'Aucune activité commune' ?></span>
                        <?php else: ?>
                            <div style="display:flex;flex-wrap:wrap;gap:5px">
                                <?php foreach ($commonActivities as $act): ?>
                                    <span class="chip chip-ok"><?= h(prettyLabel($act)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasContraIndications): ?>
                    <div style="background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 14px">
                        <div style="font-size:11px;font-weight:700;color:var(--danger);text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px">
                            Activités contre-indiquées
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:5px">
                            <?php foreach ($mergedContra as $c): ?>
                                <span class="chip chip-danger"><?= h(prettyLabel($c)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endif; ?>

                <!-- Conflits croisés (chips) -->
                <?php if ($hasConflicts): ?>
                <div class="db-row">
                    <span class="db-row-label db-row-conflict">⛔ Conflits</span>
                    <div class="db-chips">
                        <?php foreach ($allBlockedActivities as $act => $byPathos): ?>
                            <span class="chip chip-conflict" title="Bloqué par : <?= h(implode(', ', $byPathos)) ?>"><?= h(prettyLabel($act)) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Modalités globales (chips) -->
                <?php if (!empty($mergedModalities)): ?>
                <div class="db-row">
                    <span class="db-row-label db-row-mod">📊 Modalités</span>
                    <div class="db-chips">
                        <?php foreach ($mergedModalities as $prop => $vals): ?>
                            <?php foreach ($vals as $v): ?>
                                <span class="chip chip-mod"><strong><?= h(modalityLabel($prop)) ?></strong> <?= h(prettyLabel($v)) ?></span>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($selectedCount > 1): ?>
                        <span class="db-footnote">Contrainte max retenue</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /dashboard-bar -->
            <?php endif; ?>

            <?php if (empty($selected)): ?>
                
                
            <?php else: ?>

                <?php if ($selectedCount >= 2): ?>
                <div class="section-divider">
                    <div class="divider-line"></div>
                    <div class="divider-label">Détail par pathologie</div>
                    <div class="divider-line"></div>
                </div>
                <?php endif; ?>

                <?php foreach ($selected as $pathoIdx => $uri): ?>
                    <?php
                        $title        = isset($allPathologies[$uri]) ? $allPathologies[$uri]['label'] : prettyLabel(localName($uri));
                        $items        = $resultsByPathology[$uri]        ?? [];
                        $contra       = $contraByPathology[$uri]         ?? [];
                        $mods         = $modalitiesByPathology[$uri]     ?? [];
                        $precautions  = $precautionsByPathology[$uri]    ?? [];
                        $subActsMap   = $subActivitiesByPathology[$uri]  ?? [];
                        $actModsMap   = $modalitiesByActivity[$uri]      ?? [];
                        $blocked      = $blockedByPathology[$uri]        ?? [];
                        $okItems      = array_filter($items, fn($i) => !isset($blocked[$i['activity']]));
                        $blockedItems = array_filter($items, fn($i) =>  isset($blocked[$i['activity']]));
                        // Feature 2 : activités nouvelles par rapport aux pathologies précédentes
                        $prevActivities = [];
                        for ($pi = 0; $pi < $pathoIdx; $pi++) {
                            $prevUri = $selected[$pi];
                            foreach ($resultsByPathology[$prevUri] ?? [] as $prevItem) {
                                $prevActivities[$prevItem['activity']] = true;
                            }
                        }
                    ?>
                    <section class="panel panel-accordion">
                        <details class="patho-details" open>
                        <summary class="patho-summary">
                            <span class="patho-summary-title"><?= h($title) ?></span>
                            <span class="patho-toggle-label"></span>
                        </summary>

                        <?php if (empty($items) && empty($contra)): ?>
                            <div class="patho-empty-full">
                                 Pas encore renseigné.
                            </div>

                        <?php else: ?>

                            <?php if (!empty($blockedItems)): ?>
                                <div class="conflict-alert-bar">
                                    <span></span>
                                    <div>
                                        <strong><?= count($blockedItems) ?> activité<?= count($blockedItems) > 1 ? 's bloquées' : ' bloquée' ?></strong>
                                        — recommandée<?= count($blockedItems) > 1 ? 's' : '' ?> ici mais contre-indiquée<?= count($blockedItems) > 1 ? 's' : '' ?> par une autre pathologie :
                                        <?php foreach ($blockedItems as $bItem): ?>
                                            <span class="badge conflict"> <?= h(prettyLabel($bItem['activity'])) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="patho-grid">

                                <!-- ── COLONNE GAUCHE : Recommandations ── -->
                                <div class="patho-col patho-col-reco">
                                    <div class="col-header col-header-reco">ACTIVITÉS RECOMMANDÉES</div>

                                    <?php if (empty($okItems) && !empty($items)): ?>
                                        <p class="col-empty">Toutes les activités sont bloquées par une contre-indication croisée.</p>
                                    <?php elseif (empty($okItems)): ?>
                                        <p class="col-empty">Activité pas encore renseignée.</p>
                                    <?php else: ?>
                                        <div class="reco-list">
                                        <?php foreach ($okItems as $item): ?>
                                            <?php
                                                $act     = $item['activity'];
                                                $subActs = $subActsMap[$act] ?? [];
                                                $actMods = $actModsMap[$act] ?? [];
                                                $isNew   = $pathoIdx > 0 && !isset($prevActivities[$act]);
                                            ?>
                                            <div class="reco-card">
                                                <div class="reco-name">
                                                    <?= h(prettyLabel($act)) ?>
                                                    <?php if ($isNew): ?><span class="badge-new"></span><?php endif; ?>
                                                </div>
                                                <?php if (!empty($item['adaptations']) || !empty($actMods)): ?>
                                                    <div class="reco-mods-table" style="margin-top:6px">
                                                        <?php if (!empty($item['adaptations'])): ?>
                                                            <div class="reco-mod-row reco-mod-row-eapa">
                                                                <span class="reco-mod-key reco-mod-key-eapa">Suggestions EAPA</span>
                                                                <span class="reco-mod-val reco-mod-val-eapa"><?= h(implode(' — ', array_map('prettyLabel', $item['adaptations']))) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($actMods)): ?>
                                                            <div style="margin:6px 0 2px;padding:0">
                                                                <span style="font-size:11px;font-weight:700;color:#334155">Suggestions Morganne</span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php foreach ($actMods as $prop => $vals): ?>
                                                            <div class="reco-mod-row reco-mod-row-morganne" style="margin-left:36px">
                                                                <span class="reco-mod-key"><?= h(modalityLabel($prop)) ?></span>
                                                                <span class="reco-mod-val"><?= h(implode(' / ', array_map('prettyLabel', $vals))) ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($subActs)): ?>
                                                    <details class="subacts-details">
                                                        <summary class="subacts-summary">🏃 <?= count($subActs) ?> exercice<?= count($subActs) > 1 ? 's' : '' ?> spécifique<?= count($subActs) > 1 ? 's' : '' ?></summary>
                                                        <div class="subacts-grid" style="margin-top:8px">
                                                            <?php foreach ($subActs as $subName => $equips): ?>
                                                                <div class="subact-card">
                                                                    <div class="subact-name"><?= h(prettyLabel($subName)) ?></div>
                                                                    <?php if (!empty($equips)): ?>
                                                                        <div class="subact-equips">
                                                                            <?php foreach ($equips as $eq): ?>
                                                                                <span class="badge equip">🎒 <?= h(prettyLabel($eq)) ?></span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="subact-noequip">Sans matériel</span>
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

                                    <?php if (!empty($precautions)): ?>
                                        <div class="col-extra-block col-precautions">
                                            <div class="col-sub-header">⚠️ Précautions</div>
                                            <?php foreach ($precautions as $prec): ?>
                                                <span class="badge precaution"><?= h(prettyLabel($prec)) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($mods)): ?>
                                        <div class="col-extra-block col-mods">
                                            <div class="col-sub-header">📊 Modalités d'exercice</div>
                                            <div class="mods-grid">
                                                <?php foreach ($mods as $prop => $vals): ?>
                                                    <div class="mod-entry">
                                                        <span class="mod-key"><?= h(modalityLabel($prop)) ?></span>
                                                        <?php foreach ($vals as $v): ?>
                                                            <span class="badge ok" style="margin-bottom:0"><?= h(prettyLabel($v)) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div><!-- /col-reco -->

                                <!-- ── COLONNE DROITE : Contre-indications ── -->
                                <div class="patho-col patho-col-ci">
                                    <div class="col-header col-header-ci"> ACTIVITÉS CONTRE-INDIQUÉES</div>
                                    <?php if (empty($contra)): ?>
                                        <p class="col-empty">Aucune contre-indication formelle.</p>
                                    <?php else: ?>
                                        <div class="ci-list">
                                            <?php foreach ($contra as $c): ?>
                                                <div class="ci-item">
                                                    <span class="ci-icon"></span>
                                                    <span class="ci-name"><?= h(prettyLabel($c)) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div><!-- /col-ci -->

                            </div><!-- /patho-grid -->
                        <?php endif; ?>
                        </details>
                    </section>
                <?php endforeach; ?>

            <?php endif; ?>
        </main>
    </div>
</div>

<script>
// ── Auto-submit checkboxes (avec interception pour exclusions de pathos) ──
const form = document.getElementById('pathologyForm');

// Liste des pathos initialement pré-cochées (venues du patient)
// Vide [] si on n'est pas dans un contexte patient.
const ORIGINAL_PRECHECKED = <?= json_encode(array_values($originalPrechecked), JSON_UNESCAPED_UNICODE) ?>;
const HAS_PATIENT_CONTEXT = <?= !empty($_SESSION['patient_uri'] ?? '') ? 'true' : 'false' ?>;

// Map URI → label pour les pathos originales (pour afficher dans la modale)
const ORIGINAL_LABELS = {};
document.querySelectorAll('.path-item input[type="checkbox"], .sub-root > summary input[type="checkbox"]').forEach(cb => {
    if (ORIGINAL_PRECHECKED.includes(cb.value)) {
        // Label = texte du label parent
        const lbl = cb.closest('label, .path-item, summary')?.textContent.trim() || cb.value;
        ORIGINAL_LABELS[cb.value] = lbl;
    }
});

// Buffer des pathos en attente d'être exclues (en cas de décochage multiple avant la modale)
let _pendingExclusions = []; // [{uri, label, checkbox}]

document.querySelectorAll('.auto-submit').forEach(el => {
    el.addEventListener('change', (e) => {
        // Cas 1 : on coche (ré-active) une patho → submit normal sans questions
        if (el.checked) { form.submit(); return; }

        // Cas 2 : on décoche une patho qui N'EST PAS dans la liste originale du patient
        //         → ce n'est pas une "exclusion" cliniquement parlant, submit normal
        if (!HAS_PATIENT_CONTEXT || !ORIGINAL_PRECHECKED.includes(el.value)) {
            form.submit(); return;
        }

        // Cas 3 : on décoche une patho originale du patient → demander un motif
        e.preventDefault();
        // Recoche temporairement (on retire seulement après validation du motif)
        el.checked = true;
        _pendingExclusions = [{
            uri:   el.value,
            label: ORIGINAL_LABELS[el.value] || el.value,
            checkbox: el
        }];
        openExclusionMotifModal();
    });
});

// ─── Modale de motif d'exclusion (créée dynamiquement) ───
function openExclusionMotifModal() {
    const ex = _pendingExclusions[0];
    if (!ex) return;

    // Crée la modale s'il n'existe pas
    let modal = document.getElementById('modal-exclusion-index');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modal-exclusion-index';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:80px 20px 20px;backdrop-filter:blur(4px)';
        modal.innerHTML = `
            <div style="background:#fff;border-radius:18px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;font-family:'Inter',sans-serif">
                <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb">
                    <h2 style="margin:0;font-size:17px;font-weight:800;color:#1e293b">📝 Motif d'exclusion</h2>
                    <p style="margin:4px 0 0;font-size:12px;color:#64748b">Cette pathologie est associée au patient. Précisez pourquoi vous la retirez de cette consultation.</p>
                </div>
                <div style="padding:18px 24px">
                    <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:11px 13px;margin-bottom:14px;color:#78350f;font-size:13px;line-height:1.5">
                        Vous retirez <strong id="excl-patho-label">…</strong> de la prescription.<br>
                        <em>La pathologie reste active au dossier — c'est juste une exclusion pour cette consultation.</em>
                    </div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#1e293b;margin-bottom:6px">
                        Motif <span style="color:#dc2626">*</span>
                    </label>
                    <textarea id="excl-motif-input" rows="3" required
                              placeholder="Ex : Patient ne souhaite pas en discuter aujourd'hui..."
                              style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font-size:14px;font-family:inherit;resize:vertical;line-height:1.5;box-sizing:border-box"></textarea>
                </div>
                <div style="display:flex;justify-content:space-between;padding:14px 24px;border-top:1px solid #e5e7eb;background:#f8fafc">
                    <button type="button" id="excl-cancel"
                            style="background:#fff;color:#64748b;border:1px solid #e5e7eb;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">
                        Annuler
                    </button>
                    <button type="button" id="excl-confirm"
                            style="background:#2563eb;color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">
                        Confirmer l'exclusion
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Wiring des boutons
        document.getElementById('excl-cancel').onclick = closeExclusionModal;
        document.getElementById('excl-confirm').onclick = confirmExclusionMotif;
        modal.addEventListener('click', (e) => { if (e.target === modal) closeExclusionModal(); });
    }
    document.getElementById('excl-patho-label').textContent = ex.label;
    document.getElementById('excl-motif-input').value = '';
    modal.style.display = 'flex';
    setTimeout(() => document.getElementById('excl-motif-input').focus(), 80);
}

function closeExclusionModal() {
    const modal = document.getElementById('modal-exclusion-index');
    if (modal) modal.style.display = 'none';
    // On laisse la checkbox cochée (l'utilisateur a annulé) — pas de modif
    _pendingExclusions = [];
}

function confirmExclusionMotif() {
    const motif = document.getElementById('excl-motif-input').value.trim();
    if (motif === '') {
        const input = document.getElementById('excl-motif-input');
        input.style.borderColor = '#dc2626';
        input.style.background = '#fef2f2';
        input.focus();
        return;
    }

    const ex = _pendingExclusions[0];
    if (!ex) return;

    // 1) Décocher pour de vrai
    ex.checkbox.checked = false;

    // 2) Injecter le motif dans le formulaire en tant que hidden input
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = `excluded_pathos[${ex.uri}]`;
    hidden.value = motif;
    form.appendChild(hidden);

    // 3) Fermer la modale et submit
    closeExclusionModal();
    form.submit();
}

// Échap = annuler
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modal-exclusion-index');
        if (modal && modal.style.display === 'flex') closeExclusionModal();
    }
});

// ── Recherche pathologie ──────────────────────────────────────────────────
const searchInput = document.getElementById('searchPathology');
const alertBox    = document.getElementById('searchAlert');
searchInput?.addEventListener('input', function () {
    const value = this.value.toLowerCase().trim();
    let visibleCount = 0;

    // 1. Feuilles (.path-item)
    document.querySelectorAll('.path-item').forEach(item => {
        const label   = item.getAttribute('data-label') || '';
        const visible = value === '' || label.includes(value);
        item.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    // 2. Nœuds intermédiaires (details.sub-root) du plus profond au plus haut
    // On itère en reverse pour traiter les enfants avant les parents
    const subRoots = [...document.querySelectorAll('details.sub-root')].reverse();
    subRoots.forEach(detail => {
        const ownLabel    = detail.getAttribute('data-label') || '';
        const ownMatch    = value === '' || ownLabel.includes(value);
        const childVis    = [...detail.querySelectorAll('.path-item')]
                              .some(i => i.style.display !== 'none');
        const subVis      = [...detail.querySelectorAll('details.sub-root')]
                              .some(d => d.style.display !== 'none');
        const visible     = ownMatch || childVis || subVis;
        detail.style.display = visible ? '' : 'none';
        if (value !== '' && visible) detail.open = true;
        if (ownMatch) visibleCount++;
    });

    // 3. Racines (.root)
    document.querySelectorAll('details.root').forEach(detail => {
        const hasVisible = [...detail.querySelectorAll('.path-item, details.sub-root')]
                             .some(el => el.style.display !== 'none');
        detail.style.display = (hasVisible || value === '') ? '' : 'none';
        if (value !== '' && hasVisible) detail.open = true;
    });

    alertBox.style.display = (value !== '' && visibleCount === 0) ? 'block' : 'none';
});


// ── Feature 4: Animer l'ouverture des accordéons ─────────────────────────
document.querySelectorAll('.patho-details').forEach(details => {
    details.addEventListener('toggle', () => {
        if (details.open) {
            const body = details.querySelector(':scope > *:not(summary)');
            if (body) {
                body.style.animation = 'fadeSlide .2s ease';
            }
        }
    });
});
</script>

<?php if (!empty($selected)):
    $rapportParams = ['pathologies' => $selected];
    if ($EXPLORE_MODE) $rapportParams['mode'] = 'explore';
?>
<a class="btn-Recommandations" href="rapport.php?<?= http_build_query($rapportParams) ?>">
    Synthèse <span class="arrow">→</span>
</a>
<?php endif; ?>

<style>
.btn-Recommandations{
    position:fixed;bottom:28px;right:28px;
    background:linear-gradient(135deg,#1e40af,#3b82f6);
    color:#fff;font-family:'Inter',sans-serif;
    font-size:15px;font-weight:700;
    padding:14px 26px;border-radius:50px;
    box-shadow:0 4px 20px rgba(59,130,246,.45);
    text-decoration:none;
    display:flex;align-items:center;gap:8px;
    transition:transform .2s,box-shadow .2s;
    z-index:999;
}
.btn-Recommandations:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 28px rgba(59,130,246,.55);
}
.btn-Recommandations .arrow{font-size:18px;transition:transform .2s}
.btn-Recommandations:hover .arrow{transform:translateX(4px)}
</style>
<style>
@keyframes fadeSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
</style>
<script>
// ── Reconnaissance vocale ─────────────────────────────────────────────────
function startVoice() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        alert('La reconnaissance vocale n\'est pas supportée par ce navigateur.\nUtilisez Chrome ou Edge.');
        return;
    }

    const btn    = document.getElementById('voiceBtn');
    const status = document.getElementById('voiceStatus');
    const input  = document.getElementById('searchPathology');

    const recognition = new SpeechRecognition();
    recognition.lang        = 'fr-FR';
    recognition.continuous  = false;
    recognition.interimResults = false;

    recognition.onstart = () => {
        btn.classList.add('listening');
        btn.textContent = '🔴';
        status.style.display = 'block';
    };

    recognition.onresult = (event) => {
        const transcript = event.results[0][0].transcript;
        input.value = transcript;
        // Déclencher le filtre de recherche
        input.dispatchEvent(new Event('input'));
    };

    recognition.onerror = (event) => {
        alert('Erreur micro : ' + event.error + '\nVérifiez que le micro est autorisé dans le navigateur.');
    };

    recognition.onend = () => {
        btn.classList.remove('listening');
        btn.textContent = '🎤';
        status.style.display = 'none';
    };

    recognition.start();
}
</script>
</body>
</html>