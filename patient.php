<?php
/**
 * APA4CAD - Patients (liste + création) — refonte UX épurée
 *
 * 2 modes :
 *   - Mode "consultation" (parcours en cours) → bouton Attribuer
 *   - Mode "gestion" (libre) → bouton Ouvrir le dossier
 */

declare(strict_types=1);

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/patient_session.php';
require_once __DIR__ . '/praticien_session.php';

// ─── Vérification : seul un praticien connecté peut accéder ──────────────
if (!isPraticienLoggedIn()) {
    header('Location: login_praticien.php');
    exit;
}

$currentPraticienUri  = currentPraticienUri();
$currentPraticienName = currentPraticienName();
$currentPraticienEsc  = '<' . str_replace('>', '', $currentPraticienUri) . '>';

function sparqlQueryRead(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query) . '&output=json';
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/sparql-results+json\r\n",
        'timeout' => 30, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['ok' => false, 'bindings' => []];
    $data = json_decode($response, true);
    if (!isset($data['results']['bindings'])) return ['ok' => false, 'bindings' => []];
    return ['ok' => true, 'bindings' => $data['results']['bindings']];
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function localName(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}
function prettyLabelP(string $name): string {
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $name)));
}
function normalizeName(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function ageToTrancheUri(int $age): ?string {
    if ($age >= 0  && $age <= 18) return ONTO_NAMESPACE . '0A18Ans';
    if ($age >= 19 && $age <= 30) return ONTO_NAMESPACE . '19A30Ans';
    if ($age >= 31 && $age <= 40) return ONTO_NAMESPACE . '31A40Ans';
    if ($age >= 41 && $age <= 50) return ONTO_NAMESPACE . '41A50Ans';
    if ($age >= 51 && $age <= 60) return ONTO_NAMESPACE . '51A60Ans';
    if ($age >  60)               return ONTO_NAMESPACE . 'PlusDe60';
    return null;
}
function sexeToGenreUri(string $sexe): ?string {
    $sexe = strtoupper(trim($sexe));
    if ($sexe === 'M') return ONTO_NAMESPACE . 'Masculin';
    if ($sexe === 'F') return ONTO_NAMESPACE . 'Feminin';
    return null;
}

$parcoursPathos = getParcoursPathologies();
$parcoursActivites = getParcoursActivites();
$parcoursCI = getParcoursContraindications();

// Le bandeau "Prescription en cours" ne s'affiche QUE si on vient explicitement
// du rapport (paramètre ?from=rapport, propagé via GET ou POST). Quand l'utilisateur
// arrive depuis le menu "Gestion des patients", on traite ça comme une visite normale
// sans contexte de parcours, même si la session contient encore des pathologies.
$comesFromRapport = (($_GET['from'] ?? '') === 'rapport')
                 || (($_POST['from'] ?? '') === 'rapport');
$hasContext = $comesFromRapport && !empty($parcoursPathos);

// Détecte un patient déjà sélectionné en session (cas typique : on vient de
// patient_detail.php → modal "Nouvelle consultation" → index → rapport → ici).
// Dans ce cas, on saute directement à l'écran "freins" du wizard.
$patientAlreadyInSession = $hasContext && !empty($_SESSION['patient_uri'] ?? '');
$prefilledPatient = null;
if ($patientAlreadyInSession) {
    $prefilledPatient = [
        'fullname' => trim(($_SESSION['patient_prenom'] ?? '') . ' ' . ($_SESSION['patient_nom'] ?? '')) ?: '(patient)',
        'age'      => $_SESSION['patient_age']     ?? '',
        'dossier'  => $_SESSION['patient_dossier'] ?? '',
    ];
}

$flash = null;

// POST création
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $nom = normalizeName($_POST['nom'] ?? '');
    $prenom = normalizeName($_POST['prenom'] ?? '');
    $sexe = trim($_POST['sexe'] ?? '');
    $age = (int)($_POST['age'] ?? 0);
    $numeroDossier = trim($_POST['numero_dossier'] ?? '');

    $errors = [];
    if ($nom === '') $errors[] = "Le nom est obligatoire.";
    if ($prenom === '') $errors[] = "Le prénom est obligatoire.";
    if ($numeroDossier === '') $errors[] = "Le numéro de dossier est obligatoire.";
    if ($age < 0 || $age > 120) $errors[] = "L'âge doit être entre 0 et 120 ans.";
    if ($sexe !== 'M' && $sexe !== 'F') $errors[] = "Le sexe est obligatoire.";

    if (empty($errors)) {
        $dossierEsc = sparqlEscapeString($numeroDossier);
        $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode(sparqlPrefixes() . " ASK { ?p a ex:Patient ; ex:aPourNumeroDossier \"$dossierEsc\" }");
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Accept: application/sparql-results+json\r\n"]]);
        $resp = @file_get_contents($url, false, $ctx);
        $askResult = json_decode($resp ?: '{}', true);
        if (isset($askResult['boolean']) && $askResult['boolean'] === true) {
            $errors[] = "Un patient avec ce numéro de dossier existe déjà.";
        }
    }

    if (empty($errors)) {
        $uriInfo = generatePatientUri($prenom, $nom);
        $fullUri = $uriInfo['full_uri'];
        $genreUri = sexeToGenreUri($sexe);
        $trancheUri = ageToTrancheUri($age);

        $triples = [
            "<$fullUri> rdf:type owl:NamedIndividual ;",
            "           rdf:type ex:Patient ;",
            "           ex:aPourNom \"" . sparqlEscapeString($nom) . "\" ;",
            "           ex:aPourPrenom \"" . sparqlEscapeString($prenom) . "\" ;",
            "           ex:aPourNumeroDossier \"" . sparqlEscapeString($numeroDossier) . "\" ;",
            "           ex:aPourAge \"$age\"^^xsd:integer",
        ];
        if ($genreUri) $triples[] = " ;           ex:aPourGenre <$genreUri>";
        if ($trancheUri) $triples[] = " ;           ex:aPourtrancheAge <$trancheUri>";
        // Signature : qui a créé ce patient
        if ($currentPraticienUri !== null && $currentPraticienUri !== '') {
            $triples[] = " ;           ex:creePar <$currentPraticienUri>";
        }
        $triples[] = " .";

        $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { " . implode("\n", $triples) . " }");

        if ($res['success']) {
            $_SESSION['patient_uri'] = $fullUri;
            $_SESSION['patient_fragment'] = $uriInfo['fragment'];
            $_SESSION['patient_nom'] = $nom;
            $_SESSION['patient_prenom'] = $prenom;
            $_SESSION['patient_age'] = (string)$age;
            $_SESSION['patient_dossier'] = $numeroDossier;

            if ($hasContext) { header('Location: freins.php'); exit; }
            header('Location: patient_detail.php?id=' . urlencode($uriInfo['fragment']));
            exit;
        } else {
            // Détail de l'erreur pour diagnostiquer
            $errDetail = $res['error'] ?? 'erreur inconnue';
            $errCode = $res['http_code'] ?? '?';
            $flash = ['type'=>'error','msg'=>"Erreur Fuseki (HTTP $errCode) : " . $errDetail];
        }
    } else {
        $flash = ['type'=>'error','msg'=>implode(' ', $errors)];
    }
}

// GET sélection
if (isset($_GET['select']) && !empty($_GET['select'])) {
    $selectedFragment = $_GET['select'];
    $selectedUri = ONTO_NAMESPACE . $selectedFragment;
    $verifyRes = sparqlQueryRead(sparqlPrefixes() . "
        SELECT ?nom ?prenom ?age ?dossier WHERE {
            <$selectedUri> a ex:Patient .
            OPTIONAL { <$selectedUri> ex:aPourNom ?nom }
            OPTIONAL { <$selectedUri> ex:aPourPrenom ?prenom }
            OPTIONAL { <$selectedUri> ex:aPourAge ?age }
            OPTIONAL { <$selectedUri> ex:aPourNumeroDossier ?dossier }
        }");
    if ($verifyRes['ok'] && !empty($verifyRes['bindings'])) {
        $b = $verifyRes['bindings'][0];
        $_SESSION['patient_uri'] = $selectedUri;
        $_SESSION['patient_fragment'] = $selectedFragment;
        $_SESSION['patient_nom'] = $b['nom']['value'] ?? '';
        $_SESSION['patient_prenom'] = $b['prenom']['value'] ?? '';
        $_SESSION['patient_age'] = $b['age']['value'] ?? '';
        $_SESSION['patient_dossier'] = $b['dossier']['value'] ?? '';
        if ($hasContext) { header('Location: freins.php'); exit; }
        header('Location: patient_detail.php?id=' . urlencode($selectedFragment));
        exit;
    }
}

$searchTerm = trim($_GET['q'] ?? '');
$searchFilter = '';
if ($searchTerm !== '') {
    $searchEsc = sparqlEscapeString($searchTerm);
    $searchFilter = "FILTER(CONTAINS(LCASE(?nom), LCASE(\"$searchEsc\")) || CONTAINS(LCASE(?prenom), LCASE(\"$searchEsc\")) || CONTAINS(LCASE(?dossier), LCASE(\"$searchEsc\")))";
}

// ── Récupération des labels des éléments sélectionnés pour la synthèse ────
// La session ne stocke que les URIs, on a besoin des noms affichables.
// On préfère le label FRANÇAIS (rdfs:label@fr), sinon n'importe quel label,
// sinon on tombe sur le fragment de l'URI nettoyé.
$parcoursPathosLabels   = [];
$parcoursActivitesLabels = [];

/**
 * Construit un tableau URI => label en privilégiant la langue française.
 * Si plusieurs labels existent (français + anglais), on ne garde que le français.
 */
function buildLabelsMap(array $bindings, array $urisOrder): array {
    $byUri = []; // uri => ['fr'=>..., 'any'=>...]
    foreach ($bindings as $b) {
        $uri = $b['uri']['value'] ?? '';
        if ($uri === '') continue;
        $lbl = $b['label']['value'] ?? '';
        $lang = $b['label']['xml:lang'] ?? '';
        if (!isset($byUri[$uri])) $byUri[$uri] = ['fr'=>'', 'any'=>''];
        if ($lang === 'fr' && $byUri[$uri]['fr'] === '') $byUri[$uri]['fr'] = $lbl;
        if ($byUri[$uri]['any'] === '' && $lbl !== '') $byUri[$uri]['any'] = $lbl;
    }
    $out = [];
    foreach ($urisOrder as $uri) {
        $lbl = $byUri[$uri]['fr'] ?: $byUri[$uri]['any'] ?? '';
        $out[] = $lbl !== '' ? $lbl : prettyLabelP(localName($uri));
    }
    return $out;
}

if ($hasContext) {
    // Pathologies sélectionnées
    if (!empty($parcoursPathos)) {
        $values = '<' . implode('> <', $parcoursPathos) . '>';
        $r = sparqlQueryRead(sparqlPrefixes() . "
            SELECT ?uri ?label WHERE {
                VALUES ?uri { $values }
                OPTIONAL { ?uri rdfs:label ?label }
            }");
        $parcoursPathosLabels = buildLabelsMap($r['bindings'] ?? [], $parcoursPathos);
    }
    // Activités recommandées
    if (!empty($parcoursActivites)) {
        $values = '<' . implode('> <', $parcoursActivites) . '>';
        $r = sparqlQueryRead(sparqlPrefixes() . "
            SELECT ?uri ?label WHERE {
                VALUES ?uri { $values }
                OPTIONAL { ?uri rdfs:label ?label }
            }");
        $parcoursActivitesLabels = buildLabelsMap($r['bindings'] ?? [], $parcoursActivites);
    }
}
$nbPathos = count($parcoursPathosLabels);
$nbActivites = count($parcoursActivitesLabels);
$nbCI = count($parcoursCI);

// Map URI activité → noms des pathos qui la recommandent (stockée par rapport.php)
$parcoursActivitesPathos = $_SESSION['parcours_activites_pathos'] ?? [];

// ── Chargement des données freins/leviers/activités finales (wizard étape 4) ──
// Inclus en mode contexte pour pouvoir afficher la pane "freins" sans recharger
$selected = $parcoursPathos; // variable attendue par freins_data.php
if ($hasContext) {
    require_once __DIR__ . '/freins_data.php';
}

$patients = [];
$listRes = sparqlQueryRead(sparqlPrefixes() . "
    SELECT DISTINCT ?uri ?nom ?prenom ?age ?dossier ?genreLabel WHERE {
        ?uri a ex:Patient .
        {
            # Patients créés par le praticien connecté
            ?uri ex:creePar $currentPraticienEsc .
        } UNION {
            # Patients ayant une prescription signée par le praticien connecté
            ?prescription a ex:Prescription ;
                          ex:concerne ?uri ;
                          ex:prescritPar $currentPraticienEsc .
        }
        OPTIONAL { ?uri ex:aPourNom ?nom }
        OPTIONAL { ?uri ex:aPourPrenom ?prenom }
        OPTIONAL { ?uri ex:aPourAge ?age }
        OPTIONAL { ?uri ex:aPourNumeroDossier ?dossier }
        OPTIONAL { ?uri ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
        $searchFilter
    }
    ORDER BY ?nom ?prenom");
if ($listRes['ok']) {
    foreach ($listRes['bindings'] as $b) {
        $uri = $b['uri']['value'];
        $patients[] = [
            'uri' => $uri, 'fragment' => localName($uri),
            'nom' => $b['nom']['value'] ?? '',
            'prenom' => $b['prenom']['value'] ?? '',
            'age' => $b['age']['value'] ?? '',
            'dossier' => $b['dossier']['value'] ?? '',
            'genre' => $b['genreLabel']['value'] ?? '',
        ];
    }
}

// Stats pour mode "Gestion libre" : total, hommes, femmes
$stats = ['total' => count($patients), 'hommes' => 0, 'femmes' => 0];
foreach ($patients as $p) {
    $g = strtolower($p['genre']);
    if ($g === 'masculin') $stats['hommes']++;
    elseif ($g === 'feminin' || $g === 'féminin') $stats['femmes']++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $hasContext ? 'Attribuer la prescription' : 'Mes patients' ?> · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}
button{font-family:inherit;cursor:pointer}

.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 0}
.topbar-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:32px}
.topbar-brand{font-weight:700;font-size:17px;color:#1d4ed8;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#1d4ed8;border-radius:2px;display:inline-block}
.topbar-nav{display:flex;gap:6px;margin-left:auto}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#475569;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:#f1f5f9;color:#1e293b}
.topbar-nav a.active{background:#eff6ff;color:#1d4ed8;font-weight:600}

.app{max-width:1200px;margin:0 auto;padding:32px 24px 80px}

.banner{background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;
        border-radius:18px;padding:30px 34px;margin-bottom:28px;
        box-shadow:0 14px 28px rgba(37,99,235,.18)}
.banner .crumbs{font-size:12px;opacity:.85;margin-bottom:8px}
.banner .crumbs a{color:#fff;opacity:.9}
.banner .crumbs .sep{margin:0 6px;opacity:.6}
.banner h1{margin:0;font-size:28px;font-weight:700;letter-spacing:-0.02em}
.banner .subtitle{margin-top:8px;opacity:.92;font-size:14px}

.context-banner{background:#fff;border:1px solid #e5e7eb;border-left:4px solid #2563eb;
                border-radius:12px;padding:16px 20px;margin-bottom:22px;
                display:flex;align-items:center;gap:14px}
.context-banner .ctx-icon{font-size:24px;flex-shrink:0}
.context-banner .ctx-text{font-size:13px;color:#475569}
.context-banner .ctx-text strong{color:#1e293b}

.layout-2{display:grid;grid-template-columns:1.4fr 1fr;gap:22px}
@media(max-width:900px){.layout-2{grid-template-columns:1fr}}

.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
      padding:24px 26px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.card-head h2{margin:0;font-size:16px;font-weight:700;color:#1e293b;letter-spacing:-0.01em}
.card-head .count{color:#9ca3af;font-weight:400;margin-left:4px;font-size:14px}

.search-row{display:flex;gap:8px;margin-bottom:18px}
.search-row input{flex:1;padding:11px 14px;border:1px solid #e5e7eb;
                   border-radius:10px;font-size:14px;font-family:inherit}
.search-row input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.search-row .clear-btn{padding:0 14px;background:#fff;border:1px solid #e5e7eb;
                        border-radius:10px;color:#6b7280;font-weight:600;display:flex;align-items:center}
.search-row .clear-btn:hover{background:#f9fafb}

.patient-list{display:flex;flex-direction:column;gap:6px;max-height:560px;overflow-y:auto}
.patient-row{display:flex;align-items:center;gap:12px;padding:14px 16px;
              border:1px solid #e5e7eb;border-radius:10px;background:#fff;transition:.15s}
.patient-row:hover{border-color:#bfdbfe;background:#fbfdff}
.patient-row .info{flex:1;min-width:0}
.patient-row .name{font-weight:700;color:#1e293b;font-size:14px;margin-bottom:3px}
.patient-row .meta{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#6b7280}
.patient-row .meta .dossier{font-family:ui-monospace,monospace;background:#f1f5f9;
                              padding:2px 7px;border-radius:5px;font-size:11px}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;
     border-radius:8px;font-size:12px;font-weight:600;border:1px solid;
     text-decoration:none;transition:.15s;background:#fff;cursor:pointer}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}

.create-form .field{margin-bottom:14px}
.create-form .field label{display:block;font-size:12px;color:#6b7280;
                            margin-bottom:5px;font-weight:600}
.create-form .field input,.create-form .field select{
    width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:9px;
    font-size:14px;font-family:inherit;background:#fff;color:#1e293b}
.create-form .field input:focus,.create-form .field select:focus{
    outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.create-form .field .hint{font-size:11px;color:#9ca3af;margin-top:4px;font-style:italic}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

.btn-submit{background:#2563eb;color:#fff;border:none;border-radius:10px;
             padding:12px 18px;font-weight:700;font-size:14px;width:100%;
             transition:.15s;margin-top:8px}
.btn-submit:hover{background:#1d4ed8;box-shadow:0 4px 12px rgba(37,99,235,.3)}

.empty{padding:36px 16px;text-align:center;color:#9ca3af;font-size:13px;font-style:italic}

.flash{padding:12px 18px;border-radius:10px;margin-bottom:22px;font-size:13px;
        font-weight:500;border:1px solid}
.flash-error{background:#fef2f2;color:#b91c1c;border-color:#fca5a5}
.flash-info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}

/* ═══════════════════════════════════════════════════════════════════════
   MODE PARCOURS (étape 3/5) — Refonte UI prescription
   ═══════════════════════════════════════════════════════════════════════ */

/* ── Stepper 5 étapes (style index.php) ──────────────────────────────── */
.stepper-bar{background:linear-gradient(135deg,#1d4ed8,#4b8df8);
             border-radius:18px;padding:20px 24px;margin-bottom:24px;
             box-shadow:0 10px 24px rgba(37,99,235,.18)}

/* ── Bouton flottant Retour (style mobile) ──────────────────────────── */
.back-fab{position:fixed;top:78px;left:24px;z-index:50;
          display:inline-flex;align-items:center;gap:8px;
          background:#fff;border:1px solid #e5e7eb;border-radius:999px;
          padding:10px 18px 10px 14px;
          color:#1d4ed8;font-size:14px;font-weight:700;
          text-decoration:none;
          box-shadow:0 6px 16px rgba(15,23,42,.12);
          transition:.2s cubic-bezier(.4,0,.2,1)}
.back-fab:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;
                transform:translateX(-3px);
                box-shadow:0 10px 24px rgba(37,99,235,.35)}
.back-fab-arrow{font-size:18px;line-height:1;font-weight:800;
                 transition:transform .2s}
.back-fab:hover .back-fab-arrow{transform:translateX(-2px)}
.back-fab-lbl{letter-spacing:.2px}
@media(max-width:700px){
    .back-fab{padding:10px;border-radius:50%}
    .back-fab-lbl{display:none}
}
.stepper{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
.step{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;
      background:rgba(255,255,255,.18);color:#fff;font-size:13px;font-weight:600;
      text-decoration:none;transition:.15s}
.step-done{background:rgba(255,255,255,.28);opacity:.92}
.step-done:hover{background:rgba(255,255,255,.38);opacity:1}
.step-current{background:#fff;color:#1d4ed8;
              box-shadow:0 4px 12px rgba(0,0,0,.15);transform:scale(1.06)}
.step-todo{opacity:.55}
.step-num{width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.32);
          display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px}
.step-current .step-num{background:#2563eb;color:#fff}
.step-done .step-num{background:rgba(255,255,255,.5);color:#1e40af}
.step-sep{color:rgba(255,255,255,.6);font-size:14px;margin:0 2px}

/* ── Layout 2 colonnes : Synthèse / Choix patient ────────────────────── */
.rx-layout{display:grid;grid-template-columns:1fr 1.3fr;gap:22px;align-items:start}
@media(max-width:960px){.rx-layout{grid-template-columns:1fr}}

/* ── Colonne gauche : synthèse ───────────────────────────────────────── */
.rx-synth{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
          padding:22px 24px;box-shadow:0 1px 3px rgba(15,23,42,.04);
          position:sticky;top:22px}
.rx-synth-head{padding-bottom:14px;margin-bottom:16px;border-bottom:1px solid #f1f5f9}
.rx-synth-title{font-size:15px;font-weight:800;color:#1e293b;letter-spacing:-0.01em}
.rx-synth-sub{font-size:12px;color:#94a3b8;margin-top:3px;font-style:italic}

.rx-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
.rx-stat{background:linear-gradient(135deg,#eff6ff,#dbeafe);
         border:1px solid #bfdbfe;border-radius:10px;padding:12px 6px;text-align:center}
.rx-stat-num{font-size:24px;font-weight:800;color:#1d4ed8;line-height:1}
.rx-stat-lbl{font-size:10px;font-weight:600;color:#475569;text-transform:uppercase;
              letter-spacing:.4px;margin-top:4px}
.rx-stat-warn{background:linear-gradient(135deg,#fef2f2,#fee2e2);border-color:#fca5a5}
.rx-stat-warn .rx-stat-num{color:#b91c1c}
.rx-stat-ok{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#a7f3d0}
.rx-stat-ok .rx-stat-num{color:#047857}
.rx-stat-ok .rx-stat-lbl{color:#065f46}

/* Bandeau d'alerte quand aucune activité recommandable */
.rx-noreco{background:linear-gradient(135deg,#fef2f2,#fee2e2);
           border:1px solid #fca5a5;border-left:4px solid #dc2626;
           border-radius:12px;padding:18px 20px;margin-bottom:18px;text-align:center}
.rx-noreco-icon{font-size:32px;margin-bottom:6px;line-height:1}
.rx-noreco-title{font-size:14px;font-weight:800;color:#991b1b;
                  text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
.rx-noreco-msg{font-size:13px;color:#7f1d1d;text-align:left;line-height:1.55}
.rx-noreco-reasons{margin:8px 0 0;padding-left:0;list-style:none}
.rx-noreco-reasons li{padding:6px 10px;background:rgba(255,255,255,.5);
                       border-radius:7px;margin-bottom:4px;font-size:12px;line-height:1.5}
.rx-noreco-reasons li strong{color:#991b1b}
.rx-noreco-reasons li em{color:#b91c1c;font-style:normal;font-weight:600}

.rx-block{margin-bottom:16px}
.rx-block-title{font-size:11px;font-weight:800;color:#475569;
                 text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.rx-tags{display:flex;flex-wrap:wrap;gap:6px}
.rx-tag{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;
        font-weight:600;border:1px solid}
.rx-tag-blue{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}

.rx-list{margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:4px}
.rx-list li{padding:6px 10px;background:#f8fafc;border-radius:7px;
             font-size:13px;color:#334155;font-weight:500;
             border-left:3px solid #10b981;
             display:flex;align-items:center;justify-content:space-between;gap:10px}
.rx-list li strong{color:#1e293b;font-weight:600}
.rx-reco-pathos{font-size:11px;color:#047857;font-weight:600;
                 font-style:italic;white-space:nowrap;flex-shrink:0}
.rx-block-ci .rx-block-title{color:#b91c1c}
.rx-list-ci li{background:#fef2f2;border-left-color:#dc2626;color:#7f1d1d}
.rx-list-ci li strong{color:#991b1b}
.rx-ci-reason{font-size:11px;color:#b91c1c;font-style:italic;
               white-space:nowrap;flex-shrink:0;font-weight:600}

.rx-back{display:inline-block;margin-top:8px;padding:7px 0;font-size:12px;
         color:#6b7280;font-weight:500;text-decoration:none}
.rx-back:hover{color:#1d4ed8}

/* ── Colonne droite : choix patient ──────────────────────────────────── */
.rx-choice{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
           padding:24px 28px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.rx-choice-head{margin-bottom:20px}
.rx-choice-head h2{margin:0 0 6px;font-size:20px;font-weight:800;
                    color:#1e293b;letter-spacing:-0.01em}
.rx-choice-head p{margin:0;font-size:13px;color:#6b7280}

/* Cartes de choix Ancien/Nouveau */
.choice-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px}
.choice-card{background:#fff;border:2px solid #e5e7eb;border-radius:14px;
              padding:22px 16px;text-align:center;cursor:pointer;
              transition:.18s;font-family:inherit}
.choice-card:hover{border-color:#93c5fd;background:#f8fbff;transform:translateY(-2px);
                    box-shadow:0 8px 20px rgba(37,99,235,.1)}
.choice-card.active{border-color:#2563eb;background:linear-gradient(135deg,#eff6ff,#fff);
                     box-shadow:0 8px 20px rgba(37,99,235,.18)}
.choice-icon{font-size:34px;margin-bottom:8px;line-height:1}
.choice-lbl{font-size:15px;font-weight:800;color:#1e293b;margin-bottom:4px}
.choice-card.active .choice-lbl{color:#1d4ed8}
.choice-sub{font-size:11px;color:#94a3b8;font-weight:500}

/* Panes : masqués par défaut, affichés quand .open */
.pane{display:none;margin-top:22px;padding-top:22px;border-top:1px dashed #e5e7eb;
      animation:fadeIn .25s ease-out}
.pane.open{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}

/* Le formulaire et la search-row gardent leurs styles existants
   (.search-row, .patient-list, .patient-row, .field, .field-row, .btn-submit) */

/* Indice de recherche : "Tapez au moins 3 caractères..." */
.search-hint{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
             padding:14px 18px;color:#1d4ed8;font-size:13px;line-height:1.5;
             text-align:center}
.search-hint strong{color:#1e40af;font-weight:700}

/* Le bouton ✕ devient un bouton (avant c'était un <a>) */
button.clear-btn{padding:0 14px;background:#fff;border:1px solid #e5e7eb;
                  border-radius:10px;color:#6b7280;font-weight:600;cursor:pointer;
                  font-family:inherit;font-size:14px}
button.clear-btn:hover{background:#f9fafb}

/* ═══════════════════════════════════════════════════════════════════════
   WIZARD SPA : étapes 3 (patient) + 4 (freins) sur la même page
   ═══════════════════════════════════════════════════════════════════════ */

.wizard-screen{display:none;animation:wizFadeIn .35s ease-out}
.wizard-screen.active{display:block}
@keyframes wizFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* Confirmation de patient choisi (header de l'étape 4) */
.wiz-patient-confirm{background:linear-gradient(135deg,#dbeafe,#eff6ff);
                     border:1px solid #93c5fd;border-radius:14px;padding:14px 18px;
                     margin-bottom:18px;display:flex;align-items:center;gap:14px}
.wiz-patient-confirm-icon{width:44px;height:44px;border-radius:50%;background:#2563eb;
                           color:#fff;display:flex;align-items:center;justify-content:center;
                           font-size:20px;font-weight:700;flex-shrink:0}
.wiz-patient-confirm-name{font-size:16px;font-weight:800;color:#1d4ed8}
.wiz-patient-confirm-meta{font-size:12px;color:#475569;margin-top:2px}
.wiz-patient-confirm-change{margin-left:auto;background:#fff;border:1px solid #93c5fd;
                             color:#1d4ed8;padding:7px 14px;border-radius:8px;font-size:12px;
                             font-weight:600;cursor:pointer;font-family:inherit}
.wiz-patient-confirm-change:hover{background:#eff6ff}

/* Section "Activités adaptées" */
.fr-section{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
            box-shadow:0 1px 3px rgba(15,23,42,.04);overflow:hidden;margin-bottom:18px}
.fr-section-header{display:flex;align-items:center;gap:10px;padding:14px 18px;
                    border-bottom:1px solid #e5e7eb;border-left:4px solid #059669;
                    background:linear-gradient(90deg,#f0fdf4,#fff)}
.fr-section-title{font-size:14px;font-weight:800;flex:1;color:#064e3b;
                   text-transform:uppercase;letter-spacing:.4px}
.fr-section-count{font-size:11px;font-weight:700;background:#ecfdf5;color:#059669;
                   border:1px solid #6ee7b7;border-radius:20px;padding:2px 10px}
.fr-section-body{padding:14px 18px}
.fr-helper{font-size:12px;color:#64748b;margin-bottom:12px;font-style:italic}

.fr-act{border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:8px;transition:.2s}
.fr-act:last-child{margin-bottom:0}
.fr-act-top{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap}
.fr-act-name{font-size:14px;font-weight:700;flex:1;color:#1e293b}
.fr-act-status{font-size:10px;font-weight:700;border-radius:20px;padding:3px 10px;
                white-space:nowrap;flex-shrink:0}
.fr-act-compatible{background:#f0fdf8;border-color:#d1fae5;border-left:4px solid #059669}
.fr-act-compatible .fr-act-name{color:#064e3b}
.fr-act-compatible .fr-act-status{background:#ecfdf5;color:#059669;border:1px solid #6ee7b7}
.fr-act-supported{background:#fffbeb;border-color:#fde68a;border-left:4px solid #d97706}
.fr-act-supported .fr-act-name{color:#78350f}
.fr-act-supported .fr-act-status{background:#fffbeb;color:#d97706;border:1px solid #fde68a}
.fr-act-neutral{background:#fff;border-left:4px solid #cbd5e1}
.fr-act-neutral .fr-act-status{background:#f1f5f9;color:#64748b;border:1px solid #e5e7eb}
.fr-act-eapa{display:inline-block;background:#fef3c7;border:1px solid #fbbf24;
              color:#92400e;font-size:11px;font-weight:600;padding:4px 10px;
              border-radius:7px;margin-top:6px}
.fr-act-eapa strong{text-transform:uppercase;letter-spacing:.4px;font-weight:700}
.fr-act-pathos{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.fr-act-patho-tag{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;
                   font-size:10px;font-weight:600;border-radius:4px;padding:1px 7px}

/* Zone de leviers spécifiques à chaque activité (apparait quand un frein est coché) */
.fr-act-leviers{margin-top:10px;padding-top:10px;border-top:1px dashed #cbd5e1;
                animation:wizFadeIn .3s ease-out}
.fr-act-leviers-title{font-size:10px;font-weight:800;color:#475569;
                       text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;
                       display:flex;align-items:center;gap:6px}
.fr-act-leviers-title::before{content:"⚙";font-size:12px;color:#10b981}
.fr-act-leviers-group{margin-bottom:8px}
.fr-act-leviers-group:last-child{margin-bottom:0}
.fr-act-leviers-frein{font-size:10px;color:#64748b;font-weight:600;margin-bottom:4px;
                       font-style:italic}
.fr-act-leviers-frein strong{color:#1e293b;font-style:normal}
.fr-act-levier{display:inline-flex;align-items:center;gap:5px;background:#fff;
                border:1.5px solid #e5e7eb;color:#475569;font-size:11px;font-weight:600;
                border-radius:14px;padding:4px 11px;margin:2px;cursor:pointer;
                transition:.15s;font-family:inherit;user-select:none}
.fr-act-levier:hover{border-color:#10b981;color:#065f46;background:#f0fdf4}
.fr-act-levier.selected{background:#dcfce7;border-color:#10b981;color:#065f46}
.fr-act-levier.selected::before{content:"✓";font-weight:800;color:#10b981}

/* Colonne droite : freins */
.fr-freins-col{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
                box-shadow:0 1px 3px rgba(15,23,42,.04);overflow:hidden;
                position:sticky;top:16px;max-height:calc(100vh - 40px);
                display:flex;flex-direction:column}
.fr-freins-head{padding:14px 16px;border-bottom:1px solid #e5e7eb;
                 display:flex;align-items:center;gap:8px;background:#fafbfc}
.fr-freins-title{font-size:13px;font-weight:800;flex:1;color:#1e293b;
                  text-transform:uppercase;letter-spacing:.4px}
.fr-freins-count{font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8;
                  border:1px solid #bfdbfe;border-radius:20px;padding:2px 9px}
.fr-freins-reset{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;
                  font-family:inherit;padding:4px 8px;border-radius:6px}
.fr-freins-reset:hover{background:#f1f5f9;color:#1e293b}
.fr-freins-body{padding:8px 12px 14px;overflow-y:auto;flex:1}

/* Accordéon des groupes de freins (Physique, Psycho, etc.) */
.fr-freins-group{margin-bottom:6px;border:1px solid #e5e7eb;border-radius:10px;
                  background:#fff;overflow:hidden;transition:.15s}
.fr-freins-group[open]{border-color:#bfdbfe;background:#fafbff}
.fr-freins-group-head{display:flex;align-items:center;gap:8px;padding:10px 12px;
                       cursor:pointer;user-select:none;list-style:none;
                       font-size:12px;font-weight:700;color:#1e293b;
                       transition:background .12s}
.fr-freins-group-head::-webkit-details-marker{display:none}
.fr-freins-group-head:hover{background:#f1f5f9}
.fr-freins-group[open] .fr-freins-group-head{background:#eff6ff;color:#1d4ed8}
.fr-freins-group-name{flex:1;text-transform:uppercase;letter-spacing:.4px}
.fr-freins-group-count{font-size:10px;font-weight:700;background:#e5e7eb;color:#475569;
                        border-radius:10px;padding:1px 8px}
.fr-freins-group[open] .fr-freins-group-count{background:#dbeafe;color:#1d4ed8}
.fr-freins-group-checked{font-size:10px;font-weight:800;background:#10b981;color:#fff;
                          border-radius:10px;padding:1px 8px}
.fr-freins-group-arrow{font-size:10px;color:#94a3b8;transition:transform .2s;display:inline-block}
.fr-freins-group[open] .fr-freins-group-arrow{transform:rotate(90deg);color:#1d4ed8}
.fr-freins-group-body{padding:4px 8px 8px;border-top:1px dashed #cbd5e1}
.fr-frein-row{display:flex;align-items:center;gap:8px;padding:6px 4px;
               border-radius:6px;cursor:pointer;transition:background .1s;
               font-size:13px;color:#334155}
.fr-frein-row:hover{background:#f8fafc}
.fr-frein-row input{width:16px;height:16px;cursor:pointer;flex-shrink:0;accent-color:#2563eb}
.fr-frein-row.checked{background:#eff6ff;color:#1d4ed8;font-weight:600}
.fr-frein-row label{flex:1;cursor:pointer;user-select:none}
.fr-frein-row .fr-frein-lcount{font-size:10px;background:#e5e7eb;color:#64748b;
                                 border-radius:10px;padding:1px 7px;font-weight:600}
.fr-frein-row.checked .fr-frein-lcount{background:#dbeafe;color:#1d4ed8}

/* Panneau Leviers */
.fr-leviers{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
             box-shadow:0 1px 3px rgba(15,23,42,.04);margin-top:18px;overflow:hidden}
.fr-leviers-head{padding:14px 18px;border-bottom:1px solid #e5e7eb;
                  background:linear-gradient(90deg,#ecfdf5,#fff);
                  border-left:4px solid #10b981;
                  display:flex;align-items:center;gap:10px}
.fr-leviers-title{font-size:13px;font-weight:800;color:#065f46;
                   text-transform:uppercase;letter-spacing:.4px;flex:1}
.fr-leviers-count{font-size:11px;font-weight:700;background:#d1fae5;color:#065f46;
                   border:1px solid #6ee7b7;border-radius:20px;padding:2px 9px}
.fr-leviers-body{padding:14px 18px}
.fr-levier-chip{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;
                 border:1px solid #cbd5e1;color:#475569;font-size:12px;font-weight:600;
                 border-radius:20px;padding:4px 12px;margin:3px;cursor:pointer;
                 transition:.15s;font-family:inherit}
.fr-levier-chip:hover{background:#e2e8f0;color:#1e293b}
.fr-levier-chip.selected{background:#dcfce7;border-color:#10b981;color:#065f46}
.fr-levier-chip.selected::before{content:"✓ "}

/* Bouton enregistrer */
.fr-save{background:linear-gradient(135deg,#dbeafe,#eff6ff);border:1.5px solid #93c5fd;
          border-radius:18px;padding:22px 26px;margin-top:18px;
          display:flex;justify-content:space-between;align-items:center;gap:20px;
          flex-wrap:wrap;box-shadow:0 6px 16px rgba(37,99,235,.08)}
.fr-save-info{flex:1;min-width:240px}
.fr-save-info h3{margin:0 0 4px;color:#1d4ed8;font-size:18px;font-weight:800}
.fr-save-info p{margin:0;color:#475569;font-size:13px;line-height:1.5}
.fr-save-btns{display:flex;gap:10px;flex-wrap:wrap}
.fr-save-skip{background:#fff;color:#1d4ed8;border:1.5px solid #93c5fd;
               border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;
               cursor:pointer;font-family:inherit;transition:.15s}
.fr-save-skip:hover{background:#eff6ff}
.fr-save-go{background:#2563eb;color:#fff;border:none;border-radius:10px;
             padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;
             font-family:inherit;box-shadow:0 4px 12px rgba(37,99,235,.3);transition:.15s}
.fr-save-go:hover{background:#1d4ed8}
.fr-save-go:disabled{opacity:.6;cursor:not-allowed}
.fr-save-feedback{margin-top:16px}

/* Layout étape 4 : activités | freins (la synthèse reste à gauche du tout) */
.fr-layout{display:grid;grid-template-columns:1fr 290px;gap:16px;align-items:start}
@media(max-width:900px){.fr-layout{grid-template-columns:1fr}.fr-freins-col{position:static;max-height:none}}

/* Loader pour les boutons en attente AJAX */
.btn-loading{position:relative;color:transparent !important;pointer-events:none}
.btn-loading::after{content:"";position:absolute;top:50%;left:50%;
                     width:18px;height:18px;border:2.5px solid #fff;
                     border-top-color:transparent;border-radius:50%;
                     transform:translate(-50%,-50%);animation:spin .7s linear infinite}
@keyframes spin{to{transform:translate(-50%,-50%) rotate(360deg)}}

/* ═══════════════════════════════════════════════════════════════════════
   MODE GESTION LIBRE — design moderne
   ═══════════════════════════════════════════════════════════════════════ */

/* Bannière en haut avec bouton "Nouveau patient" intégré */
.gl-banner{background:linear-gradient(135deg,#1d4ed8,#4b8df8);
           border-radius:18px;padding:24px 28px;margin-bottom:20px;
           display:flex;align-items:center;gap:20px;flex-wrap:wrap;
           box-shadow:0 10px 24px rgba(37,99,235,.18);color:#fff}
.gl-banner-info{flex:1;min-width:240px}
.gl-banner-crumbs{font-size:11px;font-weight:600;letter-spacing:.6px;
                   text-transform:uppercase;opacity:.85;margin-bottom:8px}
.gl-banner-crumbs .my-badge{display:inline-flex;align-items:center;gap:6px;
                              background:rgba(255,255,255,.18);backdrop-filter:blur(4px);
                              border:1px solid rgba(255,255,255,.25);
                              padding:5px 12px;border-radius:50px;font-size:11px;font-weight:700;
                              letter-spacing:.4px;text-transform:uppercase;opacity:1}
.gl-banner-title{font-size:24px;font-weight:800;letter-spacing:-.3px;margin:0 0 4px}
.gl-banner-sub{font-size:13px;opacity:.88;line-height:1.5}
.gl-new-btn{background:#fff;color:#1d4ed8;border:none;border-radius:12px;
            padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;
            font-family:inherit;display:inline-flex;align-items:center;gap:8px;
            box-shadow:0 6px 16px rgba(0,0,0,.15);transition:.2s cubic-bezier(.4,0,.2,1)}
.gl-new-btn:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(0,0,0,.25)}
.gl-new-btn-plus{font-size:18px;font-weight:800;line-height:1}

/* Stats : Total / Hommes / Femmes */
.gl-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.gl-stat{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
         padding:18px 22px;box-shadow:0 1px 3px rgba(15,23,42,.04);
         display:flex;flex-direction:column;align-items:flex-start;gap:6px;
         border-left:4px solid;transition:.15s}
.gl-stat:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08)}
.gl-stat-num{font-size:32px;font-weight:800;line-height:1;letter-spacing:-.5px}
.gl-stat-lbl{font-size:11px;font-weight:700;color:#64748b;
              text-transform:uppercase;letter-spacing:.5px}
.gl-stat-total{border-left-color:#1d4ed8}
.gl-stat-total .gl-stat-num{color:#1d4ed8}
.gl-stat-m{border-left-color:#0891b2}
.gl-stat-m .gl-stat-num{color:#0891b2}
.gl-stat-f{border-left-color:#db2777}
.gl-stat-f .gl-stat-num{color:#db2777}

/* Card recherche */
.gl-search-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
                padding:22px 24px;box-shadow:0 1px 3px rgba(15,23,42,.04)}

/* Empty state */
.gl-empty-state{text-align:center;padding:48px 24px;color:#64748b}
.gl-empty-icon{font-size:48px;line-height:1;margin-bottom:12px;opacity:.6}
.gl-empty-title{font-size:16px;font-weight:700;color:#1e293b;margin-bottom:6px}
.gl-empty-sub{font-size:13px;color:#64748b;line-height:1.5}

/* ═══════ MODALE Nouveau patient ═══════ */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);
               display:none;align-items:flex-start;justify-content:center;
               z-index:1000;padding:60px 20px 20px;overflow-y:auto;
               animation:modalFadeIn .2s ease-out;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
@keyframes modalFadeIn{from{opacity:0}to{opacity:1}}
.modal-card{background:#fff;border-radius:18px;width:100%;max-width:560px;
            box-shadow:0 20px 60px rgba(0,0,0,.25);
            animation:modalSlideIn .25s cubic-bezier(.4,0,.2,1)}
@keyframes modalSlideIn{from{transform:translateY(-20px);opacity:0}
                        to{transform:translateY(0);opacity:1}}
.modal-head{padding:20px 24px;border-bottom:1px solid #e5e7eb;
            display:flex;align-items:center;justify-content:space-between}
.modal-head h2{margin:0;font-size:18px;font-weight:800;color:#1e293b}
.modal-close{background:none;border:none;font-size:18px;color:#94a3b8;
             cursor:pointer;padding:4px 10px;border-radius:8px;font-family:inherit;
             transition:.15s}
.modal-close:hover{background:#f1f5f9;color:#1e293b}
.modal-card .create-form{padding:20px 24px 24px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;
                padding-top:18px;border-top:1px solid #f1f5f9}
.btn-cancel{background:#fff;color:#64748b;border:1px solid #e5e7eb;
            border-radius:10px;padding:10px 20px;font-size:13px;font-weight:600;
            cursor:pointer;font-family:inherit;transition:.15s}
.btn-cancel:hover{background:#f8fafc;color:#1e293b}

@media(max-width:700px){
    .gl-stats{grid-template-columns:1fr;gap:8px}
    .gl-stat{padding:14px 16px}
    .gl-stat-num{font-size:24px}
    .gl-new-btn{width:100%;justify-content:center}
}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <nav class="topbar-nav">
            <a href="index.php"<?= $hasContext ? ' class="active"' : '' ?>>Nouvelle prescription</a>
            <a href="patient.php"<?= !$hasContext ? ' class="active"' : '' ?>>Patients</a>
            <a href="prescriptions.php">Historique</a>
        </nav>
    </div>
</div>

<div class="app">

<?php if ($hasContext): /* ─────────── MODE PARCOURS (étape 3/5) ─────────── */ ?>

    <!-- Bouton flottant retour vers rapport.php (avec les pathologies en URL pour éviter la redirection) -->
    <?php
    // rapport.php attend ?pathologies[]=URI1&pathologies[]=URI2 ; sinon il rebondit sur index.php.
    // On reconstruit le lien à partir de la session.
    $backUrl = 'rapport.php';
    if (!empty($parcoursPathos)) {
        $params = [];
        foreach ($parcoursPathos as $uri) {
            $params[] = 'pathologies[]=' . urlencode($uri);
        }
        $backUrl .= '?' . implode('&', $params);
    }
    ?>
    <a href="<?= h($backUrl) ?>" class="back-fab" title="Revenir au rapport (étape 2)">
        <span class="back-fab-arrow">←</span>
        <span class="back-fab-lbl">Retour</span>
    </a>

    <!-- Stepper 5 étapes (style index.php) -->
    <div class="stepper-bar">
        <div class="stepper">
            <?php
            $steps = [
                ['Pathologies',     'index.php',     'done'],
                ['Recommandations', 'rapport.php',   'done'],
                ['Patient',         '#',             'current'],
                ['Freins/Leviers',  '#',             'todo'],
                ['Résumé IA',       '#',             'todo'],
            ];
            $i = 1;
            foreach ($steps as $s):
                [$lbl, $url, $st] = $s;
            ?>
                <?php if ($st === 'done'): ?>
                    <a class="step step-done" href="<?= h($url) ?>">
                        <span class="step-num">✓</span><span class="step-lbl"><?= h($lbl) ?></span>
                    </a>
                <?php else: ?>
                    <div class="step step-<?= h($st) ?>">
                        <span class="step-num"><?= $i ?></span><span class="step-lbl"><?= h($lbl) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($i < 5): ?><span class="step-sep">→</span><?php endif; ?>
                <?php $i++; endforeach; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="rx-layout">

        <!-- ═════════════════ COLONNE GAUCHE : SYNTHÈSE ═════════════════ -->
        <aside class="rx-synth">
            <div class="rx-synth-head">
                <div class="rx-synth-title">📋 Synthèse de prescription</div>
                <div class="rx-synth-sub">À garder sous les yeux pendant l'attribution.</div>
            </div>

            <!-- Pathologies sélectionnées (placées en haut : données d'entrée) -->
            <?php if (!empty($parcoursPathosLabels)): ?>
                <div class="rx-block">
                    <div class="rx-block-title">🩺 Pathologies</div>
                    <div class="rx-tags">
                        <?php foreach ($parcoursPathosLabels as $lbl): ?>
                            <span class="rx-tag rx-tag-blue"><?= h($lbl) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3 chiffres clés OU bandeau d'alerte si aucune activité -->
            <?php if ($nbActivites === 0): ?>
                <div class="rx-noreco">
                    <div class="rx-noreco-icon">⛔</div>
                    <div class="rx-noreco-title">Aucune activité recommandable</div>
                    <div class="rx-noreco-msg">
                        <?php if (!empty($parcoursCI)): ?>
                            Toutes les activités sont contre-indiquées en raison de :
                            <ul class="rx-noreco-reasons">
                                <?php foreach ($parcoursCI as $ci): ?>
                                    <li>
                                        <?php if (!empty($ci['reasons'])): ?>
                                            <strong><?= h(implode(', ', $ci['reasons'])) ?></strong>
                                            contre-indique <em><?= h($ci['activity']) ?></em>
                                        <?php else: ?>
                                            <em><?= h($ci['activity']) ?></em>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            Aucune activité ne correspond aux pathologies sélectionnées.
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="rx-stats">
                    <div class="rx-stat">
                        <div class="rx-stat-num"><?= $nbPathos ?></div>
                        <div class="rx-stat-lbl">pathologie<?= $nbPathos > 1 ? 's' : '' ?></div>
                    </div>
                    <div class="rx-stat rx-stat-ok">
                        <div class="rx-stat-num"><?= $nbActivites ?></div>
                        <div class="rx-stat-lbl">activités reco.</div>
                    </div>
                    <div class="rx-stat<?= $nbCI > 0 ? ' rx-stat-warn' : '' ?>">
                        <div class="rx-stat-num"><?= $nbCI ?></div>
                        <div class="rx-stat-lbl">contre-ind.</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Activités recommandées -->
            <?php if (!empty($parcoursActivitesLabels)): ?>
                <div class="rx-block">
                    <div class="rx-block-title">✅ Activités recommandées</div>
                    <ul class="rx-list">
                        <?php foreach ($parcoursActivitesLabels as $idx => $lbl):
                            $uri = $parcoursActivites[$idx] ?? '';
                            $pathosReco = $parcoursActivitesPathos[$uri] ?? [];
                        ?>
                            <li>
                                <strong><?= h($lbl) ?></strong>
                                <?php if (!empty($pathosReco)): ?>
                                    <span class="rx-reco-pathos"><?= h(implode(', ', $pathosReco)) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Contre-indications -->
            <!-- (masqué quand le bandeau d'alerte 'Aucune activité' est déjà affiché) -->
            <?php if (!empty($parcoursCI) && $nbActivites > 0): ?>
                <div class="rx-block rx-block-ci">
                    <div class="rx-block-title">⛔ Contre-indications</div>
                    <ul class="rx-list rx-list-ci">
                        <?php foreach ($parcoursCI as $ci): ?>
                            <li>
                                <strong><?= h($ci['activity'] ?? '?') ?></strong>
                                <?php if (!empty($ci['reasons'])): ?>
                                    <span class="rx-ci-reason"><?= h(implode(', ', $ci['reasons'])) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <a href="<?= h($backUrl) ?>" class="rx-back">← Revenir à la synthèse complète</a>
        </aside>

        <!-- ═════════════════ COLONNE DROITE : WIZARD ═════════════════ -->
        <div class="rx-wizard-wrapper">

        <!-- ─── ÉCRAN 1 (étape 3) : Choix patient ─── -->
        <section class="rx-choice wizard-screen active" id="screen-patient">
            <div class="rx-choice-head">
                <h2>👤 Prescrire à un patient</h2>
                <p>Sélectionnez un patient existant ou créez-en un nouveau.</p>
            </div>

            <!-- Sélecteur Ancien / Nouveau -->
            <div class="choice-cards">
                <button type="button" class="choice-card" data-target="pane-existing" id="card-existing">
                    <div class="choice-icon">🔍</div>
                    <div class="choice-lbl">Patient existant</div>
                    <div class="choice-sub"><?= count($patients) ?> dossier<?= count($patients)>1?'s':'' ?> enregistré<?= count($patients)>1?'s':'' ?></div>
                </button>
                <button type="button" class="choice-card" data-target="pane-new" id="card-new">
                    <div class="choice-icon">➕</div>
                    <div class="choice-lbl">Nouveau patient</div>
                    <div class="choice-sub">Créer un dossier</div>
                </button>
            </div>

            <!-- Pane 1 : recherche dans les patients existants (3 caractères mini) -->
            <div class="pane" id="pane-existing">
                <div class="search-row">
                    <input type="text" id="patient-search" autocomplete="off"
                           placeholder="🔍 Rechercher par nom, prénom ou dossier (min. 3 caractères)...">
                    <button type="button" id="patient-search-clear" class="clear-btn" style="display:none">✕</button>
                </div>

                <!-- Message d'aide quand < 3 caractères -->
                <div class="search-hint" id="search-hint">
                    💡 Tapez au moins <strong>3 caractères</strong> pour rechercher parmi
                    <strong><?= count($patients) ?></strong> dossier<?= count($patients) > 1 ? 's' : '' ?> enregistré<?= count($patients) > 1 ? 's' : '' ?>.
                </div>

                <!-- Message si aucun résultat -->
                <div class="empty" id="search-empty" style="display:none">
                    Aucun patient ne correspond à votre recherche.
                </div>

                <!-- Liste filtrée dynamiquement par JS -->
                <div class="patient-list" id="patient-list" style="display:none">
                    <?php foreach ($patients as $p):
                        $displayName = trim($p['prenom'] . ' ' . $p['nom']) ?: '(' . $p['fragment'] . ')';
                        // On stocke les champs recherchables dans data-search pour le filtrage JS
                        $searchHaystack = strtolower(trim($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['dossier']));
                    ?>
                        <div class="patient-row" data-search="<?= h($searchHaystack) ?>">
                            <div class="info">
                                <div class="name"><?= h($displayName) ?></div>
                                <div class="meta">
                                    <?php if ($p['dossier'] !== ''): ?><span class="dossier"><?= h($p['dossier']) ?></span><?php endif; ?>
                                    <?php if ($p['age'] !== ''): ?><span><?= h($p['age']) ?> ans</span><?php endif; ?>
                                    <?php if ($p['genre'] !== ''): ?><span><?= h($p['genre']) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary"
                                    onclick="selectExistingPatient('<?= h($p['fragment']) ?>', this)">
                                Prescrire →
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($patients)): ?>
                    <div class="empty">Aucun patient enregistré. Utilisez « Nouveau patient » à droite.</div>
                <?php endif; ?>
            </div>

            <!-- Pane 2 : formulaire de création -->
            <div class="pane" id="pane-new">
                <form class="create-form" id="form-create-patient" onsubmit="return createNewPatient(event)">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="from" value="rapport">

                    <div class="field-row">
                        <div class="field">
                            <label>Prénom *</label>
                            <input type="text" name="prenom" required value="<?= h($_POST['prenom'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Nom *</label>
                            <input type="text" name="nom" required value="<?= h($_POST['nom'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Sexe *</label>
                            <select name="sexe" required>
                                <option value="">—</option>
                                <option value="M" <?= ($_POST['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
                                <option value="F" <?= ($_POST['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Âge *</label>
                            <input type="number" name="age" min="0" max="120" required value="<?= h($_POST['age'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>N° de dossier médical *</label>
                        <input type="text" name="numero_dossier" required placeholder="ex : D-2026-0451"
                               value="<?= h($_POST['numero_dossier'] ?? '') ?>">
                        <div class="hint">La tranche d'âge sera déduite automatiquement.</div>
                    </div>

                    <button type="submit" class="btn-submit" id="btn-create-patient">Créer et prescrire →</button>
                </form>
            </div>
        </section>
        <!-- ─── /ÉCRAN 1 ─── -->

        <!-- ─── ÉCRAN 2 (étape 4) : Freins/Leviers ─── -->
        <section class="wizard-screen" id="screen-freins">

            <!-- Confirmation patient + bouton changer -->
            <div class="wiz-patient-confirm" id="wiz-patient-confirm">
                <div class="wiz-patient-confirm-icon">👤</div>
                <div>
                    <div class="wiz-patient-confirm-name" id="wpc-name">—</div>
                    <div class="wiz-patient-confirm-meta" id="wpc-meta">—</div>
                </div>
                <button type="button" class="wiz-patient-confirm-change"
                        onclick="wizGoBack()">← Changer de patient</button>
            </div>

            <div class="fr-layout">

                <!-- Colonne centre : activités adaptées -->
                <div>
                    <div class="fr-section">
                        <div class="fr-section-header">
                            <span class="fr-section-title">Activités finales adaptées</span>
                            <span class="fr-section-count" id="actCount"><?= count($finalRecos) ?></span>
                        </div>
                        <div class="fr-section-body">
                            <?php if (empty($finalRecos)): ?>
                                <div class="empty">Aucune activité disponible après filtrage des contre-indications.</div>
                            <?php else: ?>
                                <p class="fr-helper">Cochez les freins du patient à droite — les activités s'adaptent en temps réel.</p>
                                <div id="activitesContainer">
                                    <?php foreach ($finalRecos as $item): ?>
                                        <div class="fr-act fr-act-neutral" id="act-<?= h($item['activity']) ?>" data-act="<?= h($item['activity']) ?>">
                                            <div class="fr-act-top">
                                                <div class="fr-act-name"><?= h(F_prettyLabel($item['activity'])) ?></div>
                                                <span class="fr-act-status" id="status-<?= h($item['activity']) ?>">—</span>
                                            </div>
                                            <?php if (!empty($item['adaptations'])): ?>
                                                <div class="fr-act-eapa">
                                                    <strong>Suggestion EAPA</strong> : <?= h(implode(' — ', array_map('F_prettyLabel', $item['adaptations']))) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($item['pathoLabels'])): ?>
                                                <div class="fr-act-pathos">
                                                    <?php foreach ($item['pathoLabels'] as $pl): ?>
                                                        <span class="fr-act-patho-tag"><?= h($pl) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <!-- Zone des leviers proposés (par activité, remplie en JS quand un frein est coché) -->
                                            <div class="fr-act-leviers" id="act-leviers-<?= h($item['activity']) ?>" style="display:none"></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : freins/leviers -->
                <aside class="fr-freins-col">
                    <div class="fr-freins-head">
                        <span class="fr-freins-title">Freins du patient</span>
                        <span class="fr-freins-count" id="freinsCountBadge">0</span>
                        <button type="button" class="fr-freins-reset" onclick="resetFreins()" title="Réinitialiser">↻</button>
                    </div>
                    <div class="fr-freins-body">
                        <?php if (empty($freinsGrouped)): ?>
                            <div class="empty">Aucun frein chargé.</div>
                        <?php else: ?>
                            <?php $isFirst = true; foreach ($freinsGrouped as $typeName => $freins): ?>
                                <details class="fr-freins-group"<?= $isFirst ? ' open' : '' ?>>
                                    <summary class="fr-freins-group-head">
                                        <span class="fr-freins-group-name"><?= h($typeName) ?></span>
                                        <span class="fr-freins-group-count"><?= count($freins) ?></span>
                                        <span class="fr-freins-group-checked" id="grp-checked-<?= h($typeName) ?>" style="display:none"></span>
                                        <span class="fr-freins-group-arrow">▸</span>
                                    </summary>
                                    <div class="fr-freins-group-body">
                                        <?php foreach ($freins as $f): ?>
                                            <div class="fr-frein-row" id="row-<?= h($f['id']) ?>" data-type="<?= h($typeName) ?>">
                                                <input type="checkbox" class="frein-cb" value="<?= h($f['id']) ?>"
                                                       id="cb-<?= h($f['id']) ?>" data-type="<?= h($typeName) ?>"
                                                       onchange="onFreinChange(this)">
                                                <label for="cb-<?= h($f['id']) ?>"><?= h($f['label']) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php $isFirst = false; endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>

            </div><!-- /fr-layout -->

            <!-- Panneau leviers d'action — DÉSACTIVÉ (les leviers sont maintenant par activité) -->
            <div class="fr-leviers" id="leviersPanel" style="display:none">
                <div class="fr-leviers-head">
                    <span style="font-size:16px">✓</span>
                    <span class="fr-leviers-title">Leviers d'action</span>
                    <span class="fr-leviers-count" id="leviersCount">0</span>
                </div>
                <div class="fr-leviers-body" id="leviersList"></div>
            </div>

            <!-- Bouton enregistrer -->
            <div class="fr-save">
                <div class="fr-save-info">
                    <h3>💾 Enregistrer la prescription</h3>
                    <p>Cliquez pour enregistrer définitivement la prescription dans le dossier du patient.</p>
                </div>
                <div class="fr-save-btns">
                    <button type="button" class="fr-save-skip" onclick="enregistrerPrescription(true)">
                        ⏭ Sans freins/leviers
                    </button>
                    <button type="button" class="fr-save-go" id="btn-enregistrer-final"
                            onclick="enregistrerPrescription(false)">
                        💾 Enregistrer la prescription
                    </button>
                </div>
                <div id="save-feedback" class="fr-save-feedback" style="display:none;width:100%"></div>
            </div>

        </section>
        <!-- ─── /ÉCRAN 2 ─── -->

        </div><!-- /rx-wizard-wrapper -->

    </div>

<?php else: /* ─────────── MODE GESTION LIBRE — refonte pro ─────────── */ ?>

    <!-- Bannière avec bouton "Nouveau patient" -->
    <section class="gl-banner">
        <div class="gl-banner-info">
            <div class="gl-banner-crumbs">
                <span class="my-badge">👤 Mes patients</span>
            </div>
            <h1 class="gl-banner-title">👥 Mes patients</h1>
            <div class="gl-banner-sub">
                Patients que vous avez créés ou prescrits, en tant que
                <strong style="color:#fff"><?= h($currentPraticienName) ?></strong>.
            </div>
        </div>
        <button type="button" class="gl-new-btn" onclick="openNewPatientModal()">
            <span class="gl-new-btn-plus">＋</span>
            <span>Nouveau patient</span>
        </button>
    </section>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Stats : Total · Hommes · Femmes -->
    <div class="gl-stats">
        <div class="gl-stat gl-stat-total">
            <div class="gl-stat-num"><?= $stats['total'] ?></div>
            <div class="gl-stat-lbl">total des dossiers</div>
        </div>
        <div class="gl-stat gl-stat-m">
            <div class="gl-stat-num"><?= $stats['hommes'] ?></div>
            <div class="gl-stat-lbl">masculin</div>
        </div>
        <div class="gl-stat gl-stat-f">
            <div class="gl-stat-num"><?= $stats['femmes'] ?></div>
            <div class="gl-stat-lbl">féminin</div>
        </div>
    </div>

    <!-- Recherche en mode Gestion Libre -->
    <div class="gl-search-card">
        <div class="search-row">
            <input type="text" id="gl-search" autocomplete="off"
                   placeholder="🔍 Rechercher par nom, prénom ou dossier (min. 3 caractères)...">
            <button type="button" id="gl-search-clear" class="clear-btn" style="display:none">✕</button>
        </div>

        <div class="search-hint" id="gl-search-hint">
            💡 Tapez au moins <strong>3 caractères</strong> pour rechercher parmi
            <strong><?= count($patients) ?></strong> dossier<?= count($patients) > 1 ? 's' : '' ?> enregistré<?= count($patients) > 1 ? 's' : '' ?>.
        </div>

        <div class="empty" id="gl-search-empty" style="display:none">
            Aucun patient ne correspond à votre recherche.
        </div>

        <div class="patient-list" id="gl-patient-list" style="display:none">
            <?php foreach ($patients as $p):
                $displayName = trim($p['prenom'] . ' ' . $p['nom']) ?: '(' . $p['fragment'] . ')';
                $searchHaystack = strtolower(trim($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['dossier']));
            ?>
                <div class="patient-row" data-search="<?= h($searchHaystack) ?>">
                    <div class="info">
                        <div class="name"><?= h($displayName) ?></div>
                        <div class="meta">
                            <?php if ($p['dossier'] !== ''): ?><span class="dossier"><?= h($p['dossier']) ?></span><?php endif; ?>
                            <?php if ($p['age'] !== ''): ?><span><?= h($p['age']) ?> ans</span><?php endif; ?>
                            <?php if ($p['genre'] !== ''): ?><span><?= h($p['genre']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <a href="patient_detail.php?id=<?= urlencode($p['fragment']) ?>" class="btn btn-primary">Ouvrir →</a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($patients)): ?>
            <div class="gl-empty-state">
                <div class="gl-empty-icon">📋</div>
                <div class="gl-empty-title">Aucun patient pour l'instant</div>
                <div class="gl-empty-sub">
                    Vos patients apparaîtront ici dès que vous en créerez ou prescrirez.<br>
                    Cliquez sur « ＋ Nouveau patient » en haut à droite pour commencer.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ━━━ MODALE : Création nouveau patient ━━━ -->
    <div class="modal-overlay" id="modal-new-patient" onclick="closeNewPatientModal(event)">
        <div class="modal-card" onclick="event.stopPropagation()">
            <div class="modal-head">
                <h2>＋ Nouveau patient</h2>
                <button type="button" class="modal-close" onclick="closeNewPatientModal()">✕</button>
            </div>
            <form method="post" class="create-form" id="form-new-patient-gl">
                <input type="hidden" name="action" value="create">
                <div class="field-row">
                    <div class="field"><label>Prénom *</label><input type="text" name="prenom" required value="<?= h($_POST['prenom'] ?? '') ?>"></div>
                    <div class="field"><label>Nom *</label><input type="text" name="nom" required value="<?= h($_POST['nom'] ?? '') ?>"></div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>Sexe *</label>
                        <select name="sexe" required>
                            <option value="">—</option>
                            <option value="M" <?= ($_POST['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
                            <option value="F" <?= ($_POST['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
                        </select>
                    </div>
                    <div class="field"><label>Âge *</label><input type="number" name="age" min="0" max="120" required value="<?= h($_POST['age'] ?? '') ?>"></div>
                </div>
                <div class="field">
                    <label>N° de dossier médical *</label>
                    <input type="text" name="numero_dossier" required placeholder="ex : D-2026-0451" value="<?= h($_POST['numero_dossier'] ?? '') ?>">
                    <div class="hint">La tranche d'âge sera déduite automatiquement.</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeNewPatientModal()">Annuler</button>
                    <button type="submit" class="btn-submit">Créer le patient →</button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

</div>

<script>
function formatName(s){if(!s)return'';return s.toLowerCase().replace(/(^|[\s\-'])([a-zà-ÿ])/g,(m,sep,l)=>sep+l.toUpperCase());}
['prenom','nom'].forEach(n=>{const f=document.querySelector(`input[name="${n}"]`);if(f)f.addEventListener('blur',function(){this.value=formatName(this.value);});});

// ─── Sélecteur Ancien/Nouveau (en mode parcours uniquement) ───
const cards = document.querySelectorAll('.choice-card');
const panes = document.querySelectorAll('.pane');
cards.forEach(c => c.addEventListener('click', () => {
    const target = c.dataset.target;
    cards.forEach(x => x.classList.toggle('active', x === c));
    panes.forEach(p => p.classList.toggle('open', p.id === target));
}));
// Si POST a échoué côté nouveau patient, on ouvre l'onglet création
// pour que l'utilisateur voie le message d'erreur et ses champs préremplis.
window.addEventListener('DOMContentLoaded', () => {
    const isPostError = <?= ($flash && ($flash['type'] ?? '') === 'error') ? 'true' : 'false' ?>;
    if (isPostError) {
        document.getElementById('card-new')?.click();
    }
});

// ─── Recherche temps réel des patients (3 caractères minimum) ───
const searchInput = document.getElementById('patient-search');
const searchClear = document.getElementById('patient-search-clear');
const searchHint  = document.getElementById('search-hint');
const searchEmpty = document.getElementById('search-empty');
const patientList = document.getElementById('patient-list');
const patientRows = document.querySelectorAll('#patient-list .patient-row');

if (searchInput) {
    function runSearch() {
        const term = searchInput.value.trim().toLowerCase();

        // Affiche le bouton "✕" dès qu'il y a quelque chose
        searchClear.style.display = term.length > 0 ? 'flex' : 'none';

        // Moins de 3 caractères : on affiche le message d'aide, on masque le reste
        if (term.length < 3) {
            if (searchHint)  searchHint.style.display  = 'block';
            if (searchEmpty) searchEmpty.style.display = 'none';
            if (patientList) patientList.style.display = 'none';
            return;
        }

        // 3+ caractères : on filtre la liste en mémoire
        let nbMatch = 0;
        patientRows.forEach(row => {
            const hay = row.dataset.search || '';
            const match = hay.includes(term);
            row.style.display = match ? '' : 'none';
            if (match) nbMatch++;
        });

        if (searchHint) searchHint.style.display = 'none';
        if (nbMatch === 0) {
            if (searchEmpty) searchEmpty.style.display = 'block';
            if (patientList) patientList.style.display = 'none';
        } else {
            if (searchEmpty) searchEmpty.style.display = 'none';
            if (patientList) patientList.style.display = 'flex';
        }
    }

    searchInput.addEventListener('input', runSearch);
    searchClear?.addEventListener('click', () => {
        searchInput.value = '';
        runSearch();
        searchInput.focus();
    });
}

// ═══════════════════════════════════════════════════════════════════════
//  MODE GESTION LIBRE — modale + recherche temps réel
// ═══════════════════════════════════════════════════════════════════════

// Ouverture / fermeture de la modale Nouveau patient
function openNewPatientModal() {
    const m = document.getElementById('modal-new-patient');
    if (m) {
        m.classList.add('open');
        // Focus auto sur le premier champ
        setTimeout(() => m.querySelector('input[name="prenom"]')?.focus(), 100);
    }
}
function closeNewPatientModal(ev) {
    // Si appelé par le click sur l'overlay, ne ferme que si on a cliqué hors de la carte
    if (ev && ev.target && !ev.target.classList.contains('modal-overlay')) return;
    document.getElementById('modal-new-patient')?.classList.remove('open');
}

// Recherche temps réel pour la gestion libre (mêmes principes que mode parcours)
const glSearch     = document.getElementById('gl-search');
const glSearchClear= document.getElementById('gl-search-clear');
const glSearchHint = document.getElementById('gl-search-hint');
const glSearchEmpty= document.getElementById('gl-search-empty');
const glPatientList= document.getElementById('gl-patient-list');
const glPatientRows= document.querySelectorAll('#gl-patient-list .patient-row');

if (glSearch) {
    function glRunSearch() {
        const term = glSearch.value.trim().toLowerCase();
        glSearchClear.style.display = term.length > 0 ? 'flex' : 'none';

        if (term.length < 3) {
            if (glSearchHint)  glSearchHint.style.display  = 'block';
            if (glSearchEmpty) glSearchEmpty.style.display = 'none';
            if (glPatientList) glPatientList.style.display = 'none';
            return;
        }

        let nbMatch = 0;
        glPatientRows.forEach(row => {
            const hay = row.dataset.search || '';
            const match = hay.includes(term);
            row.style.display = match ? '' : 'none';
            if (match) nbMatch++;
        });

        if (glSearchHint) glSearchHint.style.display = 'none';
        if (nbMatch === 0) {
            if (glSearchEmpty) glSearchEmpty.style.display = 'block';
            if (glPatientList) glPatientList.style.display = 'none';
        } else {
            if (glSearchEmpty) glSearchEmpty.style.display = 'none';
            if (glPatientList) glPatientList.style.display = 'flex';
        }
    }

    glSearch.addEventListener('input', glRunSearch);
    glSearchClear?.addEventListener('click', () => {
        glSearch.value = '';
        glRunSearch();
        glSearch.focus();
    });

    // Focus automatique au chargement (mais pas si modale ouverte)
    setTimeout(() => glSearch.focus(), 200);
}

// Ouvrir automatiquement la modale si POST a échoué (pour montrer l'erreur)
window.addEventListener('DOMContentLoaded', () => {
    const isPostErrorGL = <?= ($flash && ($flash['type'] ?? '') === 'error') ? 'true' : 'false' ?>;
    if (isPostErrorGL && document.getElementById('modal-new-patient')) {
        openNewPatientModal();
    }
});

// Échap pour fermer la modale
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeNewPatientModal();
});

// ═══════════════════════════════════════════════════════════════════════
//  WIZARD SPA : navigation entre étapes 3 ↔ 4
// ═══════════════════════════════════════════════════════════════════════
<?php if ($hasContext): ?>

// Données injectées (uniquement disponibles si on est en contexte parcours)
const DATA = <?= $jsData ?>;
const FREINS    = DATA.freins;       // [{id, label, typeLabel, leviers:[]}]
const ACTIVITES = DATA.activites;    // [{id, label, pathos:[], adaptations:[]}]

// Index : freinId → leviers[]
const freinLeviers = {};
FREINS.forEach(f => { freinLeviers[f.id] = f.leviers || []; });

// État
const checked = new Set();
// Sélection des leviers PAR ACTIVITÉ : Map<activityId, Set<levierName>>
const selectedLeviersPerAct = new Map();

// ── Navigation entre écrans ────────────────────────────────────────────
function wizShowScreen(id) {
    document.querySelectorAll('.wizard-screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id)?.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    updateStepperState(id);
}

function updateStepperState(currentScreenId) {
    const steps = document.querySelectorAll('.stepper .step');
    if (steps.length < 5) return;
    const patientStep = steps[2];
    const freinsStep  = steps[3];

    if (currentScreenId === 'screen-freins') {
        patientStep.classList.remove('step-current');
        patientStep.classList.add('step-done');
        patientStep.querySelector('.step-num').textContent = '✓';
        freinsStep.classList.remove('step-todo');
        freinsStep.classList.add('step-current');
    } else {
        patientStep.classList.remove('step-done');
        patientStep.classList.add('step-current');
        patientStep.querySelector('.step-num').textContent = '3';
        freinsStep.classList.remove('step-current');
        freinsStep.classList.add('step-todo');
    }
}

function wizGoBack() {
    const hasSelections = checked.size > 0 ||
        [...selectedLeviersPerAct.values()].some(s => s.size > 0);
    if (hasSelections) {
        if (!confirm("Changer de patient va perdre les freins et leviers cochés. Continuer ?")) return;
        resetFreins();
    }
    wizShowScreen('screen-patient');
}

function wizGoToFreins(patientInfo) {
    document.getElementById('wpc-name').textContent = patientInfo.fullname || '(patient)';
    const metaParts = [];
    if (patientInfo.age)     metaParts.push(patientInfo.age + ' ans');
    if (patientInfo.dossier) metaParts.push('Dossier ' + patientInfo.dossier);
    document.getElementById('wpc-meta').textContent = metaParts.join(' · ') || '—';
    wizShowScreen('screen-freins');
    update();
}

// ── AJAX : sélection d'un patient existant ────────────────────────────
async function selectExistingPatient(fragment, btn) {
    btn?.classList.add('btn-loading');
    try {
        const fd = new FormData();
        fd.append('action', 'select');
        fd.append('fragment', fragment);
        const res = await fetch('patient_select.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { alert("Erreur : " + (data.error || 'inconnue')); btn?.classList.remove('btn-loading'); return; }
        wizGoToFreins(data.patient);
    } catch (e) { alert("Erreur réseau : " + e.message); btn?.classList.remove('btn-loading'); }
}

// ── AJAX : création d'un nouveau patient ──────────────────────────────
async function createNewPatient(ev) {
    ev.preventDefault();
    const form = ev.target;
    const btn = document.getElementById('btn-create-patient');
    btn.classList.add('btn-loading');
    const fd = new FormData(form);
    fd.set('action', 'create');
    try {
        const res = await fetch('patient_select.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { alert("Erreur : " + (data.error || 'inconnue')); btn.classList.remove('btn-loading'); return false; }
        wizGoToFreins(data.patient);
    } catch (e) { alert("Erreur réseau : " + e.message); btn.classList.remove('btn-loading'); }
    return false;
}

// ═══════════════════════════════════════════════════════════════════════
//  LOGIQUE FREINS / LEVIERS PAR ACTIVITÉ
// ═══════════════════════════════════════════════════════════════════════

// Formate un nom de levier en label lisible (ex: PausesFrequentes → "Pauses Frequentes")
function levierLabel(name) {
    return name.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim();
}

function onFreinChange(cb) {
    const id  = cb.value;
    const row = document.getElementById('row-' + id);
    if (cb.checked) { checked.add(id);    row?.classList.add('checked'); }
    else            { checked.delete(id); row?.classList.remove('checked'); }
    updateGroupBadges();
    update();
}

// Met à jour le badge "X coché(s)" affiché sur chaque groupe replié
function updateGroupBadges() {
    document.querySelectorAll('.fr-freins-group').forEach(grp => {
        const inputs = grp.querySelectorAll('.frein-cb:checked');
        const badge = grp.querySelector('.fr-freins-group-checked');
        if (!badge) return;
        if (inputs.length > 0) {
            badge.textContent = inputs.length + ' coché' + (inputs.length > 1 ? 's' : '');
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    });
}

function resetFreins() {
    checked.clear();
    selectedLeviersPerAct.clear();
    document.querySelectorAll('.frein-cb').forEach(cb => {
        cb.checked = false;
        document.getElementById('row-' + cb.value)?.classList.remove('checked');
    });
    updateGroupBadges();
    update();
}

// Toggle d'un levier pour UNE activité spécifique
function toggleActLevier(activityId, levierName, chipEl) {
    if (!selectedLeviersPerAct.has(activityId)) {
        selectedLeviersPerAct.set(activityId, new Set());
    }
    const set = selectedLeviersPerAct.get(activityId);
    if (set.has(levierName)) { set.delete(levierName); chipEl.classList.remove('selected'); }
    else                     { set.add(levierName);    chipEl.classList.add('selected'); }
    updateSaveCount();
}

function updateSaveCount() {
    // Compteur total de leviers sélectionnés (toutes activités)
    let total = 0;
    selectedLeviersPerAct.forEach(s => total += s.size);
    const badge = document.getElementById('saveLeviersCount');
    if (badge) badge.textContent = total;
}

function update() {
    const n = checked.size;
    const badge = document.getElementById('freinsCountBadge');
    if (badge) badge.textContent = n;

    // Calculer la liste cumulée des leviers proposés (groupée par frein)
    // Ex: [{frein:'Fatigue Physique', leviers:['Pauses','Reduction']}, {frein:'Stress', leviers:['Relaxation']}]
    const freinsCoches = [...checked].map(id => {
        const f = FREINS.find(x => x.id === id);
        return f ? { freinId: id, freinLabel: f.label, leviers: f.leviers || [] } : null;
    }).filter(x => x !== null);

    // Pour chaque activité, on remplit sa zone leviers
    ACTIVITES.forEach(a => {
        const card  = document.getElementById('act-' + a.id);
        const stat  = document.getElementById('status-' + a.id);
        const zone  = document.getElementById('act-leviers-' + a.id);
        if (!card || !stat || !zone) return;

        card.classList.remove('fr-act-compatible', 'fr-act-supported', 'fr-act-neutral');
        if (n === 0) {
            card.classList.add('fr-act-neutral');
            stat.textContent = '—';
            zone.innerHTML = '';
            zone.style.display = 'none';
        } else {
            card.classList.add('fr-act-supported');
            stat.textContent = '⚙ À paramétrer';

            // Construire le HTML des leviers proposés pour CETTE activité
            const selSet = selectedLeviersPerAct.get(a.id) || new Set();
            let html = '<div class="fr-act-leviers-title">Leviers proposés pour cette activité</div>';

            freinsCoches.forEach(fc => {
                if (fc.leviers.length === 0) return;
                html += '<div class="fr-act-leviers-group">';
                html += `<div class="fr-act-leviers-frein">Pour gérer <strong>${fc.freinLabel}</strong> :</div>`;
                fc.leviers.forEach(l => {
                    const isSel = selSet.has(l);
                    html += `<button type="button" class="fr-act-levier${isSel ? ' selected' : ''}"
                              onclick="toggleActLevier('${a.id}', '${l}', this)">${levierLabel(l)}</button>`;
                });
                html += '</div>';
            });

            // Si aucun levier disponible pour les freins cochés
            const totalLeviersDispo = freinsCoches.reduce((s, fc) => s + fc.leviers.length, 0);
            if (totalLeviersDispo === 0) {
                html = '<div class="fr-act-leviers-title">Leviers</div>' +
                       '<div style="font-size:11px;color:#94a3b8;font-style:italic">Aucun levier proposé pour les freins cochés.</div>';
            }

            zone.innerHTML = html;
            zone.style.display = '';
        }
    });

    updateSaveCount();
}

// ── Enregistrement final ───────────────────────────────────────────────
async function enregistrerPrescription(skip = false) {
    const btnFinal = document.getElementById('btn-enregistrer-final');
    const feedback = document.getElementById('save-feedback');

    // Compter les leviers sélectionnés (toutes activités confondues)
    let totalLeviers = 0;
    selectedLeviersPerAct.forEach(s => totalLeviers += s.size);

    // ── Garde-fou 1 : aucun frein coché (et l'utilisateur n'a pas cliqué "Sans freins/leviers") ──
    if (!skip && checked.size === 0) {
        const ok = confirm(
            "⚠ Aucun frein n'a été coché.\n\n" +
            "Il est recommandé d'identifier les freins du patient pour adapter la prescription.\n\n" +
            "Voulez-vous quand même enregistrer la prescription sans freins ?"
        );
        if (!ok) return;
    }

    // ── Garde-fou 2 : des freins cochés mais aucun levier sélectionné ──
    if (!skip && checked.size > 0 && totalLeviers === 0) {
        const ok = confirm(
            "Vous avez coché des freins mais aucun levier n'est sélectionné pour aucune activité.\n\n" +
            "Voulez-vous enregistrer quand même ?"
        );
        if (!ok) return;
    }

    if (!skip) {
        const freinsChecked = [...document.querySelectorAll('.frein-cb:checked')].map(el => el.value);
        // Unifier tous les leviers sélectionnés (pour rester compatible avec freins_save.php)
        const allLeviers = new Set();
        selectedLeviersPerAct.forEach(s => s.forEach(l => allLeviers.add(l)));
        const leviersChecked = [...allLeviers];

        const fd = new FormData();
        freinsChecked.forEach(id => fd.append('freins[]', id));
        leviersChecked.forEach(id => fd.append('leviers[]', id));
        // Envoie aussi la répartition leviers/activité pour stockage en session
        selectedLeviersPerAct.forEach((leviers, activityId) => {
            leviers.forEach(l => {
                fd.append('leviers_par_activite[' + activityId + '][]', l);
            });
        });
        try { await fetch('freins_save.php', { method: 'POST', body: fd }); }
        catch (e) { console.warn('Sauvegarde freins échouée', e); }
    } else {
        try { await fetch('freins_save.php?skip=1', { method: 'POST' }); } catch (e) {}
    }

    btnFinal.disabled = true;
    btnFinal.classList.add('btn-loading');

    try {
        const res = await fetch('enregistrer_prescription.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            const id = data.prescription_fragment;
            feedback.style.display = 'block';
            feedback.innerHTML = `
                <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);
                            border:2px solid #6ee7b7;border-radius:18px;padding:20px;
                            color:#065f46;text-align:center">
                    <div style="font-size:40px;line-height:1;margin-bottom:8px">✅</div>
                    <h3 style="margin:0 0 6px;color:#047857;font-size:18px">
                        Prescription enregistrée avec succès !
                    </h3>
                    <p style="margin:0;font-size:13px">
                        ${data.nb_pathologies} pathologie(s) ·
                        ${data.nb_activites} activité(s) ·
                        ${data.nb_freins} frein(s) ·
                        ${data.nb_leviers} levier(s)
                    </p>
                    <p style="margin:10px 0 0;font-size:12px;font-style:italic">
                        Redirection vers le détail de la prescription...
                    </p>
                </div>`;
            document.querySelector('.fr-save-btns').style.display = 'none';
            setTimeout(() => {
                window.location.href = 'prescription_detail.php?id=' + encodeURIComponent(id);
            }, 1500);
        } else {
            feedback.style.display = 'block';
            feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;
                                  border-radius:14px;padding:14px;color:#7f1d1d">
                <strong>❌ Échec :</strong> ${data.error || 'erreur inconnue'}</div>`;
            btnFinal.disabled = false;
            btnFinal.classList.remove('btn-loading');
        }
    } catch (e) {
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;
                              border-radius:14px;padding:14px;color:#7f1d1d">
            <strong>❌ Erreur réseau :</strong> ${e.message}</div>`;
        btnFinal.disabled = false;
        btnFinal.classList.remove('btn-loading');
    }
}

// Init
update();

// ─── Si un patient est déjà en session (venu depuis patient_detail.php),
//     on saute directement à l'écran "freins" sans passer par le choix ───
<?php if ($patientAlreadyInSession && $prefilledPatient): ?>
    wizGoToFreins(<?= json_encode($prefilledPatient, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS) ?>);
<?php endif; ?>

<?php endif; /* fin du JS wizard */ ?>
</script>

</body>
</html>
