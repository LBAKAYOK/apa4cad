<?php
/**
 * APA4CAD - Endpoint de stockage en session des freins/leviers
 *
 * Stocke en session :
 *   - $_SESSION['parcours_freins']
 *   - $_SESSION['parcours_leviers']                (liste globale, pour rétro-compat)
 *   - $_SESSION['parcours_leviers_par_activite']   (mapping activityId → [leviers])
 *
 * Le dernier champ permet à enregistrer_prescription.php de créer des triplets
 * SPARQL ciblés par activité (chaque activité a SES propres leviers).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/patient_session.php';

if (isset($_GET['skip']) && $_GET['skip'] === '1') {
    setSessionFreins([]);
    setSessionLeviers([]);
    $_SESSION['parcours_leviers_par_activite'] = [];
    echo json_encode(['success' => true, 'skipped' => true]);
    exit;
}

$freins  = $_POST['freins']  ?? [];
$leviers = $_POST['leviers'] ?? [];
$leviersParActivite = $_POST['leviers_par_activite'] ?? [];

if (!is_array($freins))  $freins  = [$freins];
if (!is_array($leviers)) $leviers = [$leviers];
if (!is_array($leviersParActivite)) $leviersParActivite = [];

// Nettoyer le mapping par activité
$cleanedMapping = [];
foreach ($leviersParActivite as $activityId => $lvList) {
    if (!is_string($activityId) || $activityId === '') continue;
    if (!is_array($lvList)) $lvList = [$lvList];
    $lvList = array_values(array_filter($lvList, fn($v) => is_string($v) && $v !== ''));
    if (!empty($lvList)) $cleanedMapping[$activityId] = $lvList;
}

setSessionFreins($freins);
setSessionLeviers($leviers);
$_SESSION['parcours_leviers_par_activite'] = $cleanedMapping;

echo json_encode([
    'success'   => true,
    'nb_freins'  => count($freins),
    'nb_leviers' => count($leviers),
    'nb_activites_avec_leviers' => count($cleanedMapping),
]);
