<?php
require_once __DIR__ . '/config_fuseki.php';
$endpoint = preg_replace('#https?://[^@]+@#', 'https://', FUSEKI_UPDATE_ENDPOINT);
echo "BASE: " . FUSEKI_BASE_URL . "\n";
echo "UPDATE_ENDPOINT: " . FUSEKI_UPDATE_ENDPOINT . "\n";
echo "ENDPOINT APRES PREG: " . $endpoint . "\n";
echo "PASSWORD ENV: " . (getenv('FUSEKI_ADMIN_PASSWORD') ?: 'VIDE') . "\n";
?>
