<?php
/**
 * APA4CAD - Configuration centralisée Fuseki
 *
 * Compatible LOCAL (XAMPP) et HÉBERGÉ (Render, etc.) :
 *   - En local : utilise les valeurs par défaut (localhost:3030 / mononto)
 *   - En ligne : lit les variables d'environnement définies par Render
 *
 * Pour adapter sur Render, définir les variables d'environnement :
 *   - FUSEKI_BASE_URL   (ex: https://apa4cad-fuseki.onrender.com)
 *   - FUSEKI_DATASET    (ex: mononto)
 *   - APP_ENV           (ex: production)
 *   - OLLAMA_ENABLED    (ex: false en démo, true en local)
 */

// ============================================================
// ENVIRONNEMENT : local ou production
// ============================================================

// Détection automatique : si la variable APP_ENV est définie, on est en prod.
// Sinon, on est en local (XAMPP).
define('APP_ENV', getenv('APP_ENV') ?: 'local');

// ============================================================
// PARAMÈTRES FUSEKI
// ============================================================

// URL de base Fuseki : Render fournit la variable, sinon localhost par défaut
define('FUSEKI_BASE_URL', getenv('FUSEKI_BASE_URL') ?: 'https://fuseki-apa4cad.onrender.com');

// Nom du dataset : modifiable via env, sinon 'mononto' par défaut
define('FUSEKI_DATASET', getenv('FUSEKI_DATASET') ?: 'mononto');

// Endpoints calculés automatiquement
define('FUSEKI_QUERY_ENDPOINT',  FUSEKI_BASE_URL . '/' . FUSEKI_DATASET . '/query');
define('FUSEKI_UPDATE_ENDPOINT', FUSEKI_BASE_URL . '/' . FUSEKI_DATASET . '/update');

// ============================================================
// NAMESPACE DE L'ONTOLOGIE
// ============================================================

define('ONTO_NAMESPACE', 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#');
define('ONTO_PREFIX',    'ex');

// ============================================================
// CONFIGURATION OLLAMA (IA locale)
// ============================================================

// Ollama activé uniquement en local par défaut (nécessite beaucoup de RAM)
// En production (Render), il est désactivé pour la démo.
$_ollamaDefault = (APP_ENV === 'local') ? 'true' : 'false';
define('OLLAMA_ENABLED', strtolower(getenv('OLLAMA_ENABLED') ?: $_ollamaDefault) === 'true');
define('OLLAMA_URL',     getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('OLLAMA_MODEL',   getenv('OLLAMA_MODEL') ?: 'llama3.2:1b');

// ============================================================
// PRÉFIXES STANDARDS À INCLURE DANS TOUTES LES REQUÊTES
// ============================================================

function sparqlPrefixes() {
    return "
        PREFIX ex:   <" . ONTO_NAMESPACE . ">
        PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX owl:  <http://www.w3.org/2002/07/owl#>
        PREFIX xsd:  <http://www.w3.org/2001/XMLSchema#>
    ";
}

// ============================================================
// HELPER : vérifier si on est en mode démo
// ============================================================

function isDemoMode() {
    return APP_ENV === 'production';
}
