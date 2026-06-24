<?php
/**
 * APA4CAD - Fonctions SPARQL UPDATE
 *
 * Ces fonctions permettent d'ÉCRIRE dans Fuseki (INSERT/DELETE).
 * À utiliser en complément de tes fonctions sparqlQuery() existantes
 * qui ne font que de la lecture (SELECT).
 *
 * IMPORTANT : Fuseki doit être configuré en mode read-write (TDB2 persistent).
 */

require_once __DIR__ . '/config_fuseki.php';

/**
 * Exécute une requête SPARQL UPDATE (INSERT DATA, DELETE DATA, INSERT/DELETE WHERE).
 *
 * @param string $updateQuery La requête SPARQL UPDATE complète (avec les PREFIX)
 * @return array ['success' => bool, 'http_code' => int, 'response' => string, 'error' => string|null]
 */
function sparqlUpdate($updateQuery) {
    $endpoint = FUSEKI_UPDATE_ENDPOINT;

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $updateQuery);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/sparql-update; charset=utf-8',
        'Accept: */*',
        'Authorization: Basic ' . base64_encode('admin:' . (getenv('FUSEKI_ADMIN_PASSWORD') ?: 'admin'))
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    // Désactivation vérif SSL (utile en local seulement)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Fuseki renvoie 200 ou 204 en cas de succès
    $success = ($httpCode === 200 || $httpCode === 204);

    return [
        'success'   => $success,
        'http_code' => $httpCode,
        'response'  => $response,
        'error'     => $curlError ?: ($success ? null : "Réponse HTTP $httpCode : $response")
    ];
}

/**
 * Échappe une chaîne pour l'insertion dans une string SPARQL (entre guillemets).
 * Indispensable pour éviter les injections et les erreurs sur les apostrophes.
 *
 * @param string $str
 * @return string
 */
function sparqlEscapeString($str) {
    $str = str_replace('\\', '\\\\', $str);   // backslash doit être doublé en premier
    $str = str_replace('"',  '\\"', $str);    // guillemet double
    $str = str_replace("\n", '\\n', $str);    // saut de ligne
    $str = str_replace("\r", '\\r', $str);    // retour chariot
    $str = str_replace("\t", '\\t', $str);    // tabulation
    return $str;
}

/**
 * Normalise une chaîne pour en faire un fragment d'URI valide.
 * Retire les accents, espaces, caractères spéciaux.
 *
 * @param string $str
 * @return string
 */
function normalizeForUri($str) {
    // Translittération des accents
    $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    // Suppression de tout sauf lettres/chiffres
    $str = preg_replace('/[^A-Za-z0-9]/', '', $str);
    return $str;
}

/**
 * Génère une URI patient au format : ex:Patient_Prenom_Nom_YYYYMMDDHHMMSS
 *
 * @param string $prenom
 * @param string $nom
 * @return array ['fragment' => 'Patient_Marie_Dupont_20260510143022', 'full_uri' => 'http://...#Patient_...']
 */
function generatePatientUri($prenom, $nom) {
    $prenomClean = normalizeForUri($prenom);
    $nomClean    = normalizeForUri($nom);
    $timestamp   = date('YmdHis');

    $fragment = "Patient_{$prenomClean}_{$nomClean}_{$timestamp}";
    $fullUri  = ONTO_NAMESPACE . $fragment;

    return [
        'fragment' => $fragment,
        'full_uri' => $fullUri
    ];
}
