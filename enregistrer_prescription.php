<?php
/**
 * APA4CAD - Endpoint d'enregistrement final de la prescription (parcours inversé)
 *
 * Reçoit en POST :
 *   - resume_ia (optionnel)
 *
 * Récupère depuis la SESSION :
 *   - les pathologies sélectionnées
 *   - les activités finales
 *   - les freins et leviers cochés
 *   - le patient sélectionné
 *
 * Crée dans Fuseki en une seule transaction :
 *   - Une instance Prescription_<UID>
 *   - Liaisons patient → pathologies (anti-doublon)
 *   - Liaisons patient → freins/leviers (anti-doublon)
 *   - Activités dérivées + liaisons prescription → activités
 *   - Stockage du résumé IA en rdfs:comment de la prescription
 *
 * Retourne un JSON avec le résultat.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/patient_session.php';
require_once __DIR__ . '/praticien_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée (POST requis).']);
    exit;
}

$patient = getPatient();
if (!$patient) {
    echo json_encode(['success' => false, 'error' => 'Aucun patient sélectionné en session.']);
    exit;
}

// Récupération depuis la session
$pathologies = getParcoursPathologies();
$activites   = getParcoursActivites();
$freins      = getParcoursFreins();
$leviers     = getParcoursLeviers();
$contraindications = getParcoursContraindications();

// NOUVEAU : mapping leviers spécifiques par activité (stocké par freins_save.php)
// Format : ['ActiviteEndurance' => ['ActivitePlaisir', 'ChoixActivite'], ...]
$leviersParActivite = $_SESSION['parcours_leviers_par_activite'] ?? [];
if (!is_array($leviersParActivite)) $leviersParActivite = [];

// Résumé IA depuis POST ou session
$resumeIA = trim($_POST['resume_ia'] ?? getParcoursResume());

if (empty($pathologies)) {
    echo json_encode(['success' => false, 'error' => 'Aucune pathologie en session. Recommencez le parcours.']);
    exit;
}

// ─── Helper sparqlAsk ─────────────────────────────────────────────────────
function sparqlAsk(string $askQuery): bool {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($askQuery);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return false;
    $d = json_decode($resp, true);
    return isset($d['boolean']) && $d['boolean'] === true;
}

function prettyLabel(string $name): string {
    $name = str_replace('_', ' ', $name);
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', $name));
}

// ─── Génération des URIs ──────────────────────────────────────────────────
$timestamp        = date('YmdHis');
$shortUid         = substr(bin2hex(random_bytes(4)), 0, 6);
$prescriptionFrag = "Prescription_{$timestamp}_{$shortUid}";
$prescriptionUri  = ONTO_NAMESPACE . $prescriptionFrag;

// ─── Construction des triples ─────────────────────────────────────────────
$triples = [];

// 1) La prescription
$triples[] = "<$prescriptionUri> rdf:type owl:NamedIndividual ;";
$triples[] = "                   rdf:type ex:Prescription ;";
$triples[] = "                   ex:concerne <{$patient['uri']}> ;";
$triples[] = "                   ex:aPourDate \"" . date('Y-m-d\TH:i:s') . "\"^^xsd:dateTime";

// Signature : lier la prescription au praticien connecté
$_praticienUri = currentPraticienUri();
if ($_praticienUri !== null && $_praticienUri !== '') {
    $triples[] = " ;                  ex:prescritPar <$_praticienUri>";
}

if ($resumeIA !== '') {
    $resumeEsc = sparqlEscapeString($resumeIA);
    $triples[] = " ;                  rdfs:comment \"$resumeEsc\"@fr";
}
$triples[] = " .";

// 1bis) Contre-indications bloquantes en rdfs:comment avec préfixe [CI]
// Format : "[CI] <Activité> — bloquée par <Patho1>, <Patho2>"
foreach ($contraindications as $ci) {
    $activity = $ci['activity'] ?? '';
    $reasons  = $ci['reasons']  ?? [];
    if ($activity === '' || empty($reasons)) continue;

    $reasonsStr = implode(', ', $reasons);
    $ciText = "[CI] {$activity} — bloquée par {$reasonsStr}";
    $ciTextEsc = sparqlEscapeString($ciText);

    $triples[] = "<$prescriptionUri> rdfs:comment \"$ciTextEsc\"@fr .";
}

// 1ter) Freins identifiés en rdfs:comment avec préfixe [FREIN]
// Format : "[FREIN] <Nom du frein>"
// Stocké DANS la prescription pour préserver l'historique de cette consultation
foreach ($freins as $freinUri) {
    $freinLocal = (str_contains($freinUri, '#'))
        ? substr($freinUri, strrpos($freinUri, '#') + 1)
        : $freinUri;
    $freinLabel = prettyLabel($freinLocal);
    $freinText = "[FREIN] {$freinLabel}";
    $freinTextEsc = sparqlEscapeString($freinText);
    $triples[] = "<$prescriptionUri> rdfs:comment \"$freinTextEsc\"@fr .";
}

// 1quater) Leviers de motivation en rdfs:comment avec préfixe [LEVIER]
// Format : "[LEVIER] <Nom du levier>"
foreach ($leviers as $levierUri) {
    $levierLocal = (str_contains($levierUri, '#'))
        ? substr($levierUri, strrpos($levierUri, '#') + 1)
        : $levierUri;
    $levierLabel = prettyLabel($levierLocal);
    $levierText = "[LEVIER] {$levierLabel}";
    $levierTextEsc = sparqlEscapeString($levierText);
    $triples[] = "<$prescriptionUri> rdfs:comment \"$levierTextEsc\"@fr .";
}

// 2) Liaisons patient → pathologies (anti-doublon)
foreach ($pathologies as $pathoUri) {
    $askQ = sparqlPrefixes() . " ASK { <{$patient['uri']}> ex:aPourPathologie <$pathoUri> }";
    if (!sparqlAsk($askQ)) {
        $triples[] = "<{$patient['uri']}> ex:aPourPathologie <$pathoUri> .";
    }
}

// 3) Liaisons patient → freins (anti-doublon)
foreach ($freins as $freinUri) {
    $askQ = sparqlPrefixes() . " ASK { <{$patient['uri']}> ex:aPourFrein <$freinUri> }";
    if (!sparqlAsk($askQ)) {
        $triples[] = "<{$patient['uri']}> ex:aPourFrein <$freinUri> .";
    }
}

// 4) Liaisons patient → leviers (anti-doublon)
//    Note : on suppose la propriété ex:aPourLevier (à créer si absente, ou réutiliser une existante)
foreach ($leviers as $levierUri) {
    $askQ = sparqlPrefixes() . " ASK { <{$patient['uri']}> ex:aPourLevier <$levierUri> }";
    if (!sparqlAsk($askQ)) {
        $triples[] = "<{$patient['uri']}> ex:aPourLevier <$levierUri> .";
    }
}

// 5) Activités dérivées + liaison à la prescription + leviers spécifiques par activité
foreach ($activites as $activiteUri) {
    $activiteLocal = (str_contains($activiteUri, '#'))
        ? substr($activiteUri, strrpos($activiteUri, '#') + 1)
        : 'Activite';
    $activiteLocalClean = preg_replace('/[^A-Za-z0-9]/', '', $activiteLocal);
    $derivedFrag = "Activite_{$activiteLocalClean}_{$shortUid}";
    $derivedUri  = ONTO_NAMESPACE . $derivedFrag;

    $triples[] = "<$derivedUri> rdf:type owl:NamedIndividual ;";
    $triples[] = "              rdf:type <$activiteUri> ;";
    $triples[] = "              rdfs:label \"" . sparqlEscapeString(prettyLabel($activiteLocal)) . " (prescription du " . date('d/m/Y') . ")\"@fr .";

    $triples[] = "<$prescriptionUri> ex:contient <$derivedUri> .";

    // NOUVEAU : liaisons activité dérivée → leviers SPÉCIFIQUES (par activité)
    // Le mapping vient de la session, indexé par le NOM LOCAL de l'activité
    // (ex: 'ActiviteEndurance', 'Aquagym', ...) - PAS l'URI complète.
    $leviersForThisAct = $leviersParActivite[$activiteLocal] ?? [];
    foreach ($leviersForThisAct as $levierLocalOrUri) {
        // Le frontend nous envoie le NOM LOCAL du levier (ex: 'ActivitePlaisir')
        // pas l'URI complète. On reconstruit l'URI.
        $levierLocal = (str_contains($levierLocalOrUri, '#'))
            ? substr($levierLocalOrUri, strrpos($levierLocalOrUri, '#') + 1)
            : $levierLocalOrUri;
        $levierLocal = preg_replace('/[^A-Za-z0-9_]/', '', $levierLocal);
        if ($levierLocal === '') continue;
        $levierUriFull = ONTO_NAMESPACE . $levierLocal;
        $triples[] = "<$derivedUri> ex:aPourLevierApplique <$levierUriFull> .";
    }
}

// ─── Exécution ────────────────────────────────────────────────────────────
$insertQuery = sparqlPrefixes() . " INSERT DATA {\n" . implode("\n", $triples) . "\n}";
$res = sparqlUpdate($insertQuery);

if ($res['success']) {
    // Vider la session de parcours après succès (mais garder le patient pour facilité)
    unset($_SESSION['parcours_pathologies'], $_SESSION['parcours_activites'],
          $_SESSION['parcours_freins'], $_SESSION['parcours_leviers'],
          $_SESSION['parcours_resume'], $_SESSION['parcours_contraindications'],
          $_SESSION['parcours_leviers_par_activite']);

    echo json_encode([
        'success'               => true,
        'prescription_uri'      => $prescriptionUri,
        'prescription_fragment' => $prescriptionFrag,
        'nb_pathologies'        => count($pathologies),
        'nb_activites'          => count($activites),
        'nb_contraindications'  => count($contraindications),
        'nb_freins'             => count($freins),
        'nb_leviers'            => count($leviers),
        'message'               => "Prescription enregistrée avec succès.",
    ]);
} else {
    echo json_encode([
        'success'   => false,
        'error'     => "Échec Fuseki : " . ($res['error'] ?? 'erreur inconnue'),
        'http_code' => $res['http_code'] ?? null,
    ]);
}
