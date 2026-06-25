<?php
require_once __DIR__ . '/sparql_update.php';
$q = sparqlPrefixes() . ' INSERT DATA { <http://test.com/p1> a ex:Praticien }';
echo "ENDPOINT: " . preg_replace('#https?://[^@]+@#', 'https://', FUSEKI_UPDATE_ENDPOINT) . "\n\n";
$res = sparqlUpdate($q);
echo "HTTP_CODE: " . $res['http_code'] . "\n";
echo "ERROR: " . var_export($res['error'], true) . "\n";