<?php
/**
 * APA4CAD - Endpoint AJAX : sélection/création d'un patient
 *
 * Appelé par patient.php en mode wizard SPA (étape 3 du parcours).
 * Permet de :
 *   - Sélectionner un patient existant (action=select, fragment=...)
 *   - Créer un nouveau patient (action=create, prenom/nom/sexe/age/dossier)
 *
 * Stocke le patient sélectionné en session et renvoie ses infos en JSON.
 * Le frontend bascule ensuite sur l'étape 4 (freins) sans recharger.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/patient_session.php';

function p_sparqlQueryRead(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query) . '&output=json';
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

function p_localName(string $uri): string {
    if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
    if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
    return $uri;
}

function p_storePatientInSession(string $fragment): array {
    $uri = ONTO_NAMESPACE . $fragment;
    $r = p_sparqlQueryRead(sparqlPrefixes() . "
        SELECT ?nom ?prenom ?age ?dossier ?genre WHERE {
            OPTIONAL { <$uri> ex:aPourNom ?nom }
            OPTIONAL { <$uri> ex:aPourPrenom ?prenom }
            OPTIONAL { <$uri> ex:aPourAge ?age }
            OPTIONAL { <$uri> ex:aPourNumeroDossier ?dossier }
            OPTIONAL { <$uri> ex:aPourGenre ?g . BIND(STRAFTER(STR(?g),'#') AS ?genre) }
        } LIMIT 1
    ");
    $b = $r['bindings'][0] ?? [];
    $nom    = $b['nom']['value']    ?? '';
    $prenom = $b['prenom']['value'] ?? '';
    $age    = $b['age']['value']    ?? '';
    $dossier= $b['dossier']['value']?? '';

    $_SESSION['patient_uri']      = $uri;
    $_SESSION['patient_fragment'] = $fragment;
    $_SESSION['patient_nom']      = $nom;
    $_SESSION['patient_prenom']   = $prenom;
    $_SESSION['patient_age']      = $age;
    $_SESSION['patient_dossier']  = $dossier;

    return [
        'uri'      => $uri,
        'fragment' => $fragment,
        'nom'      => $nom,
        'prenom'   => $prenom,
        'age'      => $age,
        'dossier'  => $dossier,
        'fullname' => trim($prenom . ' ' . $nom) ?: '(patient sans nom)',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST requis']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'select') {
    // ─── Sélection d'un patient existant ───
    $fragment = $_POST['fragment'] ?? '';
    if ($fragment === '') {
        echo json_encode(['success' => false, 'error' => 'Fragment manquant']);
        exit;
    }
    $info = p_storePatientInSession($fragment);
    echo json_encode(['success' => true, 'patient' => $info]);
    exit;

} elseif ($action === 'create') {
    // ─── Création d'un nouveau patient ───
    $prenom  = trim($_POST['prenom']  ?? '');
    $nom     = trim($_POST['nom']     ?? '');
    $sexe    = trim($_POST['sexe']    ?? '');
    $age     = trim($_POST['age']     ?? '');
    $dossier = trim($_POST['numero_dossier'] ?? '');

    if ($prenom === '' || $nom === '' || $sexe === '' || $age === '' || $dossier === '') {
        echo json_encode(['success' => false, 'error' => 'Tous les champs sont obligatoires']);
        exit;
    }

    $uriInfo = generatePatientUri($prenom, $nom);
    $fragment = $uriInfo['fragment'];
    $fullUri  = $uriInfo['full_uri'];

    // Déterminer la tranche d'âge
    $ageInt = (int)$age;
    $tranche = null;
    if      ($ageInt < 18) $tranche = 'Mineur';
    elseif  ($ageInt < 65) $tranche = 'Adulte';
    else                   $tranche = 'SeniorPlus65';

    $genreUri   = ($sexe === 'M') ? ONTO_NAMESPACE . 'Masculin' : ONTO_NAMESPACE . 'Feminin';
    $trancheUri = ONTO_NAMESPACE . $tranche;

    $prenomEsc  = sparqlEscapeString($prenom);
    $nomEsc     = sparqlEscapeString($nom);
    $dossierEsc = sparqlEscapeString($dossier);

    $triples = [
        "<$fullUri> rdf:type owl:NamedIndividual ;",
        "           rdf:type ex:Patient ;",
        "           ex:aPourNom \"$nomEsc\" ;",
        "           ex:aPourPrenom \"$prenomEsc\" ;",
        "           ex:aPourNumeroDossier \"$dossierEsc\" ;",
        "           ex:aPourAge \"$ageInt\"^^xsd:integer ;",
        "           ex:aPourGenre <$genreUri> ;",
        "           ex:aPourtrancheAge <$trancheUri> .",
    ];

    $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { " . implode("\n", $triples) . " }");

    if (!$res['success']) {
        echo json_encode(['success' => false, 'error' => 'Échec création Fuseki : ' . ($res['error'] ?? 'inconnu')]);
        exit;
    }

    $info = p_storePatientInSession($fragment);
    echo json_encode(['success' => true, 'patient' => $info, 'created' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Action inconnue']);
