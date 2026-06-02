<?php
declare(strict_types=1);
require_once __DIR__ . '/praticien_session.php';

// Vide aussi le parcours de prescription en cours
unset(
    $_SESSION['parcours_pathologies'],
    $_SESSION['parcours_freins'],
    $_SESSION['parcours_leviers'],
    $_SESSION['patient_uri'],
    $_SESSION['patient_prenom'],
    $_SESSION['patient_nom'],
    $_SESSION['explore_mode'],
    $_SESSION['welcome_seen']
);

logoutPraticien();

header('Location: welcome.php');
exit;
