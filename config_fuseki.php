<?php
/**
 * APA4CAD - Configuration centralisée Fuseki
 *
 * Ce fichier centralise les paramètres de connexion à Apache Fuseki
 * pour TOUS les fichiers de l'application (Module 1 et Module 2).
 *
 * À adapter selon ton installation locale.
 */

// ============================================================
// PARAMÈTRES À VÉRIFIER / ADAPTER
// ============================================================

// Nom de ton dataset Fuseki (regarde dans l'URL : http://localhost:3030/CE_NOM)
// REMPLACE par ton vrai nom de dataset !
define('FUSEKI_DATASET', 'mononto');

// URL de base Fuseki (par défaut sur XAMPP local)
define('FUSEKI_BASE_URL', 'http://localhost:3030');

// Endpoints calculés automatiquement
define('FUSEKI_QUERY_ENDPOINT',  FUSEKI_BASE_URL . '/' . FUSEKI_DATASET . '/query');
define('FUSEKI_UPDATE_ENDPOINT', FUSEKI_BASE_URL . '/' . FUSEKI_DATASET . '/update');

// Namespace de l'ontologie (relevé depuis ton fichier .rdf)
define('ONTO_NAMESPACE', 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#');
define('ONTO_PREFIX',    'ex'); // alias utilisé dans les requêtes SPARQL

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
