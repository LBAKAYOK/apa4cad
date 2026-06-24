<?php
/**
 * APA4CAD — Export Fuseki → MySQL  (étape 2 de la migration)
 *
 * À placer dans le dossier "apa4cad" (à côté de config_fuseki.php et freins_data.php).
 * Lancer dans le navigateur :  http://localhost/apa4cad/export_fuseki_vers_mysql.php
 *
 * • LIT ton Fuseki existant (lecture seule) en réutilisant les fonctions F_ de
 *   freins_data.php — donc l'extraction des recommandations / contre-indications /
 *   freins / leviers est exactement celle qui marche déjà dans module2.
 * • ÉCRIT dans la base MySQL "apa4cad".
 * • Ré-exécutable : il vide puis recharge les tables de connaissance à chaque fois.
 *
 * ⚠ Il ne modifie JAMAIS Fuseki. Ton module2 n'est pas touché.
 */

@set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

// ─── Connexion MySQL (XAMPP par défaut) ──────────────────────────────────
$MYSQL_HOST = 'localhost';
$MYSQL_USER = 'root';
$MYSQL_PASS = '';            // ← mets ton mot de passe MySQL ici SEULEMENT si tu en as un
$MYSQL_DB   = 'apa4cad';

// ─── Réutilisation des fonctions de l'appli (lecture Fuseki) ─────────────
require_once __DIR__ . '/config_fuseki.php';   // ONTO_NAMESPACE, endpoints…
require_once __DIR__ . '/freins_data.php';     // F_sparql, F_loadRecommendations, F_loadContraindications, F_loadFreinsAndLeviers…

$NS = defined('ONTO_NAMESPACE') ? ONTO_NAMESPACE
    : 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';

function px(): string {
    global $NS;
    return "PREFIX ex: <$NS>\n"
         . "PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>\n"
         . "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>\n"
         . "PREFIX owl: <http://www.w3.org/2002/07/owl#>\n";
}

echo "<!DOCTYPE html><html lang='fr'><head><meta charset='utf-8'>
<title>Export Fuseki → MySQL</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:820px;margin:30px auto;color:#1e293b;line-height:1.5}
h1{color:#1d4ed8}.ok{color:#047857;font-weight:700}.err{color:#b91c1c;font-weight:700}
.step{background:#f8fafc;border:1px solid #e5e7eb;border-left:4px solid #2563eb;border-radius:8px;padding:10px 14px;margin:10px 0}
code{background:#eef2f7;padding:1px 6px;border-radius:4px}</style></head><body>";
echo "<h1>Export Fuseki → MySQL</h1>";

function step(string $msg, bool $ok = true): void {
    echo "<div class='step'>" . ($ok ? "<span class='ok'>✓</span> " : "<span class='err'>✗</span> ") . htmlspecialchars($msg) . "</div>";
    @ob_flush(); @flush();
}

// ─── Connexion ────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=$MYSQL_HOST;dbname=$MYSQL_DB;charset=utf8mb4", $MYSQL_USER, $MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    step("Connexion MySQL impossible : " . $e->getMessage(), false);
    echo "<p>Vérifie que MySQL est démarré dans XAMPP et que la base <code>apa4cad</code> existe.</p></body></html>";
    exit;
}
step("Connecté à la base MySQL « $MYSQL_DB ».");

// Vérifier que Fuseki répond
$ping = F_sparql(px() . "SELECT (COUNT(*) AS ?n) WHERE { ?s ?p ?o } LIMIT 1");
if (!$ping['ok']) {
    step("Fuseki ne répond pas. Démarre Fuseki (port 3030) puis relance.", false);
    echo "</body></html>"; exit;
}
step("Fuseki répond. Lecture de l'ontologie en cours…");

// ─── 0) On vide les tables de connaissance (ré-exécutable) ───────────────
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['activite_parent','activite_adaptation','activite_equipement','activite_modalite',
          'reco','contre_indication','frein_levier',
          'activite','categorie','pathologie','frein','levier','adaptation','equipement'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
step("Tables de connaissance vidées.");

// ─── 1) Hiérarchie des activités (subClassOf entre classes nommées) ──────
$edges = [];                 // child => [parents]
$rev   = [];                 // parent => [children]
$r = F_sparql(px() . "
  SELECT ?sub ?sup WHERE {
    ?sub rdfs:subClassOf ?sup .
    FILTER(isIRI(?sub) && isIRI(?sup))
    FILTER(STRSTARTS(STR(?sub), \"$NS\") && STRSTARTS(STR(?sup), \"$NS\"))
  }");
foreach ($r['bindings'] as $row) {
    $sub = F_localName($row['sub']['value']);
    $sup = F_localName($row['sup']['value']);
    if ($sub === '' || $sup === '') continue;
    $edges[$sub][] = $sup;
    $rev[$sup][] = $sub;
}

// Ensemble des activités = descendants de ActivitePhysique ∪ Sport
$activitySet = [];
$queue = ['ActivitePhysique', 'Sport'];
while ($queue) {
    $c = array_pop($queue);
    foreach ($rev[$c] ?? [] as $child) {
        if (!isset($activitySet[$child])) { $activitySet[$child] = true; $queue[] = $child; }
    }
}
// On garde aussi les "catégories" intermédiaires (Sport, SportCollectif…) si elles ont des enfants activités
foreach (array_keys($activitySet) as $a) {
    foreach ($edges[$a] ?? [] as $p) {
        if ($p !== 'ActivitePhysique' && $p !== 'Sport') $activitySet[$p] = true;
    }
}

// Catégories = activités qui sont parentes d'au moins une autre activité
$categorySet = [];
foreach ($activitySet as $a => $_) {
    foreach ($rev[$a] ?? [] as $child) {
        if (isset($activitySet[$child])) { $categorySet[$a] = true; break; }
    }
}

// Insert catégories
$insCat = $pdo->prepare("INSERT INTO categorie (local,label) VALUES (?,?)");
$catId = [];
foreach (array_keys($categorySet) as $c) {
    $insCat->execute([$c, F_prettyLabel($c)]);
    $catId[$c] = (int)$pdo->lastInsertId();
}
// Insert activités (avec catégorie = parent direct s'il est une catégorie)
$insAct = $pdo->prepare("INSERT INTO activite (local,label,categorie_id) VALUES (?,?,?)");
$actId = [];
foreach (array_keys($activitySet) as $a) {
    $cat = null;
    foreach ($edges[$a] ?? [] as $p) { if (isset($catId[$p])) { $cat = $catId[$p]; break; } }
    $insAct->execute([$a, F_prettyLabel($a), $cat]);
    $actId[$a] = (int)$pdo->lastInsertId();
}
// fonction d'upsert activité (pour les cibles reco/CI éventuellement hors set)
$ensureAct = function(string $local) use (&$actId, $pdo): int {
    if ($local === '') return 0;
    if (isset($actId[$local])) return $actId[$local];
    $st = $pdo->prepare("INSERT INTO activite (local,label) VALUES (?,?)");
    $st->execute([$local, F_prettyLabel($local)]);
    return $actId[$local] = (int)$pdo->lastInsertId();
};
// Liens de hiérarchie
$insAP = $pdo->prepare("INSERT IGNORE INTO activite_parent (activite_id,parent_id) VALUES (?,?)");
foreach ($activitySet as $a => $_) {
    foreach ($edges[$a] ?? [] as $p) {
        if (isset($actId[$a]) && isset($actId[$p])) $insAP->execute([$actId[$a], $actId[$p]]);
    }
}
step(count($actId) . " activités, " . count($catId) . " catégories, hiérarchie chargée.");

// ─── 2) Pathologies (+ parent) ───────────────────────────────────────────
$insPat = $pdo->prepare("INSERT INTO pathologie (local,label,parent_local) VALUES (?,?,?)");
$patList = [];
$r = F_sparql(px() . "
  SELECT ?p ?parent WHERE {
    ?p rdfs:subClassOf* ex:Pathologie . FILTER(isIRI(?p)) FILTER(?p != ex:Pathologie)
    OPTIONAL { ?p rdfs:subClassOf ?parent . FILTER(isIRI(?parent)) }
  }");
$patSeen = [];
foreach ($r['bindings'] as $row) {
    $p = F_localName($row['p']['value']);
    if ($p === '' || isset($patSeen[$p])) continue;
    $patSeen[$p] = true;
    $parent = isset($row['parent']) ? F_localName($row['parent']['value']) : null;
    $insPat->execute([$p, F_categoryTitle($p), $parent]);
    $patList[$p] = $NS . $p;
}
step(count($patList) . " pathologies chargées.");

// ─── 3) Recommandations, contre-indications, adaptations (par pathologie) ─
$insReco = $pdo->prepare("INSERT IGNORE INTO reco (pathologie_id,activite_id) VALUES (?,?)");
$insCI   = $pdo->prepare("INSERT IGNORE INTO contre_indication (pathologie_id,activite_id) VALUES (?,?)");
$insAA   = $pdo->prepare("INSERT IGNORE INTO activite_adaptation (activite_id,adaptation_id) VALUES (?,?)");

// id pathologie par nom local
function patho_id(PDO $pdo, string $local): int {
    $st = $pdo->prepare("SELECT id FROM pathologie WHERE local=?");
    $st->execute([$local]);
    return (int)($st->fetchColumn() ?: 0);
}
// id adaptation (upsert)
$adaIdMap = [];
function ada_id(PDO $pdo, array &$map, string $local): int {
    if ($local === '') return 0;
    if (isset($map[$local])) return $map[$local];
    $ins = $pdo->prepare("INSERT INTO adaptation (local,label) VALUES (?,?)");
    $ins->execute([$local, F_prettyLabel($local)]);
    return $map[$local] = (int)$pdo->lastInsertId();
}

$nReco = 0; $nCI = 0; $nAda = 0;
foreach ($patList as $pLocal => $pUri) {
    $pid = patho_id($pdo, $pLocal);
    if ($pid === 0) continue;

    foreach (F_loadRecommendations($pUri) as $it) {
        $aLoc = $it['activity'] ?? '';
        if ($aLoc === '') continue;
        $aid = $ensureAct($aLoc);
        $insReco->execute([$pid, $aid]); $nReco++;
        foreach ($it['adaptations'] ?? [] as $adaLoc) {
            $adaLoc = F_localName($adaLoc);
            $aidAda = ada_id($pdo, $adaIdMap, $adaLoc);
            if ($aidAda) { $insAA->execute([$aid, $aidAda]); $nAda++; }
        }
    }
    foreach (F_loadContraindications($pUri) as $ciLoc) {
        $ciLoc = F_localName($ciLoc);
        if ($ciLoc === '') continue;
        $aid = $ensureAct($ciLoc);
        $insCI->execute([$pid, $aid]); $nCI++;
    }
}
step("$nReco recommandations, $nCI contre-indications, $nAda liens d'adaptation insérés.");

// ─── 4) Freins & leviers (global) ────────────────────────────────────────
$insFre = $pdo->prepare("INSERT INTO frein (local,label,type_local,type_label) VALUES (?,?,?,?)");
$insLev = $pdo->prepare("INSERT INTO levier (local,label) VALUES (?,?)");
$insFL  = $pdo->prepare("INSERT IGNORE INTO frein_levier (frein_id,levier_id) VALUES (?,?)");
$freId = []; $levId = [];
$nFre = 0; $nLev = 0; $nFL = 0;
foreach (F_loadFreinsAndLeviers() as $typeLabel => $freins) {
    foreach ($freins as $f) {
        $insFre->execute([$f['id'], $f['label'], $f['typeKey'] ?? null, $f['typeLabel'] ?? $typeLabel]);
        $fid = (int)$pdo->lastInsertId(); $freId[$f['id']] = $fid; $nFre++;
        foreach ($f['leviers'] ?? [] as $levRaw) {
            $levLoc = F_localName($levRaw);
            if ($levLoc === '') continue;
            if (!isset($levId[$levLoc])) {
                $insLev->execute([$levLoc, F_prettyLabel($levLoc)]);
                $levId[$levLoc] = (int)$pdo->lastInsertId(); $nLev++;
            }
            $insFL->execute([$fid, $levId[$levLoc]]); $nFL++;
        }
    }
}
step("$nFre freins, $nLev leviers, $nFL liens frein→levier insérés.");

echo "<h2 class='ok'>✓ Export de la connaissance terminé.</h2>";
echo "<p>Tu peux vérifier dans phpMyAdmin (base <code>apa4cad</code>) : les tables
<code>activite</code>, <code>reco</code>, <code>contre_indication</code>,
<code>frein</code>/<code>levier</code> doivent être remplies.</p>";
echo "<p>Les données patients/prescriptions/praticiens ne sont pas migrées par ce script
(tu pourras créer des données de test dans la nouvelle version). Si tu veux les migrer aussi,
dis-le-moi et je te fournis le second script.</p>";
echo "</body></html>";
