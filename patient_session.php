<?php
/**
 * APA4CAD - Module 2 : Gestion de session pour le parcours complet
 *
 * Le parcours inversé est :
 *   1. index.php       → choix pathologies
 *   2. rapport.php     → voir recos, cliquer "Attribuer à un patient"
 *   3. patient.php     → créer/sélectionner patient
 *   4. freins.php      → cocher freins + leviers (ou passer)
 *   5. resume.php      → générer résumé IA (ou passer)
 *   6. enregistrement final
 *
 * Toutes les données voyagent en session via les helpers ci-dessous.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─────────────────────────────────────────────────────────────────────────
//  HELPERS DE PARCOURS — pathologies, activités, freins, leviers, résumé
// ─────────────────────────────────────────────────────────────────────────

/** Stocker les pathologies sélectionnées (étape 1). */
function setSessionPathologies(array $pathoUris): void {
    $_SESSION['parcours_pathologies'] = array_values(array_filter($pathoUris, 'is_string'));
}

/** Stocker les activités finales (étape 2). */
function setSessionActivities(array $activityUris): void {
    $_SESSION['parcours_activites'] = array_values(array_filter($activityUris, 'is_string'));
}

/** Stocker les freins cochés (étape 4). */
function setSessionFreins(array $freinUris): void {
    $_SESSION['parcours_freins'] = array_values(array_filter($freinUris, 'is_string'));
}

/** Stocker les leviers cochés (étape 4). */
function setSessionLeviers(array $levierUris): void {
    $_SESSION['parcours_leviers'] = array_values(array_filter($levierUris, 'is_string'));
}

/**
 * Stocker les contre-indications bloquantes (étape 2).
 * Format : [['activity' => 'Boxe', 'reasons' => ['BPCO', 'Asthme sévère']], ...]
 */
function setSessionContraindications(array $contras): void {
    $_SESSION['parcours_contraindications'] = $contras;
}

/** Stocker le résumé IA (étape 5). */
function setSessionResume(string $resume): void {
    $_SESSION['parcours_resume'] = $resume;
}

/** Récupérer les éléments du parcours en cours. */
function getParcoursPathologies(): array { return $_SESSION['parcours_pathologies'] ?? []; }
function getParcoursActivites(): array   { return $_SESSION['parcours_activites']   ?? []; }
function getParcoursFreins(): array      { return $_SESSION['parcours_freins']      ?? []; }
function getParcoursLeviers(): array     { return $_SESSION['parcours_leviers']     ?? []; }
function getParcoursResume(): string     { return $_SESSION['parcours_resume']      ?? ''; }
function getParcoursContraindications(): array { return $_SESSION['parcours_contraindications'] ?? []; }

/** Vider tout le parcours en cours (après enregistrement réussi). */
function clearParcours(): void {
    unset(
        $_SESSION['parcours_pathologies'],
        $_SESSION['parcours_activites'],
        $_SESSION['parcours_freins'],
        $_SESSION['parcours_leviers'],
        $_SESSION['parcours_resume'],
        $_SESSION['parcours_contraindications'],
        $_SESSION['patient_uri'],
        $_SESSION['patient_fragment'],
        $_SESSION['patient_nom'],
        $_SESSION['patient_prenom'],
        $_SESSION['patient_age'],
        $_SESSION['patient_dossier']
    );
}

// ─────────────────────────────────────────────────────────────────────────
//  HELPERS PATIENT — sélection en session
// ─────────────────────────────────────────────────────────────────────────

/**
 * Garantit qu'un patient est sélectionné, sinon redirige vers patient.php.
 * Utilisé sur les pages 4, 5, 6 du parcours.
 */
function requirePatientSelected(): void {
    if (empty($_SESSION['patient_uri'])) {
        $_SESSION['return_after_patient'] = $_SERVER['REQUEST_URI'] ?? 'freins.php';
        header('Location: patient.php');
        exit;
    }
}

/**
 * Garantit que les pathologies sont en session, sinon redirige vers index.php.
 * Utilisé sur toutes les pages après l'étape 1.
 */
function requirePathologiesSelected(): void {
    if (empty($_SESSION['parcours_pathologies'])) {
        header('Location: index.php');
        exit;
    }
}

/** Récupère les infos du patient en session. */
function getPatient(): ?array {
    if (empty($_SESSION['patient_uri'])) return null;
    $prenom = $_SESSION['patient_prenom'] ?? '';
    $nom    = $_SESSION['patient_nom']    ?? '';
    return [
        'uri'      => $_SESSION['patient_uri'],
        'fragment' => $_SESSION['patient_fragment'] ?? '',
        'nom'      => $nom,
        'prenom'   => $prenom,
        'age'      => $_SESSION['patient_age']     ?? '',
        'dossier'  => $_SESSION['patient_dossier'] ?? '',
        'fullname' => trim($prenom . ' ' . $nom) ?: '(patient sans nom)',
    ];
}

// ─────────────────────────────────────────────────────────────────────────
//  AFFICHAGE — bandeau patient + stepper de parcours
// ─────────────────────────────────────────────────────────────────────────

/**
 * Bandeau "Patient sélectionné" pour les pages 4, 5, 6.
 */
function renderPatientBanner(): void {
    $p = getPatient();
    if (!$p) return;
    ?>
    <style>
    .patient-banner{display:flex;align-items:center;justify-content:space-between;gap:14px;
                    background:linear-gradient(135deg,#dbeafe,#eff6ff);
                    border:1px solid #93c5fd;border-radius:14px;padding:12px 18px;
                    margin:0 auto 18px;max-width:1360px;box-shadow:0 4px 12px rgba(37,99,235,.08)}
    .patient-banner .pb-info{display:flex;align-items:center;gap:14px;font-size:14px}
    .patient-banner .pb-icon{width:42px;height:42px;border-radius:50%;background:#2563eb;
                              color:#fff;display:flex;align-items:center;justify-content:center;
                              font-size:20px;font-weight:700;flex-shrink:0}
    .patient-banner .pb-name{font-weight:800;color:#1d4ed8;font-size:16px}
    .patient-banner .pb-meta{color:#475569;font-size:13px;margin-top:2px}
    .patient-banner .pb-meta .pb-dossier{font-family:'Courier New',monospace;
                                          background:rgba(255,255,255,.7);padding:2px 8px;
                                          border-radius:6px;font-size:12px;margin-left:4px}
    @media print{.patient-banner{display:flex !important;background:#fff !important;
                                 border:1px solid #ccc !important;page-break-inside:avoid}}
    </style>
    <div class="patient-banner">
        <div class="pb-info">
            <div class="pb-icon">👤</div>
            <div>
                <div class="pb-name"><?= htmlspecialchars($p['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="pb-meta">
                    <?php if ($p['age'] !== ''): ?><?= htmlspecialchars((string)$p['age']) ?> ans<?php endif; ?>
                    <?php if ($p['dossier'] !== ''): ?>
                        · Dossier <span class="pb-dossier"><?= htmlspecialchars($p['dossier']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Stepper de parcours visuel à afficher sur chaque page.
 *
 * @param int $currentStep  Étape en cours (1 à 5)
 */
function renderParcoursStepper(int $currentStep = 1): void {
    $steps = [
        1 => ['Pathologies',     'index.php'],
        2 => ['Recommandations', 'rapport.php'],
        3 => ['Patient',         'patient.php'],
        4 => ['Freins/Leviers',  'freins.php'],
        5 => ['Résumé IA',       'resume.php'],
    ];
    ?>
    <style>
    .parcours-stepper{display:flex;justify-content:center;align-items:center;gap:8px;
                       margin:14px auto 0;flex-wrap:wrap;max-width:1100px}
    .parcours-stepper .step{display:flex;align-items:center;gap:8px;
                             background:rgba(255,255,255,.16);color:#fff;
                             padding:7px 14px;border-radius:999px;font-size:13px;
                             font-weight:600;transition:.15s}
    .parcours-stepper .step.done{background:rgba(255,255,255,.28);opacity:.85}
    .parcours-stepper .step.current{background:#fff;color:#1d4ed8;
                                     box-shadow:0 4px 12px rgba(0,0,0,.15);transform:scale(1.05)}
    .parcours-stepper .step .num{width:20px;height:20px;border-radius:50%;
                                  background:rgba(255,255,255,.3);display:flex;
                                  align-items:center;justify-content:center;
                                  font-weight:800;font-size:11px}
    .parcours-stepper .step.current .num{background:#2563eb;color:#fff}
    .parcours-stepper .step.done .num{background:rgba(255,255,255,.45)}
    .parcours-stepper .arrow{color:rgba(255,255,255,.7);font-size:14px}
    .parcours-stepper a.step{text-decoration:none}
    .parcours-stepper a.step:hover{background:rgba(255,255,255,.32)}
    </style>
    <div class="parcours-stepper">
    <?php
    $isFirst = true;
    foreach ($steps as $num => [$label, $url]) {
        if (!$isFirst) echo '<span class="arrow">→</span>';
        $isFirst = false;

        $class = '';
        if ($num < $currentStep) $class = 'done';
        elseif ($num === $currentStep) $class = 'current';

        // Les étapes franchies sont cliquables (pour revenir en arrière), pas les futures
        $tag = ($num < $currentStep) ? "a href=\"$url\"" : 'div';
        $closeTag = ($num < $currentStep) ? 'a' : 'div';
        echo "<$tag class=\"step $class\"><span class=\"num\">$num</span> $label</$closeTag>";
    }
    ?>
    </div>
    <?php
}
