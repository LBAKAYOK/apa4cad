<?php
/**
 * APA4CAD - Statistiques (page unifiée)
 *
 * 3 états selon l'utilisateur :
 *   1. Personne connecté → écran de choix "Praticien" ou "Admin"
 *   2. Praticien connecté → stats personnelles
 *   3. Admin connecté → stats globales avec filtre par praticien
 */

declare(strict_types=1);

require_once __DIR__ . '/sparql_update.php';
require_once __DIR__ . '/praticien_session.php';

$isAdmin     = !empty($_SESSION['apa4cad_admin_authenticated'] ?? false);
$isPraticien = isPraticienLoggedIn();

// ─── ÉTAT 1 : Choix initial (personne connecté) ────────────────────────
if (!$isAdmin && !$isPraticien) {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Statistiques · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
     font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:linear-gradient(135deg,#581c87 0%,#7c3aed 50%,#a855f7 100%);
     color:#1e293b;padding:24px}
body::before{content:"";position:fixed;inset:0;
              background-image:radial-gradient(circle at 25% 30%, rgba(255,255,255,.05) 0%, transparent 50%),
                                radial-gradient(circle at 75% 70%, rgba(255,255,255,.05) 0%, transparent 50%);
              pointer-events:none;z-index:0}

.wrap{max-width:780px;width:100%;position:relative;z-index:1}

.head{text-align:center;margin-bottom:36px;color:#fff}
.head-brand{display:inline-flex;align-items:center;gap:12px;
            background:rgba(255,255,255,.12);backdrop-filter:blur(10px);
            border:1px solid rgba(255,255,255,.18);
            padding:10px 18px;border-radius:50px;margin-bottom:22px;
            font-size:13px;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
.head-brand-icon{width:24px;height:24px;border-radius:6px;background:#fff;color:#7c3aed;
                  font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center}
.head h1{margin:0 0 12px;font-size:34px;font-weight:800;letter-spacing:-0.025em}
.head p{margin:0;font-size:15px;opacity:.9;max-width:520px;margin-left:auto;margin-right:auto;line-height:1.6}

.choice-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
@media(max-width:680px){.choice-grid{grid-template-columns:1fr}}

.choice-card{background:#fff;border-radius:20px;padding:32px 28px;text-decoration:none;color:inherit;
              display:flex;flex-direction:column;gap:14px;position:relative;overflow:hidden;
              transition:.3s cubic-bezier(.4,0,.2,1);box-shadow:0 18px 45px rgba(0,0,0,.2);
              border-top:6px solid;min-height:340px;cursor:pointer}
.choice-card:hover{transform:translateY(-8px);box-shadow:0 28px 60px rgba(0,0,0,.3)}
.choice-card::before{content:"";position:absolute;top:-60px;right:-60px;width:180px;height:180px;
                      border-radius:50%;opacity:.08;transition:.3s;pointer-events:none}
.choice-card:hover::before{transform:scale(1.3);opacity:.15}

.choice-icon{width:64px;height:64px;border-radius:16px;display:flex;align-items:center;justify-content:center;
              font-size:30px;color:#fff;box-shadow:0 10px 24px rgba(0,0,0,.18);transition:.3s}
.choice-card:hover .choice-icon{transform:scale(1.08) rotate(-3deg)}

.choice-card h2{margin:6px 0 0;font-size:22px;font-weight:800;color:#0f172a;letter-spacing:-.01em}
.choice-card p{margin:0;font-size:13px;color:#64748b;line-height:1.55;flex:1}

.choice-features{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px}
.choice-features li{font-size:12.5px;color:#475569;display:flex;align-items:center;gap:8px}
.choice-features li::before{content:"";width:6px;height:6px;border-radius:50%;flex-shrink:0}

.choice-cta{margin-top:auto;padding-top:14px;border-top:1px solid #f1f5f9;
            display:flex;justify-content:space-between;align-items:center;font-size:14px;font-weight:700}
.choice-arrow{transition:.2s;font-size:18px}
.choice-card:hover .choice-arrow{transform:translateX(6px)}

/* Card praticien (bleu) */
.choice-praticien{border-top-color:#1d4ed8}
.choice-praticien::before{background:#3b82f6}
.choice-praticien .choice-icon{background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.choice-praticien .choice-features li::before{background:#1d4ed8}
.choice-praticien .choice-cta{color:#1d4ed8}

/* Card admin (rouge) */
.choice-admin{border-top-color:#dc2626}
.choice-admin::before{background:#ef4444}
.choice-admin .choice-icon{background:linear-gradient(135deg,#dc2626,#b91c1c)}
.choice-admin .choice-features li::before{background:#dc2626}
.choice-admin .choice-cta{color:#dc2626}

.back-link{text-align:center;margin-top:28px}
.back-link a{color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:600}
.back-link a:hover{color:#fff;text-decoration:underline}
</style>
</head>
<body>

<div class="wrap">

    <div class="head">
        <div class="head-brand">
            <div class="head-brand-icon">A</div>
            APA4CAD
        </div>
        <h1>📊 Accès aux statistiques</h1>
        <p>Choisissez le type de statistiques que vous souhaitez consulter.</p>
    </div>

    <div class="choice-grid">

        <a href="login_praticien.php?redirect=stats" class="choice-card choice-praticien">
            <div class="choice-icon">👤</div>
            <h2>Praticien</h2>
            <p>Consulter vos statistiques personnelles : vos prescriptions, vos patients, votre activité.</p>
            <ul class="choice-features">
                <li>Mon nombre de prescriptions</li>
                <li>Mes patients suivis</li>
                <li>Évolution de mon activité</li>
                <li>Top pathologies prescrites</li>
                <li>Qualité de mes prescriptions</li>
            </ul>
            <div class="choice-cta">
                Mes statistiques <span class="choice-arrow">→</span>
            </div>
        </a>

        <a href="admin/login.php?redirect=stats" class="choice-card choice-admin">
            <div class="choice-icon">🛠</div>
            <h2>Administrateur</h2>
            <p>Accéder à toutes les statistiques du système, avec possibilité de filtrer par praticien.</p>
            <ul class="choice-features">
                <li>Toutes les prescriptions du système</li>
                <li>Comparaison entre praticiens</li>
                <li>Filtre par praticien spécifique</li>
                <li>Profil patientèle globale</li>
                <li>Indicateurs qualité système</li>
            </ul>
            <div class="choice-cta">
                Statistiques globales <span class="choice-arrow">→</span>
            </div>
        </a>

    </div>

    <div class="back-link">
        <a href="welcome.php">← Retour à l'accueil</a>
    </div>

</div>

</body>
</html>
<?php
    exit;
}

// ─── ÉTATS 2 & 3 : Stats personnelles ou globales ──────────────────────

// Quel praticien filtre-t-on ?
//  - Admin : il peut choisir dans le dropdown (param ?praticien=URI)
//  - Praticien : forcé sur lui-même
$selectedPraticienUri = '';
$mode = ''; // 'admin_global', 'admin_specific', 'praticien'

if ($isAdmin) {
    $selectedPraticienUri = trim((string)($_GET['praticien'] ?? ''));
    $mode = ($selectedPraticienUri !== '') ? 'admin_specific' : 'admin_global';
} else {
    $selectedPraticienUri = currentPraticienUri();
    $mode = 'praticien';
}

// Filtre période
$period = (string)($_GET['period'] ?? 'all');
$periodFilter = '';
if ($period === 'week') {
    $start = (new DateTime('monday this week'))->format('Y-m-d');
    $periodFilter = "FILTER(?date >= \"$start" . "T00:00:00\"^^xsd:dateTime)";
    $periodLabel = 'cette semaine';
} elseif ($period === 'month') {
    $start = (new DateTime('first day of this month'))->format('Y-m-d');
    $periodFilter = "FILTER(?date >= \"$start" . "T00:00:00\"^^xsd:dateTime)";
    $periodLabel = 'ce mois';
} elseif ($period === 'year') {
    $start = (new DateTime())->format('Y') . '-01-01';
    $periodFilter = "FILTER(?date >= \"$start" . "T00:00:00\"^^xsd:dateTime)";
    $periodLabel = 'cette année';
} else {
    $periodLabel = 'depuis le début';
}

// Construire le filtre praticien
$pratFilter = '';
$pratFilterPatient = '';
if ($selectedPraticienUri !== '') {
    $uri = '<' . str_replace('>', '', $selectedPraticienUri) . '>';
    $pratFilter = " ; ex:prescritPar $uri";
    $pratFilterPatient = "
        { ?pat ex:creePar $uri }
        UNION
        { ?presc a ex:Prescription ; ex:concerne ?pat ; ex:prescritPar $uri }";
}

// ─── HELPERS ─────────────────────────────────────────────────────────────
function sparqlSU(string $query): array {
    $url = FUSEKI_QUERY_ENDPOINT . '?query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp ?: '{}', true);
    return $d['results']['bindings'] ?? [];
}
function hSU(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function prettyUriSU(string $u): string {
    $base = strrpos($u, '#') !== false ? substr($u, strrpos($u, '#') + 1) : $u;
    $base = preg_replace('/^(Activite_|Frein_|Levier_)/', '', $base);
    return ucfirst(preg_replace('/_+/', ' ', $base));
}

// ─── STATS ACTIVITÉ ─────────────────────────────────────────────────────
$totalAll = (int)(sparqlSU(sparqlPrefixes() . "
    SELECT (COUNT(?p) AS ?n) WHERE {
        ?p a ex:Prescription $pratFilter .
    }
")[0]['n']['value'] ?? 0);

$totalPeriod = (int)(sparqlSU(sparqlPrefixes() . "
    SELECT (COUNT(?p) AS ?n) WHERE {
        ?p a ex:Prescription $pratFilter ; ex:aPourDate ?date .
        $periodFilter
    }
")[0]['n']['value'] ?? 0);

// Nb patients
if ($selectedPraticienUri !== '') {
    $uri = '<' . str_replace('>', '', $selectedPraticienUri) . '>';
    $totalPatients = (int)(sparqlSU(sparqlPrefixes() . "
        SELECT (COUNT(DISTINCT ?pat) AS ?n) WHERE {
            ?pat a ex:Patient .
            { ?pat ex:creePar $uri }
            UNION
            { ?presc a ex:Prescription ; ex:concerne ?pat ; ex:prescritPar $uri }
        }
    ")[0]['n']['value'] ?? 0);
} else {
    $totalPatients = (int)(sparqlSU(sparqlPrefixes() . "
        SELECT (COUNT(DISTINCT ?pat) AS ?n) WHERE { ?pat a ex:Patient }
    ")[0]['n']['value'] ?? 0);
}

// Activités moyennes par prescription
$avgRaw = sparqlSU(sparqlPrefixes() . "
    SELECT (AVG(?nbA) AS ?moy) WHERE {
        {
            SELECT ?p (COUNT(?a) AS ?nbA) WHERE {
                ?p a ex:Prescription $pratFilter .
                OPTIONAL { ?p ex:contient ?a }
            }
            GROUP BY ?p
        }
    }
");
$avgActs = (float)($avgRaw[0]['moy']['value'] ?? 0);

// ─── ÉVOLUTION 30 JOURS ─────────────────────────────────────────────────
$daysData = array_fill(0, 30, 0);
$daysLabels = [];
for ($i = 29; $i >= 0; $i--) {
    $daysLabels[] = (new DateTime())->modify("-$i days")->format('d/m');
}
$evoRows = sparqlSU(sparqlPrefixes() . "
    SELECT ?date WHERE { ?p a ex:Prescription $pratFilter ; ex:aPourDate ?date }
");
$thirtyDaysAgo = (new DateTime())->modify('-29 days')->format('Y-m-d');
foreach ($evoRows as $r) {
    $d = substr($r['date']['value'] ?? '', 0, 10);
    if ($d < $thirtyDaysAgo) continue;
    $diff = (new DateTime($d))->diff(new DateTime('today'))->days;
    if ($diff <= 29) $daysData[29 - $diff]++;
}
$maxDay = max($daysData) ?: 1;

// ─── TOP PATHOLOGIES & ACTIVITÉS ────────────────────────────────────────
$topPatho = sparqlSU(sparqlPrefixes() . "
    SELECT ?patho (COUNT(?presc) AS ?n) WHERE {
        ?presc a ex:Prescription $pratFilter ; ex:concerne ?pat .
        ?pat ex:aPourPathologie ?patho .
        OPTIONAL { ?presc ex:aPourDate ?date }
        $periodFilter
    } GROUP BY ?patho ORDER BY DESC(?n) LIMIT 5
");
$topActs = sparqlSU(sparqlPrefixes() . "
    SELECT ?actType (COUNT(?presc) AS ?n) WHERE {
        ?presc a ex:Prescription $pratFilter ; ex:contient ?act .
        ?act a ?actType .
        FILTER(?actType != owl:NamedIndividual && STRSTARTS(STR(?actType), \"" . ONTO_NAMESPACE . "\"))
        OPTIONAL { ?presc ex:aPourDate ?date }
        $periodFilter
    } GROUP BY ?actType ORDER BY DESC(?n) LIMIT 5
");
$maxPatho = !empty($topPatho) ? (int)($topPatho[0]['n']['value'] ?? 1) : 1;
$maxActs  = !empty($topActs)  ? (int)($topActs[0]['n']['value']  ?? 1) : 1;

// ─── PROFIL PATIENTÈLE ──────────────────────────────────────────────────
$patQuery = sparqlPrefixes() . "
    SELECT DISTINCT ?pat ?age ?genre WHERE {
        ?pat a ex:Patient .
        $pratFilterPatient
        OPTIONAL { ?pat ex:aPourAge ?age }
        OPTIONAL { ?pat ex:aPourGenre ?genre }
    }
";
$ages = []; $hommes = 0; $femmes = 0;
$tranchesAge = ['<30' => 0, '30-49' => 0, '50-69' => 0, '70+' => 0];
foreach (sparqlSU($patQuery) as $r) {
    $age = (int)($r['age']['value'] ?? 0);
    if ($age > 0) {
        $ages[] = $age;
        if ($age < 30) $tranchesAge['<30']++;
        elseif ($age < 50) $tranchesAge['30-49']++;
        elseif ($age < 70) $tranchesAge['50-69']++;
        else $tranchesAge['70+']++;
    }
    $g = strtolower($r['genre']['value'] ?? '');
    if (str_contains($g, 'masculin') || str_contains($g, 'homme')) $hommes++;
    elseif (str_contains($g, 'feminin') || str_contains($g, 'féminin') || str_contains($g, 'femme')) $femmes++;
}
$avgAge = count($ages) > 0 ? round(array_sum($ages) / count($ages)) : 0;
$totalGenres = $hommes + $femmes;
$pctH = $totalGenres > 0 ? round(($hommes / $totalGenres) * 100) : 0;
$pctF = $totalGenres > 0 ? round(($femmes / $totalGenres) * 100) : 0;
$maxAge = max(array_values($tranchesAge)) ?: 1;

// ─── QUALITÉ ────────────────────────────────────────────────────────────
$qRows = sparqlSU(sparqlPrefixes() . "
    SELECT ?presc
           (SUM(IF(STRSTARTS(STR(?comm), \"[FREIN]\"), 1, 0)) AS ?nbFreins)
           (SUM(IF(STRSTARTS(STR(?comm), \"[LEVIER]\"), 1, 0)) AS ?nbLeviers)
           (SUM(IF(!STRSTARTS(STR(?comm), \"[\"), 1, 0)) AS ?hasResume)
    WHERE {
        ?presc a ex:Prescription $pratFilter .
        OPTIONAL { ?presc rdfs:comment ?comm . FILTER(lang(?comm) = \"fr\") }
        OPTIONAL { ?presc ex:aPourDate ?date }
        $periodFilter
    } GROUP BY ?presc
");
$totalQ = count($qRows);
$nbWithFreins = 0; $nbWithLeviers = 0; $nbWithResume = 0;
foreach ($qRows as $r) {
    if ((int)($r['nbFreins']['value']  ?? 0) > 0) $nbWithFreins++;
    if ((int)($r['nbLeviers']['value'] ?? 0) > 0) $nbWithLeviers++;
    if ((int)($r['hasResume']['value'] ?? 0) > 0) $nbWithResume++;
}
$pctFreins  = $totalQ > 0 ? round(($nbWithFreins  / $totalQ) * 100) : 0;
$pctLeviers = $totalQ > 0 ? round(($nbWithLeviers / $totalQ) * 100) : 0;
$pctResume  = $totalQ > 0 ? round(($nbWithResume  / $totalQ) * 100) : 0;

// ─── Liste des praticiens (pour dropdown admin) ─────────────────────────
$allPraticiens = [];
if ($isAdmin) {
    foreach (sparqlSU(sparqlPrefixes() . "
        SELECT ?uri ?prenom ?nom WHERE {
            ?uri a ex:Praticien .
            OPTIONAL { ?uri ex:aPourPrenom ?prenom }
            OPTIONAL { ?uri ex:aPourNom    ?nom }
        } ORDER BY ?nom ?prenom
    ") as $r) {
        $allPraticiens[] = [
            'uri'    => $r['uri']['value']    ?? '',
            'prenom' => $r['prenom']['value'] ?? '',
            'nom'    => $r['nom']['value']    ?? '',
        ];
    }
}

// Sous-titre dynamique selon le mode
if ($mode === 'praticien') {
    $modeTitle = 'Mes statistiques';
    $modeSub   = 'Vue de votre activité, ' . $periodLabel . '.';
    $modeBadge = '👤 Praticien';
} elseif ($mode === 'admin_specific') {
    $pratName = '';
    foreach ($allPraticiens as $p) {
        if ($p['uri'] === $selectedPraticienUri) {
            $pratName = trim($p['prenom'] . ' ' . $p['nom']);
            break;
        }
    }
    $modeTitle = 'Statistiques de ' . $pratName;
    $modeSub   = 'Vue filtrée pour ce praticien, ' . $periodLabel . '.';
    $modeBadge = '🛠 Admin · vue praticien';
} else {
    $modeTitle = 'Statistiques globales';
    $modeSub   = 'Vue d\'ensemble du système, ' . $periodLabel . '.';
    $modeBadge = '🛠 Admin · vue système';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Statistiques · APA4CAD</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}

/* Topbar minimaliste */
.stats-topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:11px 0;
              box-shadow:0 1px 4px rgba(15,23,42,.04);position:sticky;top:0;z-index:500}
.stats-topbar-inner{max-width:1300px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:16px}
.stats-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:#0f172a;font-weight:700;font-size:16px}
.stats-logo-icon{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,#a855f7);
                color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px}
.stats-badge{background:#f3e8ff;color:#5b21b6;font-size:10px;font-weight:800;
             padding:3px 9px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px;border:1px solid #d8b4fe}
.stats-spacer{flex:1}
.stats-home-btn{background:linear-gradient(135deg,#10b981,#059669);color:#fff;
                padding:8px 16px;border-radius:9px;text-decoration:none;font-weight:700;font-size:13px;
                display:flex;align-items:center;gap:7px;box-shadow:0 4px 10px rgba(5,150,105,.3)}
.stats-logout{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:7px 14px;
              border-radius:8px;font-weight:600;font-size:12px;text-decoration:none}
.stats-logout:hover{background:#fee2e2}

.app{max-width:1300px;margin:0 auto;padding:28px}

/* Hero */
.hero{background:linear-gradient(135deg,#7c3aed 0%,#a855f7 50%,#c084fc 100%);
      color:#fff;padding:28px 32px;border-radius:18px;margin-bottom:22px;
      box-shadow:0 10px 30px rgba(124,58,237,.2)}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);
           padding:5px 12px;border-radius:50px;font-size:11px;font-weight:700;
           letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px}
.hero h1{margin:0 0 6px;font-size:28px;font-weight:800;letter-spacing:-.02em}
.hero p{margin:0;font-size:14px;opacity:.9}

/* Filtres */
.filters-bar{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;
             border-radius:12px;padding:14px 18px;box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:24px;flex-wrap:wrap}
.filter-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.filter-label{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.period-btn{padding:6px 14px;border-radius:8px;font-weight:600;font-size:13px;color:#64748b;
             text-decoration:none;transition:.15s;cursor:pointer;border:1px solid #e5e7eb;background:#fff}
.period-btn:hover{background:#f8fafc;color:#1e293b}
.period-btn.active{background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border-color:transparent;
                    box-shadow:0 4px 10px rgba(124,58,237,.3)}
.filter-select{padding:7px 12px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:13px;
                font-family:inherit;background:#fff;cursor:pointer;font-weight:500}
.filter-select:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15)}

/* Sections */
.section-title{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;
                letter-spacing:1px;margin:32px 0 14px;display:flex;align-items:center;gap:10px}
.section-title::before{content:"";width:4px;height:18px;background:linear-gradient(180deg,#7c3aed,#a855f7);border-radius:2px}

/* KPIs */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
@media(max-width:920px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.kpi-grid{grid-template-columns:1fr}}
.kpi{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px 20px;
      box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid;transition:.2s}
.kpi:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(15,23,42,.08)}
.kpi-icon{font-size:22px;margin-bottom:6px;display:block}
.kpi-num{font-size:30px;font-weight:800;letter-spacing:-.5px;line-height:1;margin:4px 0}
.kpi-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.kpi-sub{font-size:11px;color:#94a3b8;margin-top:4px}
.kpi-1{border-left-color:#1d4ed8} .kpi-1 .kpi-num{color:#1d4ed8}
.kpi-2{border-left-color:#7c3aed} .kpi-2 .kpi-num{color:#7c3aed}
.kpi-3{border-left-color:#0891b2} .kpi-3 .kpi-num{color:#0891b2}
.kpi-4{border-left-color:#059669} .kpi-4 .kpi-num{color:#059669}

.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:22px 24px;
      box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:20px}
.card h3{margin:0 0 18px;font-size:15px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px}

.evo-chart{display:grid;grid-template-columns:repeat(30,1fr);gap:3px;height:120px;
            align-items:end;padding:8px 0 6px;border-bottom:1px solid #f1f5f9}
.evo-bar{background:linear-gradient(180deg,#a855f7,#7c3aed);border-radius:3px 3px 0 0;
          min-height:3px;transition:.15s;position:relative;cursor:pointer}
.evo-bar:hover{background:linear-gradient(180deg,#c084fc,#a855f7)}
.evo-bar:hover::after{content:attr(data-tt);position:absolute;bottom:calc(100% + 4px);
                       left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;
                       padding:4px 8px;border-radius:6px;font-size:11px;white-space:nowrap;z-index:10}
.evo-axis{display:flex;justify-content:space-between;font-size:10px;color:#94a3b8;
           padding-top:6px;font-weight:600}

.top-list{display:flex;flex-direction:column;gap:10px}
.top-row{display:flex;align-items:center;gap:12px}
.top-rank{width:24px;height:24px;border-radius:50%;background:#f1f5f9;color:#475569;
           font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.top-row:nth-child(1) .top-rank{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#fff}
.top-row:nth-child(2) .top-rank{background:linear-gradient(135deg,#cbd5e1,#94a3b8);color:#fff}
.top-row:nth-child(3) .top-rank{background:linear-gradient(135deg,#fb923c,#ea580c);color:#fff}
.top-info{flex:1;min-width:0}
.top-name{font-size:13px;font-weight:600;color:#1e293b;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.top-bar{height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden}
.top-bar-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a855f7);border-radius:3px;transition:width .4s}
.top-count{font-size:13px;font-weight:800;color:#7c3aed;flex-shrink:0;width:32px;text-align:right}

.dual-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:840px){.dual-grid{grid-template-columns:1fr}}

.donut-wrap{display:flex;align-items:center;gap:24px}
.donut-svg{flex-shrink:0}
.donut-legend{flex:1;display:flex;flex-direction:column;gap:10px;font-size:13px}
.donut-leg-row{display:flex;align-items:center;gap:10px}
.donut-leg-dot{width:14px;height:14px;border-radius:4px;flex-shrink:0}
.donut-leg-text{flex:1;color:#475569}
.donut-leg-pct{font-weight:800;color:#0f172a}

.tranches-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
@media(max-width:600px){.tranches-grid{grid-template-columns:repeat(2,1fr)}}
.tranche{background:#f8fafc;border:1px solid #e5e7eb;border-radius:11px;padding:14px;text-align:center}
.tranche-num{font-size:22px;font-weight:800;color:#7c3aed;line-height:1}
.tranche-lbl{font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:6px}
.tranche-bar{height:4px;background:#e5e7eb;border-radius:2px;margin-top:8px;overflow:hidden}
.tranche-bar-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a855f7);border-radius:2px}

.quality-row{display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.quality-row:last-child{border-bottom:0}
.quality-icon{font-size:18px;width:24px;text-align:center;flex-shrink:0}
.quality-lbl{flex:1;font-size:13px;font-weight:600;color:#1e293b}
.quality-bar-wrap{flex:2;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden}
.quality-bar-fill{height:100%;border-radius:4px;transition:width .5s}
.quality-good{background:linear-gradient(90deg,#10b981,#059669)}
.quality-warn{background:linear-gradient(90deg,#f59e0b,#d97706)}
.quality-bad{background:linear-gradient(90deg,#dc2626,#b91c1c)}
.quality-pct{font-size:14px;font-weight:800;width:50px;text-align:right;flex-shrink:0}

.empty-msg{text-align:center;color:#94a3b8;font-style:italic;padding:30px 20px;font-size:13px}
</style>
</head>
<body>

<div class="stats-topbar">
    <div class="stats-topbar-inner">
        <a href="welcome.php" class="stats-logo">
            <span class="stats-logo-icon">A</span>
            APA4CAD
        </a>
        <span class="stats-badge"><?= hSU($modeBadge) ?></span>
        <div class="stats-spacer"></div>
        <a href="welcome.php" class="stats-home-btn">🏠 Accueil</a>
        <?php if ($isAdmin): ?>
            <a href="admin/logout.php" class="stats-logout">Déconnexion admin</a>
        <?php else: ?>
            <a href="logout_praticien.php" class="stats-logout">Déconnexion</a>
        <?php endif; ?>
    </div>
</div>

<div class="app">

    <div class="hero">
        <div class="hero-tag">📊 <?= hSU($modeBadge) ?></div>
        <h1><?= hSU($modeTitle) ?></h1>
        <p><?= hSU($modeSub) ?></p>
    </div>

    <!-- Filtres -->
    <form class="filters-bar" method="get">
        <div class="filter-group">
            <span class="filter-label">Période :</span>
            <button type="submit" name="period" value="week"  class="period-btn <?= $period === 'week'  ? 'active' : '' ?>">Semaine</button>
            <button type="submit" name="period" value="month" class="period-btn <?= $period === 'month' ? 'active' : '' ?>">Mois</button>
            <button type="submit" name="period" value="year"  class="period-btn <?= $period === 'year'  ? 'active' : '' ?>">Année</button>
            <button type="submit" name="period" value="all"   class="period-btn <?= $period === 'all'   ? 'active' : '' ?>">Tout</button>
        </div>

        <?php if ($isAdmin): ?>
        <div class="filter-group" style="margin-left:auto">
            <span class="filter-label">Praticien :</span>
            <select name="praticien" class="filter-select" onchange="this.form.submit()">
                <option value="">— Tous (vue système) —</option>
                <?php foreach ($allPraticiens as $p): ?>
                    <option value="<?= hSU($p['uri']) ?>" <?= $selectedPraticienUri === $p['uri'] ? 'selected' : '' ?>>
                        <?= hSU($p['prenom'] . ' ' . $p['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="period" value="<?= hSU($period) ?>">
        </div>
        <?php endif; ?>
    </form>

    <!-- KPIs Activité -->
    <h3 class="section-title">Activité</h3>
    <div class="kpi-grid">
        <div class="kpi kpi-1">
            <span class="kpi-icon">📋</span>
            <div class="kpi-num"><?= $totalAll ?></div>
            <div class="kpi-lbl">Prescriptions</div>
            <div class="kpi-sub">depuis le début</div>
        </div>
        <div class="kpi kpi-2">
            <span class="kpi-icon">📅</span>
            <div class="kpi-num"><?= $totalPeriod ?></div>
            <div class="kpi-lbl"><?= hSU($periodLabel) ?></div>
            <div class="kpi-sub">sur la période</div>
        </div>
        <div class="kpi kpi-3">
            <span class="kpi-icon">👥</span>
            <div class="kpi-num"><?= $totalPatients ?></div>
            <div class="kpi-lbl">Patients</div>
            <div class="kpi-sub"><?= $selectedPraticienUri !== '' ? 'suivis' : 'dans le système' ?></div>
        </div>
        <div class="kpi kpi-4">
            <span class="kpi-icon">🏃</span>
            <div class="kpi-num"><?= number_format($avgActs, 1, ',', ' ') ?></div>
            <div class="kpi-lbl">Activités / prescription</div>
            <div class="kpi-sub">moyenne</div>
        </div>
    </div>

    <!-- Évolution 30j -->
    <h3 class="section-title">Évolution sur 30 jours</h3>
    <div class="card">
        <h3>📈 Prescriptions au quotidien</h3>
        <?php if (array_sum($daysData) === 0): ?>
            <div class="empty-msg">Aucune prescription sur les 30 derniers jours.</div>
        <?php else: ?>
        <div class="evo-chart">
            <?php foreach ($daysData as $i => $n):
                $h = $n === 0 ? 3 : max(8, ($n / $maxDay) * 100);
            ?>
                <div class="evo-bar" style="height:<?= $h ?>%" data-tt="<?= $n ?> presc. — <?= hSU($daysLabels[$i]) ?>"></div>
            <?php endforeach; ?>
        </div>
        <div class="evo-axis">
            <span><?= hSU($daysLabels[0])  ?></span>
            <span><?= hSU($daysLabels[7])  ?></span>
            <span><?= hSU($daysLabels[14]) ?></span>
            <span><?= hSU($daysLabels[22]) ?></span>
            <span><?= hSU($daysLabels[29]) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Top -->
    <h3 class="section-title">Top 5 (<?= hSU($periodLabel) ?>)</h3>
    <div class="dual-grid">
        <div class="card">
            <h3>🩺 Pathologies les plus prescrites</h3>
            <?php if (empty($topPatho)): ?>
                <div class="empty-msg">Aucune donnée.</div>
            <?php else: $rank = 1; foreach ($topPatho as $r):
                $name = prettyUriSU($r['patho']['value'] ?? '');
                $n = (int)($r['n']['value'] ?? 0);
                $pct = ($n / $maxPatho) * 100;
            ?>
                <div class="top-row">
                    <div class="top-rank"><?= $rank++ ?></div>
                    <div class="top-info">
                        <div class="top-name"><?= hSU($name) ?></div>
                        <div class="top-bar"><div class="top-bar-fill" style="width:<?= $pct ?>%"></div></div>
                    </div>
                    <div class="top-count"><?= $n ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="card">
            <h3>🏃 Activités les plus prescrites</h3>
            <?php if (empty($topActs)): ?>
                <div class="empty-msg">Aucune donnée.</div>
            <?php else: $rank = 1; foreach ($topActs as $r):
                $name = prettyUriSU($r['actType']['value'] ?? '');
                $n = (int)($r['n']['value'] ?? 0);
                $pct = ($n / $maxActs) * 100;
            ?>
                <div class="top-row">
                    <div class="top-rank"><?= $rank++ ?></div>
                    <div class="top-info">
                        <div class="top-name"><?= hSU($name) ?></div>
                        <div class="top-bar"><div class="top-bar-fill" style="width:<?= $pct ?>%"></div></div>
                    </div>
                    <div class="top-count"><?= $n ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Profil -->
    <h3 class="section-title">Profil patientèle</h3>
    <div class="dual-grid">
        <div class="card">
            <h3>👫 Répartition par genre</h3>
            <?php if ($totalGenres === 0): ?>
                <div class="empty-msg">Données non renseignées.</div>
            <?php else:
                $cx = 80; $cy = 80; $r = 60; $C = 2 * M_PI * $r;
                $offsetH = ($pctH / 100) * $C;
            ?>
            <div class="donut-wrap">
                <svg class="donut-svg" width="160" height="160" viewBox="0 0 160 160">
                    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="#ec4899" stroke-width="24"/>
                    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="#3b82f6" stroke-width="24"
                            stroke-dasharray="<?= $offsetH ?> <?= $C ?>" stroke-dashoffset="0"
                            transform="rotate(-90 <?= $cx ?> <?= $cy ?>)"/>
                    <text x="<?= $cx ?>" y="<?= $cy + 6 ?>" text-anchor="middle" font-size="20" font-weight="800" fill="#0f172a"><?= $totalGenres ?></text>
                </svg>
                <div class="donut-legend">
                    <div class="donut-leg-row">
                        <div class="donut-leg-dot" style="background:#3b82f6"></div>
                        <div class="donut-leg-text">Hommes (<?= $hommes ?>)</div>
                        <div class="donut-leg-pct"><?= $pctH ?>%</div>
                    </div>
                    <div class="donut-leg-row">
                        <div class="donut-leg-dot" style="background:#ec4899"></div>
                        <div class="donut-leg-text">Femmes (<?= $femmes ?>)</div>
                        <div class="donut-leg-pct"><?= $pctF ?>%</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>👤 Tranches d'âge (moy. <?= $avgAge ?> ans)</h3>
            <?php if (array_sum($tranchesAge) === 0): ?>
                <div class="empty-msg">Aucune donnée d'âge.</div>
            <?php else: ?>
            <div class="tranches-grid">
                <?php foreach ($tranchesAge as $lbl => $n):
                    $pct = ($n / $maxAge) * 100;
                ?>
                <div class="tranche">
                    <div class="tranche-num"><?= $n ?></div>
                    <div class="tranche-lbl"><?= hSU($lbl) ?> ans</div>
                    <div class="tranche-bar"><div class="tranche-bar-fill" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Qualité -->
    <h3 class="section-title">Qualité des prescriptions</h3>
    <div class="card">
        <h3>✨ Indicateurs sur <?= $totalQ ?> prescription<?= $totalQ > 1 ? 's' : '' ?> (<?= hSU($periodLabel) ?>)</h3>
        <?php if ($totalQ === 0): ?>
            <div class="empty-msg">Aucune prescription sur cette période.</div>
        <?php else:
            $qClass = fn($p) => $p >= 80 ? 'quality-good' : ($p >= 50 ? 'quality-warn' : 'quality-bad');
        ?>
            <div class="quality-row">
                <span class="quality-icon">📝</span>
                <span class="quality-lbl">Avec résumé IA</span>
                <div class="quality-bar-wrap"><div class="quality-bar-fill <?= $qClass($pctResume) ?>" style="width:<?= $pctResume ?>%"></div></div>
                <span class="quality-pct"><?= $pctResume ?>%</span>
            </div>
            <div class="quality-row">
                <span class="quality-icon">⚠</span>
                <span class="quality-lbl">Freins identifiés</span>
                <div class="quality-bar-wrap"><div class="quality-bar-fill <?= $qClass($pctFreins) ?>" style="width:<?= $pctFreins ?>%"></div></div>
                <span class="quality-pct"><?= $pctFreins ?>%</span>
            </div>
            <div class="quality-row">
                <span class="quality-icon">💡</span>
                <span class="quality-lbl">Leviers appliqués</span>
                <div class="quality-bar-wrap"><div class="quality-bar-fill <?= $qClass($pctLeviers) ?>" style="width:<?= $pctLeviers ?>%"></div></div>
                <span class="quality-pct"><?= $pctLeviers ?>%</span>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
