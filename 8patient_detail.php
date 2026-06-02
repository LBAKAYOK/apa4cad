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
            // Motif obligatoire pour l'archivage
            $motif = trim($_POST['motif'] ?? '');
            if ($motif === '') {
                $flash = ['type'=>'error','msg'=>'Le motif d\'archivage est obligatoire.'];
            } else {
                // 1) Bouger la pathologie de active vers archivée
                sparqlUpdate(sparqlPrefixes() . " DELETE { <$patientUri> ex:aPourPathologie <$pathoUri> } WHERE { <$patientUri> ex:aPourPathologie <$pathoUri> }");
                $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> }");

                // 2) Créer l'événement de traçabilité (ex:ArchivagePathologie)
                if ($res['success']) {
                    $pathoLocal = (str_contains($pathoUri, '#'))
                        ? substr($pathoUri, strrpos($pathoUri, '#') + 1) : 'Patho';
                    $patientLocal = (str_contains($patientUri, '#'))
                        ? substr($patientUri, strrpos($patientUri, '#') + 1) : 'Patient';
                    $eventFrag = "Archivage_{$patientLocal}_{$pathoLocal}_" . date('YmdHis');
                    $eventUri  = ONTO_NAMESPACE . $eventFrag;
                    $now       = date('Y-m-d\TH:i:s');
                    $motifEsc  = sparqlEscapeString($motif);

                    $eventInsert = sparqlPrefixes() . " INSERT DATA {
                        <$eventUri> rdf:type owl:NamedIndividual ;
                                    rdf:type ex:ArchivagePathologie ;
                                    ex:concerneArchivagePatient <$patientUri> ;
                                    ex:concerneArchivagePathologie <$pathoUri> ;
                                    ex:aPourDateArchivage \"$now\"^^xsd:dateTime ;
                                    ex:aPourMotifArchivage \"$motifEsc\"@fr ;
                                    ex:estArchivageActif \"true\"^^xsd:boolean .
                    }";
                    sparqlUpdate($eventInsert);
                    $flash = ['type'=>'success','msg'=>'Pathologie archivée avec motif.'];
                } else {
                    $flash = ['type'=>'error','msg'=>'Erreur lors de l\'archivage.'];
                }
            }
        } elseif ($action === 'restore') {
            // Motif obligatoire pour la réactivation
            $motif = trim($_POST['motif'] ?? '');
            if ($motif === '') {
                $flash = ['type'=>'error','msg'=>'Le motif de réactivation est obligatoire.'];
            } else {
                // 1) Bouger la pathologie d'archivée vers active
                sparqlUpdate(sparqlPrefixes() . " DELETE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> } WHERE { <$patientUri> ex:aPourPathologieArchivee <$pathoUri> }");
                $res = sparqlUpdate(sparqlPrefixes() . " INSERT DATA { <$patientUri> ex:aPourPathologie <$pathoUri> }");

                // 2) Marquer le dernier événement d'archivage actif comme RÉACTIVÉ
                //    (on garde l'événement pour la traçabilité, on l'annote)
                if ($res['success']) {
                    $now = date('Y-m-d\TH:i:s');
                    $motifEsc = sparqlEscapeString($motif);
                    // Trouver le dernier archivage actif et le marquer comme désactivé
                    $reactivateUpdate = sparqlPrefixes() . "
                        DELETE { ?event ex:estArchivageActif ?old }
                        INSERT { ?event ex:estArchivageActif \"false\"^^xsd:boolean ;
                                       ex:aPourDateReactivation \"$now\"^^xsd:dateTime ;
                                       ex:aPourMotifReactivation \"$motifEsc\"@fr . }
                        WHERE {
                            ?event a ex:ArchivagePathologie ;
                                   ex:concerneArchivagePatient <$patientUri> ;
                                   ex:concerneArchivagePathologie <$pathoUri> ;
                                   ex:estArchivageActif ?old .
                            FILTER(?old = \"true\"^^xsd:boolean)
                        }";
                    sparqlUpdate($reactivateUpdate);
                    $flash = ['type'=>'success','msg'=>'Pathologie réactivée avec motif.'];
                } else {
                    $flash = ['type'=>'error','msg'=>'Erreur lors de la réactivation.'];
                }
            }
        }
    }
}

if (isset($_GET['consult']) && $_GET['consult'] === '1') {
    $checkedPathos = $_GET['pathos'] ?? [];
    if (!is_array($checkedPathos)) $checkedPathos = [$checkedPathos];
    $checkedPathos = array_values(array_filter($checkedPathos, fn($v) => is_string($v) && $v !== ''));

    // NOUVEAU : récupérer les pathos exclues avec leurs motifs
    // Format : excluded_pathos[<uri>] = "motif texte"
    $excludedWithMotif = $_GET['excluded_pathos'] ?? [];
    if (!is_array($excludedWithMotif)) $excludedWithMotif = [];

    if (!empty($checkedPathos)) {
        $vRows = sparqlQueryPD(sparqlPrefixes() . "
            SELECT ?nom ?prenom ?age ?dossier ?genreLabel WHERE {
                <$patientUri> a ex:Patient .
                OPTIONAL { <$patientUri> ex:aPourNom ?nom }
                OPTIONAL { <$patientUri> ex:aPourPrenom ?prenom }
                OPTIONAL { <$patientUri> ex:aPourAge ?age }
                OPTIONAL { <$patientUri> ex:aPourNumeroDossier ?dossier }
                OPTIONAL { <$patientUri> ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
            }");
        if (!empty($vRows)) {
            $b = $vRows[0];
            $_SESSION['patient_uri']      = $patientUri;
            $_SESSION['patient_fragment'] = $id;
            $_SESSION['patient_nom']      = $b['nom']['value'] ?? '';
            $_SESSION['patient_prenom']   = $b['prenom']['value'] ?? '';
            $_SESSION['patient_age']      = $b['age']['value'] ?? '';
            $_SESSION['patient_dossier']  = $b['dossier']['value'] ?? '';
            $_SESSION['patient_genre']    = $b['genreLabel']['value'] ?? '';
        }
        // Pré-charger les pathologies sélectionnées dans la session
        $_SESSION['parcours_pathologies'] = $checkedPathos;

        // NOUVEAU : enregistrer les exclusions de pathos pour CETTE consultation
        // (événements ex:ExclusionPathologieConsultation - cohérent avec ex:ArchivagePathologie)
        // On stocke aussi en session pour pouvoir les rattacher à la prescription créée plus tard.
        $exclusionsForSession = [];
        if (!empty($excludedWithMotif)) {
            $patientLocal = (str_contains($patientUri, '#'))
                ? substr($patientUri, strrpos($patientUri, '#') + 1) : 'Patient';
            $now = date('Y-m-d\TH:i:s');
            foreach ($excludedWithMotif as $excludedUri => $motif) {
                $excludedUri = (string)$excludedUri;
                $motif       = trim((string)$motif);
                if ($excludedUri === '' || $motif === '') continue;
                if (!str_starts_with($excludedUri, ONTO_NAMESPACE)) continue; // sécurité

                $pathoLocal = (str_contains($excludedUri, '#'))
                    ? substr($excludedUri, strrpos($excludedUri, '#') + 1) : 'Patho';
                $eventFrag = "Exclusion_{$patientLocal}_{$pathoLocal}_" . date('YmdHis')
                              . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
                $eventUri  = ONTO_NAMESPACE . $eventFrag;
                $motifEsc  = sparqlEscapeString($motif);

                $insert = sparqlPrefixes() . " INSERT DATA {
                    <$eventUri> rdf:type owl:NamedIndividual ;
                                rdf:type ex:ExclusionPathologieConsultation ;
                                ex:concerneExclusionPatient <$patientUri> ;
                                ex:concerneExclusionPathologie <$excludedUri> ;
                                ex:aPourDateExclusion \"$now\"^^xsd:dateTime ;
                                ex:aPourMotifExclusion \"$motifEsc\"@fr .
                }";
                sparqlUpdate($insert);

                // Garder en session pour rattachement éventuel à la prescription
                $exclusionsForSession[] = [
                    'event_uri' => $eventUri,
                    'patho_uri' => $excludedUri,
                    'motif'     => $motif,
                ];
            }
        }
        $_SESSION['parcours_exclusions'] = $exclusionsForSession;

        header('Location: index.php?from_patient=' . urlencode($id));
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

// ─── Charger l'historique d'archivage avec motifs et dates (timeline) ─────
// Récupère tous les événements ex:ArchivagePathologie de ce patient,
// triés du plus récent au plus ancien.
$archivageEvents = [];
$histQuery = sparqlPrefixes() . "
    SELECT ?event ?patho ?dateArch ?motifArch ?actif ?dateReact ?motifReact WHERE {
        ?event a ex:ArchivagePathologie ;
               ex:concerneArchivagePatient <$patientUri> ;
               ex:concerneArchivagePathologie ?patho ;
               ex:aPourDateArchivage ?dateArch ;
               ex:aPourMotifArchivage ?motifArch .
        OPTIONAL { ?event ex:estArchivageActif ?actif }
        OPTIONAL { ?event ex:aPourDateReactivation ?dateReact }
        OPTIONAL { ?event ex:aPourMotifReactivation ?motifReact }
    }
    ORDER BY DESC(?dateArch)
";
foreach (sparqlQueryPD($histQuery) as $r) {
    $pathoUri  = $r['patho']['value'] ?? '';
    $pathoLocal= localNamePD($pathoUri);
    $isActive  = (($r['actif']['value'] ?? 'true') === 'true');
    $archivageEvents[] = [
        'pathoUri'    => $pathoUri,
        'pathoLabel'  => categoryTitlePD($pathoLocal),
        'dateArch'    => $r['dateArch']['value']    ?? '',
        'motifArch'   => $r['motifArch']['value']   ?? '',
        'isActive'    => $isActive, // true = encore archivé, false = a été réactivé
        'dateReact'   => $r['dateReact']['value']   ?? '',
        'motifReact'  => $r['motifReact']['value']  ?? '',
    ];
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
/* ═══════════════════════════════════════════════════════════════════════
   ONGLETS : système de tabs dynamique
   ═══════════════════════════════════════════════════════════════════════ */
.tabs-bar{position:sticky;top:0;z-index:30;background:#f4f7fb;
          padding:14px 0 6px;margin-bottom:18px;border-bottom:1px solid #e5e7eb}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tab{padding:10px 18px;border-radius:10px 10px 0 0;font-weight:600;font-size:13px;
     color:#64748b;background:transparent;border:none;cursor:pointer;
     font-family:inherit;transition:.15s;display:inline-flex;align-items:center;gap:8px;
     position:relative;border-bottom:3px solid transparent;margin-bottom:-1px}
.tab:hover{color:#1e293b;background:rgba(255,255,255,.6)}
.tab.active{color:#1d4ed8;background:#fff;border-bottom-color:#1d4ed8;
            box-shadow:0 -2px 8px rgba(15,23,42,.04)}
.tab-icon{font-size:15px;line-height:1}
.tab-count{font-size:11px;font-weight:700;background:#e5e7eb;color:#475569;
            border-radius:10px;padding:1px 8px;line-height:1.4}
.tab.active .tab-count{background:#dbeafe;color:#1d4ed8}

.tab-pane{display:none;animation:tabFadeIn .25s ease-out}
.tab-pane.active{display:block}
@keyframes tabFadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

/* Carte "résumé" en haut de l'onglet Vue d'ensemble : 3 mini-stats colorées */
.overview-mini-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.omini{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;
       border-left:4px solid;display:flex;flex-direction:column;gap:4px}
.omini-num{font-size:22px;font-weight:800;line-height:1}
.omini-lbl{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.omini-presc{border-left-color:#1d4ed8} .omini-presc .omini-num{color:#1d4ed8}
.omini-actives{border-left-color:#059669} .omini-actives .omini-num{color:#059669}
.omini-archives{border-left-color:#f59e0b} .omini-archives .omini-num{color:#b45309}
@media(max-width:700px){.overview-mini-stats{grid-template-columns:1fr}}

/* Onglet "Historique d'archivage" : timeline verticale */
.timeline{position:relative;padding-left:30px;margin-top:4px}
.timeline::before{content:"";position:absolute;left:11px;top:0;bottom:0;
                   width:2px;background:#e5e7eb;border-radius:1px}
.tl-item{position:relative;padding:0 0 18px 0}
.tl-item:last-child{padding-bottom:0}
.tl-dot{position:absolute;left:-22px;top:4px;width:14px;height:14px;border-radius:50%;
        border:3px solid #fff;box-shadow:0 0 0 2px}
.tl-dot-archive{background:#fbbf24;box-shadow:0 0 0 2px #fbbf24}
.tl-dot-restore{background:#10b981;box-shadow:0 0 0 2px #10b981}
.tl-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}
.tl-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
.tl-card-title{font-weight:700;color:#1e293b;font-size:14px}
.tl-card-date{font-size:11px;color:#94a3b8;font-weight:500;white-space:nowrap}
.tl-card-action{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
                 padding:2px 8px;border-radius:10px;margin-top:2px;display:inline-block}
.tl-action-archive{background:#fef3c7;color:#92400e;border:1px solid #fbbf24}
.tl-action-restore{background:#dcfce7;color:#065f46;border:1px solid #6ee7b7}
.tl-card-meta{margin-top:6px;font-size:12px;color:#475569;line-height:1.5}
.tl-card-meta strong{color:#1e293b;font-weight:600}
.tl-empty{text-align:center;padding:40px 20px;color:#94a3b8;font-style:italic}
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
            <?php if ($patient['age'] !== ''): ?><span> <?= h($patient['age']) ?> ans</span><?php endif; ?>
            <?php if ($patient['genre'] !== ''): ?><span> <?= h($patient['genre']) ?></span><?php endif; ?>
            <?php if ($patient['tranche'] !== ''): ?><span><?= h($patient['tranche']) ?></span><?php endif; ?>
            <?php if ($patient['dossier'] !== ''): ?><span class="dossier-pill"><?= h($patient['dossier']) ?></span><?php endif; ?>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- ═══════ BARRE D'ONGLETS ═══════ -->
    <div class="tabs-bar">
        <div class="tabs">
            <button type="button" class="tab active" data-tab="overview" onclick="showTab('overview')">
                <span class="tab-icon">🩺</span>
                <span>Vue d'ensemble</span>
            </button>
            <button type="button" class="tab" data-tab="prescriptions" onclick="showTab('prescriptions')">
                <span class="tab-icon">📋</span>
                <span>Prescriptions</span>
                <span class="tab-count"><?= count($prescriptions) ?></span>
            </button>
            <button type="button" class="tab" data-tab="archivage" onclick="showTab('archivage')">
                <span class="tab-icon">📁</span>
                <span>Archivage</span>
                <?php if (!empty($archivedPathos)): ?>
                    <span class="tab-count"><?= count($archivedPathos) ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- ═══════ ONGLET 1 : VUE D'ENSEMBLE ═══════ -->
    <div class="tab-pane active" id="tab-overview">

        <!-- Mini-stats récapitulatives -->
        <div class="overview-mini-stats">
            <div class="omini omini-presc">
                <div class="omini-num"><?= count($prescriptions) ?></div>
                <div class="omini-lbl">prescription<?= count($prescriptions) > 1 ? 's' : '' ?></div>
            </div>
            <div class="omini omini-actives">
                <div class="omini-num"><?= count($activePathos) ?></div>
                <div class="omini-lbl">patho. active<?= count($activePathos) > 1 ? 's' : '' ?></div>
            </div>
            <div class="omini omini-archives">
                <div class="omini-num"><?= count($archivedPathos) ?></div>
                <div class="omini-lbl">patho. archivée<?= count($archivedPathos) > 1 ? 's' : '' ?></div>
            </div>
        </div>

        <!-- CTA : Nouvelle consultation -->
        <?php if (!empty($activePathos) || !empty($archivedPathos)): ?>
        <div class="main-action">
            <div class="ma-text">
                <h2> Démarrer une nouvelle consultation</h2>
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
                                <button type="button" class="btn-mini" title="Archiver"
                                        onclick="openArchiveModal('<?= h($p['uri']) ?>', '<?= h($p['label']) ?>')">Archiver</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <button class="add-trigger" onclick="openModal('modal-add')">+ Ajouter une pathologie</button>

                <?php if (!empty($archivedPathos)): ?>
                    <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #e5e7eb;
                                text-align:center;font-size:12px;color:#94a3b8">
                        💡 <strong><?= count($archivedPathos) ?></strong> pathologie<?= count($archivedPathos) > 1 ? 's' : '' ?>
                        archivée<?= count($archivedPathos) > 1 ? 's' : '' ?> —
                        <a href="javascript:void(0)" onclick="showTab('archivage')" style="color:#1d4ed8;font-weight:600">voir l'historique</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- ═══════ /ONGLET 1 ═══════ -->

    <!-- ═══════ ONGLET 2 : PRESCRIPTIONS ═══════ -->
    <div class="tab-pane" id="tab-prescriptions">
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
    <!-- ═══════ /ONGLET 2 ═══════ -->

    <!-- ═══════ ONGLET 3 : ARCHIVAGE (timeline historique avec motifs) ═══════ -->
    <div class="tab-pane" id="tab-archivage">
        <div class="card">
            <div class="card-head">
                <h2>📁 Historique d'archivage <span class="count">· <?= count($archivageEvents) ?> événement<?= count($archivageEvents) > 1 ? 's' : '' ?></span></h2>
            </div>

            <?php if (empty($archivageEvents) && empty($archivedPathos)): ?>
                <div class="tl-empty">
                    Aucune pathologie archivée pour ce patient.<br>
                    L'historique des archivages et réactivations apparaîtra ici.
                </div>
            <?php else: ?>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;line-height:1.6">
                    Historique complet des archivages et réactivations de pathologies pour ce patient,
                    du plus récent au plus ancien. Chaque événement est tracé avec sa date et son motif.
                </p>

                <div class="timeline">
                    <?php
                    // Affiche d'abord les pathos archivées sans événement (ancien format)
                    $eventsByPatho = [];
                    foreach ($archivageEvents as $ev) {
                        $eventsByPatho[$ev['pathoUri']][] = $ev;
                    }
                    foreach ($archivedPathos as $p):
                        if (!isset($eventsByPatho[$p['uri']])): ?>
                            <div class="tl-item">
                                <div class="tl-dot tl-dot-archive"></div>
                                <div class="tl-card">
                                    <div class="tl-card-head">
                                        <div>
                                            <div class="tl-card-title"><?= h($p['label']) ?></div>
                                            <span class="tl-card-action tl-action-archive">Archivée (sans motif)</span>
                                        </div>
                                        <button type="button" class="btn-mini ok"
                                                onclick="openRestoreModal('<?= h($p['uri']) ?>', '<?= h($p['label']) ?>')">
                                            Réactiver →
                                        </button>
                                    </div>
                                    <div class="tl-card-meta" style="color:#94a3b8;font-style:italic">
                                        Archivage ancien (avant l'ajout du suivi des motifs).
                                    </div>
                                </div>
                            </div>
                        <?php endif;
                    endforeach;

                    // Puis les événements complets, triés du plus récent au plus ancien
                    foreach ($archivageEvents as $ev):
                        $stillArchived = $ev['isActive'];
                        $dotClass = $stillArchived ? 'tl-dot-archive' : 'tl-dot-restore';
                        $actionLbl = $stillArchived ? 'Archivée' : 'Réactivée';
                        $actionCss = $stillArchived ? 'tl-action-archive' : 'tl-action-restore';
                    ?>
                        <div class="tl-item">
                            <div class="tl-dot <?= $dotClass ?>"></div>
                            <div class="tl-card">
                                <div class="tl-card-head">
                                    <div>
                                        <div class="tl-card-title"><?= h($ev['pathoLabel']) ?></div>
                                        <span class="tl-card-action <?= $actionCss ?>"><?= $actionLbl ?></span>
                                    </div>
                                    <?php if ($stillArchived): ?>
                                        <button type="button" class="btn-mini ok"
                                                onclick="openRestoreModal('<?= h($ev['pathoUri']) ?>', '<?= h($ev['pathoLabel']) ?>')">
                                            Réactiver →
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="tl-card-meta">
                                    <strong>📅 Archivée le</strong> <?= h(formatDatePD($ev['dateArch'])) ?><br>
                                    <strong>📝 Motif :</strong> <em><?= h($ev['motifArch']) ?: '—' ?></em>
                                    <?php if (!$stillArchived && $ev['dateReact'] !== ''): ?>
                                        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e5e7eb">
                                            <strong style="color:#047857">↻ Réactivée le</strong> <?= h(formatDatePD($ev['dateReact'])) ?><br>
                                            <strong style="color:#047857">📝 Motif :</strong> <em><?= h($ev['motifReact']) ?: '—' ?></em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- ═══════ /ONGLET 3 ═══════ -->

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

<!-- Modal Archiver une pathologie (avec motif obligatoire) -->
<div class="modal-overlay" id="modal-archive">
    <div class="modal" style="max-width:520px">
        <div class="modal-head">
            <h2>📦 Archiver une pathologie</h2>
            <p>Précisez le motif de l'archivage pour traçabilité.</p>
        </div>
        <form method="post" id="form-archive" style="display:flex;flex-direction:column;flex:1">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="patho" id="archive-patho-uri">
            <div class="modal-body" style="padding:20px 24px">
                <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;
                            padding:12px 14px;margin-bottom:16px;color:#78350f;font-size:13px;line-height:1.5">
                    Vous êtes sur le point d'archiver <strong id="archive-patho-name">...</strong>.
                    Cette pathologie ne sera plus prise en compte dans les futures prescriptions
                    mais restera accessible dans l'historique.
                </div>
                <label style="display:block;font-size:13px;font-weight:600;color:#1e293b;margin-bottom:6px">
                    Motif de l'archivage <span style="color:#dc2626">*</span>
                </label>
                <textarea name="motif" id="archive-motif" rows="3" required
                          placeholder="Ex : Rémission complète depuis 2 ans, scanner négatif..."
                          style="width:100%;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;
                                 font-size:14px;font-family:inherit;resize:vertical;line-height:1.5"></textarea>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px;font-style:italic">
                    Ce motif sera conservé dans le dossier patient.
                </div>
            </div>
            <div class="modal-foot" style="justify-content:space-between;padding:16px 24px;border-top:1px solid #e5e7eb">
                <button type="button" class="modal-close" onclick="closeModal('modal-archive')">Annuler</button>
                <button type="submit" class="btn-add" style="background:#d97706;border-color:#d97706;color:#fff">
                    📦 Confirmer l'archivage
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Réactiver une pathologie (avec motif obligatoire) -->
<div class="modal-overlay" id="modal-restore">
    <div class="modal" style="max-width:520px">
        <div class="modal-head">
            <h2>↻ Réactiver une pathologie</h2>
            <p>Précisez le motif de la réactivation pour traçabilité.</p>
        </div>
        <form method="post" id="form-restore" style="display:flex;flex-direction:column;flex:1">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="patho" id="restore-patho-uri">
            <div class="modal-body" style="padding:20px 24px">
                <div style="background:#dcfce7;border:1px solid #6ee7b7;border-radius:10px;
                            padding:12px 14px;margin-bottom:16px;color:#065f46;font-size:13px;line-height:1.5">
                    Vous êtes sur le point de réactiver <strong id="restore-patho-name">...</strong>.
                    Cette pathologie redeviendra active et sera prise en compte dans les prescriptions.
                </div>
                <label style="display:block;font-size:13px;font-weight:600;color:#1e293b;margin-bottom:6px">
                    Motif de la réactivation <span style="color:#dc2626">*</span>
                </label>
                <textarea name="motif" id="restore-motif" rows="3" required
                          placeholder="Ex : Récidive détectée lors du dernier bilan..."
                          style="width:100%;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;
                                 font-size:14px;font-family:inherit;resize:vertical;line-height:1.5"></textarea>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px;font-style:italic">
                    Ce motif sera conservé dans le dossier patient.
                </div>
            </div>
            <div class="modal-foot" style="justify-content:space-between;padding:16px 24px;border-top:1px solid #e5e7eb">
                <button type="button" class="modal-close" onclick="closeModal('modal-restore')">Annuler</button>
                <button type="submit" class="btn-add" style="background:#059669;border-color:#059669;color:#fff">
                    ↻ Confirmer la réactivation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Consultation -->
<div class="modal-overlay" id="modal-consult">
    <div class="modal">
        <div class="modal-head">
            <h2>Nouvelle consultation</h2>
            <p>Sélectionnez les pathologies à prendre en compte.</p>
        </div>
        <form method="get" id="form-consult" onsubmit="return handleConsultSubmit(event)"
              style="display:flex;flex-direction:column;flex:1;overflow:hidden">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <input type="hidden" name="consult" value="1">
            <!-- Container où JS injectera les motifs d'exclusion juste avant le submit final -->
            <div id="exclusion-hidden-inputs"></div>
            <div class="modal-body">
                <div class="consult-list">
                    <?php foreach ($activePathos as $p): ?>
                        <label class="consult-row">
                            <input type="checkbox" name="pathos[]" value="<?= h($p['uri']) ?>"
                                   data-label="<?= h($p['label']) ?>"
                                   data-active="1"
                                   checked>
                            <span><?= h($p['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php foreach ($archivedPathos as $p): ?>
                        <label class="consult-row archived">
                            <input type="checkbox" name="pathos[]" value="<?= h($p['uri']) ?>"
                                   data-label="<?= h($p['label']) ?>"
                                   data-active="0">
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

<!-- Modal Motifs d'exclusion (apparaît si au moins une patho active a été décochée) -->
<div class="modal-overlay" id="modal-exclusion-motifs">
    <div class="modal" style="max-width:580px">
        <div class="modal-head">
            <h2>📝 Motifs d'exclusion</h2>
            <p>Vous avez décoché certaines pathologies actives. Précisez le motif pour chacune.</p>
        </div>
        <div class="modal-body" style="padding:20px 24px">
            <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;
                        padding:12px 14px;margin-bottom:18px;color:#78350f;font-size:13px;line-height:1.5">
                💡 <strong>Astuce</strong> : si plusieurs pathologies ont le même motif,
                tapez-le dans le premier champ — les autres seront automatiquement pré-remplies
                (vous pourrez ensuite les modifier individuellement).
            </div>
            <div id="exclusion-motifs-list"></div>
        </div>
        <div class="modal-foot" style="justify-content:space-between;padding:16px 24px;border-top:1px solid #e5e7eb">
            <button type="button" class="modal-close" onclick="closeModal('modal-exclusion-motifs')">Retour</button>
            <button type="button" class="btn-add" id="btn-confirm-exclusions"
                    onclick="confirmExclusionsAndSubmit()"
                    style="background:#2563eb;border-color:#2563eb;color:#fff">
                Valider et démarrer →
            </button>
        </div>
    </div>
</div>

<script>
// ─── Système d'onglets ─────────────────────────────────────────
function showTab(id) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + id)?.classList.add('active');
    document.querySelector(`.tab[data-tab="${id}"]`)?.classList.add('active');
    // Persister l'onglet actif dans l'URL (deep linking)
    if (history.replaceState) {
        const url = new URL(window.location);
        url.hash = id;
        history.replaceState(null, '', url);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Au chargement, ouvrir l'onglet depuis l'URL (#prescriptions, #archivage)
window.addEventListener('DOMContentLoaded', () => {
    const hash = (window.location.hash || '').replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
        showTab(hash);
    }
});

function openModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';
    const s=document.querySelector('#'+id+' input[type=text],#'+id+' textarea');if(s)setTimeout(()=>s.focus(),50);}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(o=>closeModal(o.id));});
function toggleSection(b){b.classList.toggle('open');b.nextElementSibling.classList.toggle('open');}
function filterPathos(){const t=document.getElementById('patho-search').value.toLowerCase().trim();
    document.querySelectorAll('#add-list .modal-item').forEach(i=>{
        const l=i.getAttribute('data-label')||'';
        i.style.display=(t===''||l.includes(t))?'flex':'none';});}

// ─── Modales d'archivage/réactivation avec motif obligatoire ───
function openArchiveModal(pathoUri, pathoLabel) {
    document.getElementById('archive-patho-uri').value = pathoUri;
    document.getElementById('archive-patho-name').textContent = pathoLabel;
    document.getElementById('archive-motif').value = '';
    openModal('modal-archive');
}
function openRestoreModal(pathoUri, pathoLabel) {
    document.getElementById('restore-patho-uri').value = pathoUri;
    document.getElementById('restore-patho-name').textContent = pathoLabel;
    document.getElementById('restore-motif').value = '';
    openModal('modal-restore');
}

// ─── Modale "Nouvelle consultation" : interception du submit pour motifs d'exclusion ───
let _consultExclusions = []; // [{uri, label}, ...]

function handleConsultSubmit(ev) {
    // Trouver toutes les pathos ACTIVES qui ont été DÉCOCHÉES par l'utilisateur
    const checkboxes = document.querySelectorAll('#modal-consult input[name="pathos[]"]');
    const excluded = [];
    checkboxes.forEach(cb => {
        if (cb.dataset.active === '1' && !cb.checked) {
            excluded.push({
                uri:   cb.value,
                label: cb.dataset.label || cb.value
            });
        }
    });

    // Aucune patho décochée → submit direct (comportement normal)
    if (excluded.length === 0) return true;

    // Au moins une décochée → on bloque le submit et on ouvre la modale des motifs
    ev.preventDefault();
    _consultExclusions = excluded;
    buildExclusionMotifsForm(excluded);
    closeModal('modal-consult');
    openModal('modal-exclusion-motifs');
    return false;
}

// Construit dynamiquement les textareas pour saisir les motifs (un par patho exclue)
function buildExclusionMotifsForm(excluded) {
    const list = document.getElementById('exclusion-motifs-list');
    list.innerHTML = '';
    excluded.forEach((ex, idx) => {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'margin-bottom:16px;padding:14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px';
        wrap.innerHTML = `
            <label style="display:flex;align-items:center;gap:8px;font-weight:700;color:#1e293b;font-size:13px;margin-bottom:8px">
                <span style="background:#fef3c7;color:#92400e;border:1px solid #fbbf24;font-size:10px;padding:2px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:.4px">Exclue</span>
                <span>${escapeHtml(ex.label)}</span>
            </label>
            <textarea data-uri="${escapeHtml(ex.uri)}" data-idx="${idx}" rows="2" required
                      placeholder="Pourquoi cette pathologie n'est-elle pas prise en compte cette fois ?"
                      class="exclusion-motif-input"
                      style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;resize:vertical;line-height:1.5"></textarea>
        `;
        list.appendChild(wrap);
    });

    // Auto-pré-remplissage : quand on tape dans le premier, les autres se remplissent
    // (en gardant la possibilité de les modifier indépendamment ensuite)
    const inputs = list.querySelectorAll('.exclusion-motif-input');
    if (inputs.length > 1) {
        inputs[0].addEventListener('input', function() {
            const val = this.value;
            for (let i = 1; i < inputs.length; i++) {
                // Seulement pour les autres champs encore "vierges" (non touchés manuellement)
                if (!inputs[i].dataset.touched) {
                    inputs[i].value = val;
                }
            }
        });
        // Marquer un champ comme "touché" dès que l'utilisateur le modifie manuellement
        for (let i = 1; i < inputs.length; i++) {
            inputs[i].addEventListener('input', function() { this.dataset.touched = '1'; });
        }
    }

    // Focus auto sur le premier
    setTimeout(() => inputs[0]?.focus(), 60);
}

// Valide les motifs et soumet le formulaire principal
function confirmExclusionsAndSubmit() {
    const inputs = document.querySelectorAll('#exclusion-motifs-list .exclusion-motif-input');
    const container = document.getElementById('exclusion-hidden-inputs');
    container.innerHTML = '';

    let allValid = true;
    inputs.forEach(textarea => {
        const motif = textarea.value.trim();
        if (motif === '') {
            allValid = false;
            textarea.style.borderColor = '#dc2626';
            textarea.style.background = '#fef2f2';
        } else {
            textarea.style.borderColor = '#cbd5e1';
            textarea.style.background = '#fff';
            // Injection dans le form principal : excluded_pathos[<uri>] = motif
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `excluded_pathos[${textarea.dataset.uri}]`;
            input.value = motif;
            container.appendChild(input);
        }
    });

    if (!allValid) {
        alert("Veuillez renseigner un motif pour chaque pathologie exclue.");
        return;
    }

    // Tout est OK : on soumet le formulaire principal
    closeModal('modal-exclusion-motifs');
    document.getElementById('form-consult').submit();
}

// Helper : échappe les caractères HTML pour éviter les injections
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}
</script>

</body>
</html>
