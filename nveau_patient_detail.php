<?php
/**
 * APA4CAD - Module 2 : Dossier patient (refonte UX épurée)
 *
 * Design pro : actions principales mises en avant, sections secondaires
 * cachées en modales, espacement aéré, couleurs originales conservées.
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/patient_session.php';

function sparqlQueryPD(string $query): array {
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

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function localNamePD(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}
function prettyLabelPD(string $name): string {
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $name)));
}
function categoryTitlePD(string $local): string {
    return match ($local) {
        'AffectionDeLongueDuree' => 'Affections de longue durée',
        'PathologieCardiaque' => 'Pathologies cardiaques',
        'PathologieDigestive' => 'Pathologies digestives',
        'PathologieMusculosquelettique' => 'Pathologies musculosquelettiques',
        'PathologieRespiratoire' => 'Pathologies respiratoires',
        'PathologieCoronarienne' => 'Pathologies coronariennes',
        'CardiopathiesInflammatoires' => 'Cardiopathies inflammatoires',
        'CoronaropathieChronique' => 'Coronaropathie chronique',
        'CoronaropathieFonctionnelle' => 'Coronaropathie fonctionnelle',
        'SyndromeCoronarienAigu' => 'Syndrome coronarien aigu',
        'Diabete' => 'Diabète', 'Arthrose' => 'Arthrose',
        'AngorStable' => 'Angor stable', 'AngorInstable' => 'Angor instable',
        'CoronaropathieAsymptomatique' => 'Coronaropathie asymptomatique',
        'IschemieMyocardiqueStable' => 'Ischémie myocardique stable',
        'SpasmeCoronarien' => 'Spasme coronarien',
        'InfarctusDuMyocarde' => 'Infarctus du myocarde',
        'Endocardite' => 'Endocardite', 'Myocardite' => 'Myocardite',
        'Pericardite' => 'Péricardite', 'Cancer' => 'Cancer',
        'Hypertension_arterielle' => 'Hypertension artérielle',
        'Obesite' => 'Obésité', 'DT1' => 'Diabète de type 1', 'DT2' => 'Diabète de type 2',
        'ArthroseCervicale' => 'Arthrose cervicale', 'ArthroseEpaule' => 'Arthrose de l\'épaule',
        'ArthroseGenou' => 'Arthrose du genou', 'ArthroseHanche' => 'Arthrose de la hanche',
        'Lombalgie' => 'Lombalgie', 'Menisectomie' => 'Méniscectomie',
        'ApneeDuSommeil' => 'Apnée du sommeil',
        'BronchopneumopathieChroniqueObstructive' => 'BPCO',
        'Diastasis' => 'Diastasis', 'Eventration' => 'Éventration',
        'HernieInguinale' => 'Hernie inguinale',
        default => prettyLabelPD($local),
    };
}
function formatDatePD(string $iso): string {
    if ($iso === '') return '—';
    try { return (new DateTime($iso))->format('d/m/Y à H:i'); }
    catch (Exception $e) { return $iso; }
}

$id = trim($_GET['id'] ?? '');
if ($id === '') { http_response_code(400); die('ID de patient manquant.'); }
$patientUri = ONTO_NAMESPACE . $id;

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pathoUri = $_POST['patho'] ?? '';
    if ($pathoUri !== '' && str_starts_with($pathoUri, ONTO_NAMESPACE)) {
        if ($action === 'add') {
            $askQ = sparqlPrefixes() . " ASK { <$patientUri> ex:aPourPathologie <$pathoUri> }";
            $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($askQ);
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Accept: application/sparql-results+json\r\n"]]);
            $resp = @file_get_contents($url, false, $ctx);
            $askData = json_decode($resp ?: '{}', true);
            if (isset($askData['boolean']) && $askData['boolean']) {
                $flash = ['type' => 'info', 'msg' => 'Pathologie déjà active.'];
            } else {
                sparqlUpdate(sparqlPrefixes() . " DELETE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> } WHERE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> }");
                $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { <$patientUri> ex:aPourPathologie <$pathoUri> }");
                $flash = $res['success'] ? ['type'=>'success','msg'=>'Pathologie ajoutée.'] : ['type'=>'error','msg'=>'Erreur lors de l\'ajout.'];
            }
        } elseif ($action === 'archive') {
            sparqlUpdate(sparqlPrefixes() . " DELETE { <$patientUri> ex:aPourPathologie <$pathoUri> } WHERE { <$patientUri> ex:aPourPathologie <$pathoUri> }");
            $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> }");
            $flash = $res['success'] ? ['type'=>'success','msg'=>'Pathologie archivée.'] : ['type'=>'error','msg'=>'Erreur.'];
        } elseif ($action === 'restore') {
            sparqlUpdate(sparqlPrefixes() . " DELETE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> } WHERE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> }");
            $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { <$patientUri> ex:aPourPathologie <$pathoUri> }");
            $flash = $res['success'] ? ['type'=>'success','msg'=>'Pathologie réactivée.'] : ['type'=>'error','msg'=>'Erreur.'];
        }
    }
}

if (isset($_GET['consult']) && $_GET['consult'] === '1') {
    $checkedPathos = $_GET['pathos'] ?? [];
    if (!is_array($checkedPathos)) $checkedPathos = [$checkedPathos];
    $checkedPathos = array_values(array_filter($checkedPathos, fn($v) => is_string($v) && $v !== ''));
    if (!empty($checkedPathos)) {
        $vRows = sparqlQueryPD(sparqlPrefixes() . "
            SELECT ?nom ?prenom ?age ?dossier WHERE {
                <$patientUri> a ex:Patient .
                OPTIONAL { <$patientUri> ex:aPourNom ?nom }
                OPTIONAL { <$patientUri> ex:aPourPrenom ?prenom }
                OPTIONAL { <$patientUri> ex:aPourAge ?age }
                OPTIONAL { <$patientUri> ex:aPourNumeroDossier ?dossier }
            }");
        if (!empty($vRows)) {
            $b = $vRows[0];
            $_SESSION['patient_uri'] = $patientUri;
            $_SESSION['patient_fragment'] = $id;
            $_SESSION['patient_nom'] = $b['nom']['value'] ?? '';
            $_SESSION['patient_prenom'] = $b['prenom']['value'] ?? '';
            $_SESSION['patient_age'] = $b['age']['value'] ?? '';
            $_SESSION['patient_dossier'] = $b['dossier']['value'] ?? '';
        }
        header('Location: rapport.php?' . http_build_query(['pathologies' => $checkedPathos]));
        exit;
    } else {
        $flash = ['type'=>'error','msg'=>'Sélectionnez au moins une pathologie.'];
    }
}

$infoRows = sparqlQueryPD(sparqlPrefixes() . "
    SELECT ?nom ?prenom ?age ?dossier ?genreLabel ?trancheLabel WHERE {
        <$patientUri> a ex:Patient .
        OPTIONAL { <$patientUri> ex:aPourNom ?nom }
        OPTIONAL { <$patientUri> ex:aPourPrenom ?prenom }
        OPTIONAL { <$patientUri> ex:aPourAge ?age }
        OPTIONAL { <$patientUri> ex:aPourNumeroDossier ?dossier }
        OPTIONAL { <$patientUri> ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
        OPTIONAL { <$patientUri> ex:aPourtrancheAge ?tranche . OPTIONAL { ?tranche rdfs:label ?trancheLabel . FILTER(lang(?trancheLabel)=\"fr\") } }
    }");
if (empty($infoRows)) { http_response_code(404); die('Patient introuvable : ' . h($id)); }
$pInfo = $infoRows[0];
$patient = [
    'nom' => $pInfo['nom']['value'] ?? '',
    'prenom' => $pInfo['prenom']['value'] ?? '',
    'age' => $pInfo['age']['value'] ?? '',
    'dossier' => $pInfo['dossier']['value'] ?? '',
    'genre' => $pInfo['genreLabel']['value'] ?? '',
    'tranche' => $pInfo['trancheLabel']['value'] ?? '',
];
$patientName = trim($patient['prenom'] . ' ' . $patient['nom']) ?: '(patient anonyme)';

$activePathos = [];
foreach (sparqlQueryPD(sparqlPrefixes() . " SELECT DISTINCT ?patho WHERE { <$patientUri> ex:aPourPathologie ?patho }") as $r) {
    $uri = $r['patho']['value']; $local = localNamePD($uri);
    $activePathos[] = ['uri'=>$uri,'local'=>$local,'label'=>categoryTitlePD($local)];
}
$archivedPathos = [];
foreach (sparqlQueryPD(sparqlPrefixes() . " SELECT DISTINCT ?patho WHERE { <$patientUri> ex:aPourPathologieArchivee ?patho }") as $r) {
    $uri = $r['patho']['value']; $local = localNamePD($uri);
    $archivedPathos[] = ['uri'=>$uri,'local'=>$local,'label'=>categoryTitlePD($local)];
}
$allPathos = [];
$activeSet = array_flip(array_map(fn($p)=>$p['uri'],$activePathos));
$archivedSet = array_flip(array_map(fn($p)=>$p['uri'],$archivedPathos));

// ─── Charger TOUTES les pathologies (même logique que index.php) ───────────
// On parcourt récursivement l'arbre depuis les 5 racines, en utilisant
// subClassOf direct ET via owl:intersectionOf (pour les classes anonymes)
$treeQuery = sparqlPrefixes() . "
    SELECT DISTINCT ?child ?parent WHERE {
        {
            ?child rdfs:subClassOf ?parent .
            FILTER(isIRI(?parent))
            FILTER(STRSTARTS(STR(?child), \"" . ONTO_NAMESPACE . "\"))
            FILTER(STRSTARTS(STR(?parent), \"" . ONTO_NAMESPACE . "\"))
            FILTER(?child != ?parent)
        }
        UNION
        {
            ?child rdfs:subClassOf ?anon .
            FILTER(isBlank(?anon))
            ?anon owl:intersectionOf/rdf:rest*/rdf:first ?parent .
            FILTER(isIRI(?parent))
            FILTER(STRSTARTS(STR(?child), \"" . ONTO_NAMESPACE . "\"))
            FILTER(STRSTARTS(STR(?parent), \"" . ONTO_NAMESPACE . "\"))
            FILTER(?child != ?parent)
        }
    }
";
$childrenOf = [];
foreach (sparqlQueryPD($treeQuery) as $r) {
    $child = $r['child']['value'] ?? '';
    $parent = $r['parent']['value'] ?? '';
    if ($child === '' || $parent === '' || $child === $parent) continue;
    $childrenOf[$parent][$child] = true;
}

// Les 5 racines (catégories, non-sélectionnables comme dans index.php)
$topRoots = [
    ONTO_NAMESPACE . 'AffectionDeLongueDuree',
    ONTO_NAMESPACE . 'PathologieCardiaque',
    ONTO_NAMESPACE . 'PathologieDigestive',
    ONTO_NAMESPACE . 'PathologieMusculosquelettique',
    ONTO_NAMESPACE . 'PathologieRespiratoire',
];
$rootNames = array_map('localNamePD', $topRoots);

// Parcours récursif pour collecter toutes les pathologies sélectionnables (= feuilles)
$visited = [];
$collectLeaves = function(string $uri) use (&$collectLeaves, &$childrenOf, &$visited, &$allPathos, $activeSet, $archivedSet, $rootNames) {
    if (isset($visited[$uri])) return;
    $visited[$uri] = true;
    $local = localNamePD($uri);
    $children = array_keys($childrenOf[$uri] ?? []);

    if (empty($children)) {
        // Feuille = pathologie sélectionnable (sauf si déjà active/archivée ou si c'est une racine)
        if (!isset($activeSet[$uri]) && !isset($archivedSet[$uri]) && !in_array($local, $rootNames, true)) {
            $allPathos[$uri] = ['uri'=>$uri,'local'=>$local,'label'=>categoryTitlePD($local)];
        }
    } else {
        // Nœud intermédiaire : parcourir les enfants
        foreach ($children as $childUri) {
            $collectLeaves($childUri);
        }
    }
};

foreach ($topRoots as $rootUri) {
    $collectLeaves($rootUri);
}

// Trier par label alphabétique
$allPathos = array_values($allPathos);
usort($allPathos, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

$prescriptions = [];
foreach (sparqlQueryPD(sparqlPrefixes() . "
    SELECT ?prescription ?date (COUNT(DISTINCT ?activite) AS ?nbActs) WHERE {
        ?prescription a ex:Prescription ; ex:concerne <$patientUri> .
        OPTIONAL { ?prescription ex:aPourDate ?date }
        OPTIONAL { ?prescription ex:contient ?activite }
    } GROUP BY ?prescription ?date ORDER BY DESC(?date)") as $r) {
    $uri = $r['prescription']['value'];
    $prescriptions[] = [
        'fragment' => localNamePD($uri),
        'date' => $r['date']['value'] ?? '',
        'nbActs' => (int)($r['nbActs']['value'] ?? 0),
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dossier — <?= h($patientName) ?> · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}
button{font-family:inherit;cursor:pointer}

/* Topbar */
.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 0}
.topbar-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:32px}
.topbar-brand{font-weight:700;font-size:17px;color:#1d4ed8;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#1d4ed8;border-radius:2px;display:inline-block}
.topbar-nav{display:flex;gap:6px;margin-left:auto}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#475569;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:#f1f5f9;color:#1e293b}
.topbar-nav a.active{background:#eff6ff;color:#1d4ed8;font-weight:600}

.app{max-width:1200px;margin:0 auto;padding:32px 24px 80px}

/* Bannière patient (garde le gradient bleu original) */
.banner{background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;
        border-radius:18px;padding:30px 34px;margin-bottom:28px;
        box-shadow:0 14px 28px rgba(37,99,235,.18)}
.banner .crumbs{font-size:12px;opacity:.85;margin-bottom:8px}
.banner .crumbs a{color:#fff;opacity:.9}
.banner .crumbs .sep{margin:0 6px;opacity:.6}
.banner h1{margin:0 0 12px;font-size:28px;font-weight:700;letter-spacing:-0.02em}
.banner .meta{display:flex;gap:22px;font-size:14px;flex-wrap:wrap}
.banner .meta span{display:inline-flex;align-items:center;gap:6px;opacity:.95}
.banner .dossier-pill{background:rgba(255,255,255,.18);padding:3px 12px;border-radius:999px;
                       border:1px solid rgba(255,255,255,.3);font-family:ui-monospace,monospace;font-size:12px}

/* Action principale */
.main-action{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
             padding:22px 26px;margin-bottom:28px;display:flex;align-items:center;
             justify-content:space-between;gap:20px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.main-action .ma-text h2{margin:0 0 4px;font-size:17px;font-weight:700;color:#1e293b}
.main-action .ma-text p{margin:0;color:#6b7280;font-size:13px}
.btn-primary{background:#2563eb;color:#fff;border:none;border-radius:10px;
             padding:13px 24px;font-size:14px;font-weight:700;transition:.15s}
.btn-primary:hover{background:#1d4ed8;box-shadow:0 4px 12px rgba(37,99,235,.3)}

/* Grid */
.grid-2{display:grid;grid-template-columns:1fr 1.4fr;gap:22px}
@media(max-width:900px){.grid-2{grid-template-columns:1fr}}

/* Cartes */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;
      padding:24px 26px;margin-bottom:22px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.card-head h2{margin:0;font-size:16px;font-weight:700;color:#1e293b;letter-spacing:-0.01em}
.card-head .count{color:#9ca3af;font-weight:400;margin-left:4px;font-size:14px}

/* Identité */
.id-row{display:flex;justify-content:space-between;padding:10px 0;font-size:13px;border-bottom:1px solid #f1f5f9}
.id-row:last-child{border-bottom:none}
.id-row .lbl{color:#6b7280}
.id-row .val{color:#1e293b;font-weight:600}
.id-row .val.mono{font-family:ui-monospace,monospace;background:#f1f5f9;
                   padding:3px 8px;border-radius:4px;font-size:12px;font-weight:500}

/* Liste pathologies (couleur jaune originale) */
.patho-list{display:flex;flex-direction:column;gap:8px}
.patho-line{display:flex;align-items:center;justify-content:space-between;
            padding:12px 16px;background:#fef3c7;border:1px solid #fcd34d;
            border-radius:10px;gap:12px;transition:.15s}
.patho-line:hover{background:#fde68a}
.patho-line .name{font-weight:600;color:#92400e;font-size:14px}
.patho-line.archived{background:#f9fafb;border-color:#e5e7eb}
.patho-line.archived .name{color:#9ca3af;text-decoration:line-through;text-decoration-color:#cbd5e1}

.btn-mini{background:#fff;border:1px solid #fcd34d;color:#92400e;border-radius:7px;
           padding:6px 12px;font-size:12px;font-weight:600;transition:.15s}
.btn-mini:hover{background:#fef3c7}
.btn-mini.ok{border-color:#d1fae5;color:#047857}
.btn-mini.ok:hover{background:#ecfdf5}

.add-trigger{width:100%;background:#f9fafb;border:2px dashed #d1d5db;color:#6b7280;
              padding:14px;border-radius:10px;font-size:13px;font-weight:600;
              margin-top:12px;transition:.15s}
.add-trigger:hover{background:#eff6ff;border-color:#93c5fd;color:#2563eb}

/* Section pliable */
.section-toggle{background:none;border:none;width:100%;padding:14px 0;
                 display:flex;justify-content:space-between;align-items:center;
                 font-size:13px;font-weight:600;color:#6b7280;text-align:left;
                 border-top:1px solid #f1f5f9;margin-top:8px}
.section-toggle:hover{color:#1e293b}
.section-toggle .chevron{transition:transform .2s;font-size:12px}
.section-toggle.open .chevron{transform:rotate(90deg)}
.section-content{display:none;padding-bottom:10px}
.section-content.open{display:block;animation:slideDown .2s ease}
@keyframes slideDown{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;
                align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:85vh;
        display:flex;flex-direction:column;overflow:hidden;
        box-shadow:0 24px 48px rgba(0,0,0,.2);animation:modalIn .2s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.modal-head{padding:22px 26px 16px;border-bottom:1px solid #e5e7eb}
.modal-head h2{margin:0 0 4px;font-size:18px;font-weight:700}
.modal-head p{margin:0;color:#6b7280;font-size:13px}
.modal-search{padding:16px 26px}
.modal-search input{width:100%;padding:11px 14px;border:1px solid #e5e7eb;
                     border-radius:10px;font-size:14px;font-family:inherit}
.modal-search input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.modal-body{padding:0 26px 16px;overflow-y:auto;flex:1}
.modal-foot{padding:16px 26px;border-top:1px solid #e5e7eb;display:flex;
             justify-content:flex-end;background:#f9fafb;gap:8px}
.modal-close{background:#fff;border:1px solid #e5e7eb;color:#6b7280;
              padding:9px 18px;border-radius:9px;font-weight:600;font-size:13px}
.modal-close:hover{background:#f9fafb;color:#1e293b}

.modal-list{display:flex;flex-direction:column;gap:4px}
.modal-item{display:flex;align-items:center;justify-content:space-between;
             padding:10px 14px;border-radius:8px;transition:.12s}
.modal-item:hover{background:#f9fafb}
.modal-item .name{font-size:13px;color:#1e293b}
.btn-add{background:#2563eb;color:#fff;border:none;padding:6px 12px;
          border-radius:7px;font-weight:600;font-size:12px}
.btn-add:hover{background:#1d4ed8}

.consult-list{display:flex;flex-direction:column;gap:4px;max-height:320px;overflow-y:auto}
.consult-row{display:flex;align-items:center;gap:12px;padding:10px 12px;
              border-radius:8px;transition:.12s;cursor:pointer}
.consult-row:hover{background:#f1f5f9}
.consult-row input{transform:scale(1.15);cursor:pointer;flex-shrink:0}
.consult-row label{cursor:pointer;flex:1;font-size:14px;color:#1e293b;font-weight:500}
.consult-row.archived label{color:#6b7280}
.consult-row .badge-arch{background:#f1f5f9;color:#64748b;font-size:10px;
                          padding:2px 7px;border-radius:5px;font-weight:700;
                          text-transform:uppercase;letter-spacing:.5px}

/* Prescriptions */
.presc-list{display:flex;flex-direction:column;gap:2px}
.presc-row{display:flex;align-items:center;justify-content:space-between;
            padding:12px 14px;border-radius:10px;transition:.15s;border:1px solid transparent}
.presc-row:hover{background:#f9fafb;border-color:#e5e7eb}
.presc-row .date{font-weight:600;color:#1e293b;font-size:14px}
.presc-row .acts{font-size:12px;color:#6b7280;margin-top:2px}
.presc-row a.view-btn{color:#2563eb;font-weight:600;font-size:13px;
                       padding:6px 12px;border-radius:7px}
.presc-row a.view-btn:hover{background:#eff6ff}

.empty{padding:36px 16px;text-align:center;color:#9ca3af;font-size:13px;font-style:italic}

.flash{padding:12px 18px;border-radius:10px;margin-bottom:22px;font-size:13px;
        font-weight:500;border:1px solid}
.flash-success{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.flash-error{background:#fef2f2;color:#b91c1c;border-color:#fca5a5}
.flash-info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}

@media print{.topbar,.main-action,.btn-mini,.add-trigger,.section-toggle{display:none}}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <nav class="topbar-nav">
            <a href="index.php">Nouvelle prescription</a>
            <a href="patient.php" class="active">Patients</a>
            <a href="prescriptions.php">Historique</a>
        </nav>
    </div>
</div>

<div class="app">

    <section class="banner">
        <div class="crumbs">
            <a href="patient.php">Patients</a><span class="sep">›</span><span><?= h($patientName) ?></span>
        </div>
        <h1><?= h($patientName) ?></h1>
        <div class="meta">
            <?php if ($patient['age'] !== ''): ?><span>🎂 <?= h($patient['age']) ?> ans</span><?php endif; ?>
            <?php if ($patient['genre'] !== ''): ?><span>⚧ <?= h($patient['genre']) ?></span><?php endif; ?>
            <?php if ($patient['tranche'] !== ''): ?><span><?= h($patient['tranche']) ?></span><?php endif; ?>
            <?php if ($patient['dossier'] !== ''): ?><span class="dossier-pill"><?= h($patient['dossier']) ?></span><?php endif; ?>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!empty($activePathos) || !empty($archivedPathos)): ?>
    <div class="main-action">
        <div class="ma-text">
            <h2>🩺 Démarrer une nouvelle consultation</h2>
            <p>Créer une nouvelle prescription d'activité physique pour <?= h($patientName) ?>.</p>
        </div>
        <button class="btn-primary" onclick="openModal('modal-consult')">Nouvelle consultation →</button>
    </div>
    <?php endif; ?>

    <div class="grid-2">

        <div class="card">
            <div class="card-head"><h2>Informations</h2></div>
            <div>
                <div class="id-row"><span class="lbl">Nom</span><span class="val"><?= h($patient['nom']) ?: '—' ?></span></div>
                <div class="id-row"><span class="lbl">Prénom</span><span class="val"><?= h($patient['prenom']) ?: '—' ?></span></div>
                <div class="id-row"><span class="lbl">Âge</span><span class="val"><?= h($patient['age']) ?: '—' ?> ans</span></div>
                <div class="id-row"><span class="lbl">Sexe</span><span class="val"><?= h($patient['genre']) ?: '—' ?></span></div>
                <div class="id-row"><span class="lbl">Dossier</span>
                    <span class="val<?= $patient['dossier'] ? ' mono' : '' ?>"><?= h($patient['dossier']) ?: '—' ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Pathologies <span class="count">· <?= count($activePathos) ?> active<?= count($activePathos) > 1 ? 's' : '' ?></span></h2>
            </div>

            <?php if (empty($activePathos)): ?>
                <div class="empty">Aucune pathologie active.</div>
            <?php else: ?>
                <div class="patho-list">
                    <?php foreach ($activePathos as $p): ?>
                        <div class="patho-line">
                            <span class="name"><?= h($p['label']) ?></span>
                            <form method="post" style="margin:0">
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="patho" value="<?= h($p['uri']) ?>">
                                <button type="submit" class="btn-mini" title="Archiver">Archiver</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <button class="add-trigger" onclick="openModal('modal-add')">+ Ajouter une pathologie</button>

            <?php if (!empty($archivedPathos)): ?>
                <button class="section-toggle" onclick="toggleSection(this)">
                    <span>📦 Pathologies archivées (<?= count($archivedPathos) ?>)</span>
                    <span class="chevron">›</span>
                </button>
                <div class="section-content">
                    <div class="patho-list">
                        <?php foreach ($archivedPathos as $p): ?>
                            <div class="patho-line archived">
                                <span class="name"><?= h($p['label']) ?></span>
                                <form method="post" style="margin:0">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="patho" value="<?= h($p['uri']) ?>">
                                    <button type="submit" class="btn-mini ok">Réactiver</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Historique des prescriptions <span class="count">· <?= count($prescriptions) ?></span></h2>
        </div>
        <?php if (empty($prescriptions)): ?>
            <div class="empty">Aucune prescription enregistrée.</div>
        <?php else: ?>
            <div class="presc-list">
                <?php foreach ($prescriptions as $pr): ?>
                    <div class="presc-row">
                        <div>
                            <div class="date"><?= h(formatDatePD($pr['date'])) ?></div>
                            <div class="acts"><?= $pr['nbActs'] ?> activité<?= $pr['nbActs'] > 1 ? 's' : '' ?> prescrite<?= $pr['nbActs'] > 1 ? 's' : '' ?></div>
                        </div>
                        <a href="prescription_detail.php?id=<?= urlencode($pr['fragment']) ?>" class="view-btn">Voir →</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modal Ajouter -->
<div class="modal-overlay" id="modal-add">
    <div class="modal">
        <div class="modal-head">
            <h2>Ajouter une pathologie</h2>
            <p><?= count($allPathos) ?> pathologie<?= count($allPathos) > 1 ? 's' : '' ?> disponible<?= count($allPathos) > 1 ? 's' : '' ?>.</p>
        </div>
        <div class="modal-search">
            <input type="text" id="patho-search" placeholder="Rechercher (ex : diabète, BPCO, arthrose...)" oninput="filterPathos()">
        </div>
        <div class="modal-body">
            <div class="modal-list" id="add-list">
                <?php foreach ($allPathos as $p): ?>
                    <div class="modal-item" data-label="<?= h(strtolower($p['label'])) ?>">
                        <span class="name"><?= h($p['label']) ?></span>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="patho" value="<?= h($p['uri']) ?>">
                            <button type="submit" class="btn-add">Ajouter</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-foot">
            <button class="modal-close" onclick="closeModal('modal-add')">Fermer</button>
        </div>
    </div>
</div>

<!-- Modal Consultation -->
<div class="modal-overlay" id="modal-consult">
    <div class="modal">
        <div class="modal-head">
            <h2>Nouvelle consultation</h2>
            <p>Sélectionnez les pathologies à prendre en compte.</p>
        </div>
        <form method="get" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <input type="hidden" name="consult" value="1">
            <div class="modal-body">
                <div class="consult-list">
                    <?php foreach ($activePathos as $p): ?>
                        <label class="consult-row">
                            <input type="checkbox" name="pathos[]" value="<?= h($p['uri']) ?>" checked>
                            <span><?= h($p['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php foreach ($archivedPathos as $p): ?>
                        <label class="consult-row archived">
                            <input type="checkbox" name="pathos[]" value="<?= h($p['uri']) ?>">
                            <span><?= h($p['label']) ?></span>
                            <span class="badge-arch">archivée</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot" style="justify-content:space-between">
                <button type="button" class="modal-close" onclick="closeModal('modal-consult')">Annuler</button>
                <button type="submit" class="btn-add" style="padding:9px 22px;font-size:13px">Démarrer →</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';
    const s=document.querySelector('#'+id+' input[type=text]');if(s)setTimeout(()=>s.focus(),50);}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(o=>closeModal(o.id));});
function toggleSection(b){b.classList.toggle('open');b.nextElementSibling.classList.toggle('open');}
function filterPathos(){const t=document.getElementById('patho-search').value.toLowerCase().trim();
    document.querySelectorAll('#add-list .modal-item').forEach(i=>{
        const l=i.getAttribute('data-label')||'';
        i.style.display=(t===''||l.includes(t))?'flex':'none';});}
</script>

</body>
</html>
