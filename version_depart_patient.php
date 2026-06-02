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
$hasContext = !empty($parcoursPathos);

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

$patients = [];
$listRes = sparqlQueryRead(sparqlPrefixes() . "
    SELECT ?uri ?nom ?prenom ?age ?dossier ?genreLabel WHERE {
        ?uri a ex:Patient .
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $hasContext ? 'Attribuer la prescription' : 'Patients' ?> · APA4CAD</title>
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

    <section class="banner">
        <div class="crumbs">
            <?php if ($hasContext): ?>
                <a href="index.php">Parcours</a><span class="sep">›</span>
                <a href="rapport.php">Rapport</a><span class="sep">›</span>
                <span>Attribuer</span>
            <?php else: ?>
                <span>Patients</span>
            <?php endif; ?>
        </div>
        <h1><?= $hasContext ? '👤 Attribuer la prescription' : '👥 Patients' ?></h1>
        <div class="subtitle">
            <?= $hasContext
                ? 'Étape 3/5 — Associez la prescription à un patient existant ou nouveau.'
                : 'Consultez et gérez les dossiers patients enregistrés.' ?>
        </div>
    </section>

    <?php if ($hasContext): ?>
        <div class="context-banner">
            <span class="ctx-icon"></span>
            <div class="ctx-text">
                <strong>Prescription en cours :</strong>
                <?= count($parcoursPathos) ?> pathologie<?= count($parcoursPathos) > 1 ? 's' : '' ?>,
                <?= count($parcoursActivites) ?> activité<?= count($parcoursActivites) > 1 ? 's' : '' ?> recommandée<?= count($parcoursActivites) > 1 ? 's' : '' ?><?php
                if (count($parcoursCI) > 0) echo ", <strong style=\"color:#b91c1c\">" . count($parcoursCI) . " contre-indication" . (count($parcoursCI) > 1 ? 's' : '') . "</strong>";
                ?>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="layout-2">

        <div class="card">
            <div class="card-head">
                <h2>Patients existants <span class="count">· <?= count($patients) ?></span></h2>
            </div>

            <form method="get" class="search-row">
                <input type="text" name="q" placeholder="Rechercher par nom, prénom ou dossier..."
                       value="<?= h($searchTerm) ?>" autofocus>
                <?php if ($searchTerm !== ''): ?>
                    <a href="patient.php" class="clear-btn">✕</a>
                <?php endif; ?>
            </form>

            <?php if (empty($patients)): ?>
                <div class="empty">
                    <?= $searchTerm !== '' ? 'Aucun résultat pour « ' . h($searchTerm) . ' ».' : 'Aucun patient enregistré. Créez-en un avec le formulaire à droite.' ?>
                </div>
            <?php else: ?>
                <div class="patient-list">
                    <?php foreach ($patients as $p):
                        $displayName = trim($p['prenom'] . ' ' . $p['nom']) ?: '(' . $p['fragment'] . ')';
                    ?>
                        <div class="patient-row">
                            <div class="info">
                                <div class="name"><?= h($displayName) ?></div>
                                <div class="meta">
                                    <?php if ($p['dossier'] !== ''): ?><span class="dossier"><?= h($p['dossier']) ?></span><?php endif; ?>
                                    <?php if ($p['age'] !== ''): ?><span><?= h($p['age']) ?> ans</span><?php endif; ?>
                                    <?php if ($p['genre'] !== ''): ?><span><?= h($p['genre']) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($hasContext): ?>
                                <a href="patient.php?select=<?= urlencode($p['fragment']) ?>" class="btn btn-primary">
                                    Attribuer →
                                </a>
                            <?php else: ?>
                                <a href="patient_detail.php?id=<?= urlencode($p['fragment']) ?>" class="btn btn-primary">
                                    Ouvrir →
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>+ Nouveau patient</h2>
            </div>

            <form method="post" class="create-form">
                <input type="hidden" name="action" value="create">

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

                <button type="submit" class="btn-submit">
                    <?= $hasContext ? 'Créer et attribuer →' : 'Créer le patient →' ?>
                </button>
            </form>
        </div>
    </div>

</div>

<script>
function formatName(s){if(!s)return'';return s.toLowerCase().replace(/(^|[\s\-'])([a-zà-ÿ])/g,(m,sep,l)=>sep+l.toUpperCase());}
['prenom','nom'].forEach(n=>{const f=document.querySelector(`input[name="${n}"]`);if(f)f.addEventListener('blur',function(){this.value=formatName(this.value);});});
</script>

</body>
</html>
