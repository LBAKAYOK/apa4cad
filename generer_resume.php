<?php
/**
 * APA4CAD - Génération du résumé IA d'une prescription
 *
 * Appelé en POST avec { prescription_id: "Prescription_xxx" }
 *
 * Workflow :
 *   1. Lit la prescription depuis Fuseki (patient, activités, freins, leviers, CI...)
 *   2. Construit un prompt enrichi pour Ollama
 *   3. Appelle Ollama via ollama_proxy.php
 *   4. Supprime l'ancien résumé s'il existe
 *   5. Sauvegarde le nouveau résumé en rdfs:comment
 *   6. Renvoie le texte généré en JSON
 *
 * Réponse JSON : { success: true, resume: "...", duration_ms: 12345 }
 *           ou : { success: false, error: "..." }
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// ── Handler d'erreurs : tout doit retourner du JSON, jamais du HTML ──
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function($e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Exception : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
    ]);
    exit;
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => "Erreur PHP : $errstr (" . basename($errfile) . ":$errline)"
    ]);
    exit;
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Erreur fatale : ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
        ]);
        exit;
    }
});

ob_start();

require_once __DIR__ . '/sparql_update.php';

// ─────────────────────────────────────────────────────────────────
//  Utilitaires
// ─────────────────────────────────────────────────────────────────

function sparqlQueryR(string $query): array {
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

function localName(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}

function prettyLabel(string $name): string {
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $name)));
}

function categoryLabel(string $local): string {
    return match ($local) {
        'AffectionDeLongueDuree' => 'Affection de longue durée',
        'Hypertension_arterielle' => 'Hypertension artérielle',
        'Obesite' => 'Obésité',
        'DT1' => 'Diabète de type 1',
        'DT2' => 'Diabète de type 2',
        'BronchopneumopathieChroniqueObstructive' => 'BPCO',
        'ApneeDuSommeil' => 'Apnée du sommeil',
        'Diabete' => 'Diabète',
        'ArthroseGenou' => 'Arthrose du genou',
        'ArthroseHanche' => 'Arthrose de la hanche',
        'ArthroseEpaule' => 'Arthrose de l\'épaule',
        'ArthroseCervicale' => 'Arthrose cervicale',
        'AngorStable' => 'Angor stable',
        'AngorInstable' => 'Angor instable',
        'InfarctusDuMyocarde' => 'Infarctus du myocarde',
        default => prettyLabel($local),
    };
}

function jsonError(string $msg, int $httpCode = 500): void {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  Récupération de l'ID de prescription
// ─────────────────────────────────────────────────────────────────

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$prescriptionId = trim($payload['prescription_id'] ?? '');

if ($prescriptionId === '') {
    jsonError('prescription_id manquant', 400);
}

$prescriptionUri = ONTO_NAMESPACE . $prescriptionId;

$startTime = microtime(true);

// ─────────────────────────────────────────────────────────────────
//  1) Charger les infos de la prescription + patient
// ─────────────────────────────────────────────────────────────────

$infoQ = sparqlPrefixes() . "
    SELECT ?patient ?nom ?prenom ?age ?dossier ?genreLabel ?date WHERE {
        <$prescriptionUri> a ex:Prescription ;
                           ex:concerne ?patient .
        OPTIONAL { <$prescriptionUri> ex:aPourDate ?date }
        OPTIONAL { ?patient ex:aPourNom ?nom }
        OPTIONAL { ?patient ex:aPourPrenom ?prenom }
        OPTIONAL { ?patient ex:aPourAge ?age }
        OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
        OPTIONAL { ?patient ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
    }
";
$rows = sparqlQueryR($infoQ);
if (empty($rows)) {
    jsonError("Prescription introuvable : $prescriptionId", 404);
}
$b = $rows[0];
$patientUri = $b['patient']['value'] ?? '';
$prenom = $b['prenom']['value'] ?? '';
$nom = $b['nom']['value'] ?? '';
$age = $b['age']['value'] ?? '';
$dossier = $b['dossier']['value'] ?? '';
$genre = $b['genreLabel']['value'] ?? '';
$date = $b['date']['value'] ?? '';

$patientName = trim("$prenom $nom") ?: 'Patient anonyme';

// ─────────────────────────────────────────────────────────────────
//  2) Pathologies actives du patient
// ─────────────────────────────────────────────────────────────────

$pathologies = [];
if ($patientUri) {
    foreach (sparqlQueryR(sparqlPrefixes() . " SELECT ?patho WHERE { <$patientUri> ex:aPourPathologie ?patho }") as $r) {
        $pathologies[] = categoryLabel(localName($r['patho']['value']));
    }
}

// ─────────────────────────────────────────────────────────────────
//  3) Activités prescrites + adaptations + modalités Morganne
// ─────────────────────────────────────────────────────────────────

$activitesQ = sparqlPrefixes() . "
    SELECT DISTINCT ?activite WHERE {
        <$prescriptionUri> ex:contient ?activite .
    }
";
$activites = [];
foreach (sparqlQueryR($activitesQ) as $r) {
    $actUri = $r['activite']['value'];
    $actLocal = localName($actUri);
    // Le label "Endurance_b1358a" → on enlève le suffixe
    $cleanLocal = preg_replace('/_[a-f0-9]{6}$/', '', $actLocal);
    $cleanLocal = preg_replace('/^Activite_/', '', $cleanLocal);
    $activites[] = ['uri' => $actUri, 'name' => prettyLabel($cleanLocal)];
}

// Pour chaque pathologie active, on charge les recommandations + adaptations + modalités
// (on les croise avec les activités prescrites)
$adaptationsParActivite = [];   // [activityClass => [adaptation1, adaptation2]]
$modalitesParActivite = [];     // [activityClass => [prop => [val1, val2]]]

if ($patientUri && !empty($activites)) {
    // Récupérer aussi les classes parentes des activités prescrites
    // pour matcher avec les recommandations qui pointent vers la classe générique
    foreach ($activites as &$act) {
        // Recherche du type de l'instance d'activité
        $typeQ = sparqlPrefixes() . "
            SELECT ?type WHERE {
                <{$act['uri']}> a ?type .
                FILTER(STRSTARTS(STR(?type), \"" . ONTO_NAMESPACE . "\"))
                FILTER(?type != owl:NamedIndividual)
            }
        ";
        foreach (sparqlQueryR($typeQ) as $tr) {
            $act['class'] = $tr['type']['value'];
            break;
        }
    }
    unset($act);

    foreach ($pathologies as $pathoLabel) {
        // Skip si pas une vraie pathologie connue
    }

    // Récupérer toutes les pathologies et leurs adaptations recommandées
    $allPathoUris = [];
    foreach (sparqlQueryR(sparqlPrefixes() . " SELECT ?patho WHERE { <$patientUri> ex:aPourPathologie ?patho }") as $r) {
        $allPathoUris[] = $r['patho']['value'];
    }

    foreach ($allPathoUris as $pUri) {
        // Adaptations
        $adaptQ = sparqlPrefixes() . "
            SELECT ?activity ?adaptation WHERE {
                <$pUri> rdfs:subClassOf ?r .
                ?r owl:onProperty ex:aPourActiviteRecommandee ;
                   owl:someValuesFrom ?activity .
                OPTIONAL { ?activity rdfs:subClassOf ?r2 .
                           ?r2 owl:onProperty ex:aPourAdaptation ;
                               owl:someValuesFrom ?adaptation . }
            }
        ";
        foreach (sparqlQueryR($adaptQ) as $r) {
            $actClass = $r['activity']['value'] ?? '';
            $adapt = $r['adaptation']['value'] ?? '';
            if ($actClass && $adapt) {
                if (!isset($adaptationsParActivite[$actClass])) {
                    $adaptationsParActivite[$actClass] = [];
                }
                $adaptLabel = prettyLabel(localName($adapt));
                if (!in_array($adaptLabel, $adaptationsParActivite[$actClass], true)) {
                    $adaptationsParActivite[$actClass][] = $adaptLabel;
                }
            }
        }
    }
}

// Croiser : pour chaque activité prescrite, on trouve ses adaptations
foreach ($activites as &$act) {
    if (isset($act['class']) && isset($adaptationsParActivite[$act['class']])) {
        $act['adaptations'] = $adaptationsParActivite[$act['class']];
    } else {
        $act['adaptations'] = [];
    }
}
unset($act);

// ─────────────────────────────────────────────────────────────────
//  4) Freins, Leviers et CI depuis les rdfs:comment de la prescription
// ─────────────────────────────────────────────────────────────────

$freinsList = [];
$leviersList = [];
$ciList = [];

$commentsQ = sparqlPrefixes() . "
    SELECT ?comment WHERE {
        <$prescriptionUri> rdfs:comment ?comment .
    }
";
foreach (sparqlQueryR($commentsQ) as $r) {
    $txt = $r['comment']['value'] ?? '';
    if ($txt === '') continue;
    if (str_starts_with($txt, '[CI]')) {
        $ciList[] = trim(substr($txt, 4));
    } elseif (str_starts_with($txt, '[FREIN]')) {
        $freinsList[] = trim(substr($txt, 7));
    } elseif (str_starts_with($txt, '[LEVIER]')) {
        $leviersList[] = trim(substr($txt, 8));
    }
}

// ─────────────────────────────────────────────────────────────────
//  5) Construction du PROMPT enrichi pour Ollama
// ─────────────────────────────────────────────────────────────────

$dateFr = '';
if ($date !== '') {
    try { $dateFr = (new DateTime($date))->format('d/m/Y'); }
    catch (Exception $e) { $dateFr = $date; }
}

$promptParts = [];
$promptParts[] = "Vous êtes un Enseignant en Activité Physique Adaptée (EAPA) qualifié.";
$promptParts[] = "Rédigez un compte rendu de consultation médical structuré en exactement 3 paragraphes pour le patient ci-dessous.";
$promptParts[] = "";
$promptParts[] = "═══ CONTEXTE PATIENT ═══";
$promptParts[] = "- Nom complet : $patientName";
if ($age !== '') $promptParts[] = "- Âge : $age ans";
if ($genre !== '') $promptParts[] = "- Sexe : $genre";
if ($dossier !== '') $promptParts[] = "- N° dossier médical : $dossier";
if ($dateFr !== '') $promptParts[] = "- Date de consultation : $dateFr";
if (!empty($pathologies)) {
    $promptParts[] = "- Pathologies prises en compte : " . implode(', ', $pathologies);
}
$promptParts[] = "";

if (!empty($activites)) {
    $promptParts[] = "═══ ACTIVITÉS PRESCRITES ═══";
    foreach ($activites as $i => $act) {
        $n = $i + 1;
        $line = "$n. " . $act['name'];
        if (!empty($act['adaptations'])) {
            $line .= " (adaptations EAPA : " . implode(', ', $act['adaptations']) . ")";
        }
        $promptParts[] = $line;
    }
    $promptParts[] = "";
}

if (!empty($freinsList)) {
    $promptParts[] = "═══ FREINS IDENTIFIÉS CHEZ LE PATIENT ═══";
    foreach ($freinsList as $f) {
        $promptParts[] = "- $f";
    }
    $promptParts[] = "";
}

if (!empty($leviersList)) {
    $promptParts[] = "═══ LEVIERS DE MOTIVATION ═══";
    foreach ($leviersList as $l) {
        $promptParts[] = "- $l";
    }
    $promptParts[] = "";
}

if (!empty($ciList)) {
    $promptParts[] = "═══ ACTIVITÉS CONTRE-INDIQUÉES ═══";
    foreach ($ciList as $ci) {
        $promptParts[] = "- $ci";
    }
    $promptParts[] = "";
}

$promptParts[] = "═══ CONSIGNES STRICTES ═══";
$promptParts[] = "1. Paragraphe 1 — Présentation du patient et son contexte clinique (pathologies, situation).";
$promptParts[] = "2. Paragraphe 2 — Programme d'activité physique adapté prescrit (activités, adaptations).";
$promptParts[] = "3. Paragraphe 3 — Recommandations, points de vigilance (freins à anticiper, leviers à valoriser, contre-indications à respecter).";
$promptParts[] = "";
$promptParts[] = "RÈGLES IMPÉRATIVES :";
$promptParts[] = "- N'inventez aucune information non fournie ci-dessus.";
$promptParts[] = "- N'inférez aucune cause médicale ou diagnostic.";
$promptParts[] = "- Utilisez UNIQUEMENT les informations listées dans ce document.";
$promptParts[] = "- Style médical professionnel, à la 3ème personne.";
$promptParts[] = "- Pas d'emojis ni de mise en forme markdown.";
$promptParts[] = "- Réponse directe sans préambule.";

$prompt = implode("\n", $promptParts);

// ─────────────────────────────────────────────────────────────────
//  6) Appel à Ollama (direct + fallback proxy)
// ─────────────────────────────────────────────────────────────────

$ollamaPayload = [
    'model' => 'llama3.2:1b',
    'prompt' => $prompt,
    'stream' => false,
    'options' => [
        'temperature' => 0.3,    // peu créatif, factuel
        'top_p' => 0.85,
        'num_predict' => 800,
    ],
];

// On essaie 3 approches successivement :
// 1) cURL direct vers Ollama (http://localhost:11434/api/generate)
// 2) cURL via proxy (différents chemins possibles)
// 3) file_get_contents direct

$ollamaResp = false;
$httpCode = 0;
$curlErr = '';

// Approche 1 : cURL direct
$directUrl = 'http://127.0.0.1:11434/api/generate';
$ch = curl_init($directUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ollamaPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 280);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
$ollamaResp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

// Approche 2 : si direct n'a pas marché, essaye via proxy
if ($ollamaResp === false || $httpCode !== 200) {
    $scriptDir = trim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $proxyCandidates = [
        'http://localhost/' . $scriptDir . '/ollama_proxy.php',
        'http://localhost/ollama_proxy.php',
    ];
    foreach ($proxyCandidates as $proxyUrl) {
        $proxyUrl = str_replace('//ollama_proxy.php', '/ollama_proxy.php', $proxyUrl);
        $ch = curl_init($proxyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ollamaPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 280);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $ollamaResp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($ollamaResp !== false && $httpCode === 200) break;
    }
}

if ($ollamaResp === false || $httpCode !== 200) {
    jsonError("Erreur Ollama : $curlErr (HTTP $httpCode). Vérifiez qu'Ollama est lancé sur le port 11434.", 502);
}

$ollamaData = json_decode($ollamaResp, true);
$generatedText = trim($ollamaData['response'] ?? '');

if ($generatedText === '') {
    jsonError("Ollama a retourné une réponse vide.", 502);
}

// ─────────────────────────────────────────────────────────────────
//  7) Sauvegarde du résumé dans Fuseki
//     - DELETE l'ancien résumé s'il existe (commentaires sans préfixe)
//     - INSERT le nouveau
// ─────────────────────────────────────────────────────────────────

// On supprime tous les rdfs:comment qui ne commencent pas par [CI], [FREIN], [LEVIER]
// (donc tous les anciens résumés IA)
$deleteOldQ = sparqlPrefixes() . "
    DELETE {
        <$prescriptionUri> rdfs:comment ?c .
    }
    WHERE {
        <$prescriptionUri> rdfs:comment ?c .
        FILTER(!STRSTARTS(STR(?c), \"[CI]\"))
        FILTER(!STRSTARTS(STR(?c), \"[FREIN]\"))
        FILTER(!STRSTARTS(STR(?c), \"[LEVIER]\"))
    }
";
sparqlUpdate($deleteOldQ);

// Insertion du nouveau résumé
$resumeEsc = sparqlEscapeString($generatedText);
$insertQ = sparqlPrefixes() . "
    INSERT DATA {
        <$prescriptionUri> rdfs:comment \"$resumeEsc\"@fr .
    }
";
$saveRes = sparqlUpdate($insertQ);

if (!$saveRes['success']) {
    jsonError("Résumé généré mais erreur de sauvegarde : " . ($saveRes['error'] ?? '?'), 500);
}

$durationMs = (int)((microtime(true) - $startTime) * 1000);

// ─────────────────────────────────────────────────────────────────
//  8) Réponse au navigateur
// ─────────────────────────────────────────────────────────────────

echo json_encode([
    'success'     => true,
    'resume'      => $generatedText,
    'duration_ms' => $durationMs,
    'patient'     => $patientName,
    'nb_activites'=> count($activites),
    'nb_freins'   => count($freinsList),
    'nb_leviers'  => count($leviersList),
    'nb_ci'       => count($ciList),
]);
