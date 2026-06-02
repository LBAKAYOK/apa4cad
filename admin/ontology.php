<?php
/**
 * APA4CAD - Gestion de l'ontologie (Admin)
 *
 * Permet à un admin de :
 *   - Lister toutes les pathologies (groupées par catégorie)
 *   - Ajouter une nouvelle pathologie
 *   - Modifier une pathologie (nom, catégorie, recommandations, CI)
 *   - Désactiver (soft delete) une pathologie inutilisée
 *   - Réactiver une pathologie désactivée
 *
 * Sécurité :
 *   - Accès admin uniquement (via _guard.php)
 *   - Blocage de la désactivation si la pathologie est liée à un patient
 *   - Toutes les opérations passent par SPARQL UPDATE
 */

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../sparql_update.php';

// ─────────────────────────────────────────────────────────────────────────
//  Helpers SPARQL locaux
// ─────────────────────────────────────────────────────────────────────────
function sparqlO(string $query): array {
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
function hO(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function localO(string $uri): string {
    return str_contains($uri, '#') ? substr($uri, strrpos($uri, '#') + 1) : $uri;
}
function prettyO(string $s): string {
    $s = str_replace('_', ' ', $s);
    return trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', $s));
}
function normalizeName(string $s): string {
    // Pour créer un nom OWL valide à partir d'un libellé : retire accents,
    // remplace espaces par underscore, garde uniquement alphanumérique + _
    $s = trim($s);
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^A-Za-z0-9_]/', '', $s);
    return $s;
}

// ─────────────────────────────────────────────────────────────────────────
//  TRAITEMENT DES ACTIONS POST (création, modification, désactivation)
// ─────────────────────────────────────────────────────────────────────────
$flash = null;
$openModalForEdit = null; // pour rouvrir la modale en cas d'erreur

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── AJOUT D'UNE PATHOLOGIE ────────────────────────────────────────
    if ($action === 'add') {
        $rawName = trim((string)($_POST['name'] ?? ''));
        $categoryUri = trim((string)($_POST['category_uri'] ?? ''));
        $newCategoryName = trim((string)($_POST['new_category_name'] ?? ''));
        $recommended = $_POST['recommended'] ?? [];
        $contraindicated = $_POST['contraindicated'] ?? [];

        if (!is_array($recommended)) $recommended = [];
        if (!is_array($contraindicated)) $contraindicated = [];

        if ($rawName === '') {
            $flash = ['type' => 'error', 'msg' => 'Le nom de la pathologie est obligatoire.'];
        } else {
            $localName = normalizeName($rawName);
            $pathoUri = ONTO_NAMESPACE . $localName;

            // Détermine la catégorie parente (soit existante, soit nouvelle)
            $parentUri = null;
            if ($newCategoryName !== '') {
                $catLocal = normalizeName($newCategoryName);
                $parentUri = ONTO_NAMESPACE . $catLocal;
                // On crée la catégorie si elle n'existe pas
                $triples = [
                    "<$parentUri> rdf:type owl:Class",
                    "<$parentUri> rdfs:subClassOf ex:Pathologie",
                    "<$parentUri> rdfs:label \"" . sparqlEscapeString($newCategoryName) . "\"@fr",
                ];
            } elseif ($categoryUri !== '') {
                $parentUri = $categoryUri;
                $triples = [];
            } else {
                // Pas de catégorie → directement sous Pathologie
                $parentUri = ONTO_NAMESPACE . 'Pathologie';
                $triples = [];
            }

            // Triplets de base de la pathologie
            $triples[] = "<$pathoUri> rdf:type owl:Class";
            $triples[] = "<$pathoUri> rdfs:subClassOf <$parentUri>";
            $triples[] = "<$pathoUri> rdfs:label \"" . sparqlEscapeString($rawName) . "\"@fr";

            // Activités recommandées (via restriction OWL)
            foreach ($recommended as $actUri) {
                $actUri = trim((string)$actUri);
                if ($actUri === '' || !str_starts_with($actUri, ONTO_NAMESPACE)) continue;
                $bnode = "_:rec" . substr(md5($pathoUri . $actUri), 0, 8);
                $triples[] = "<$pathoUri> rdfs:subClassOf $bnode";
                $triples[] = "$bnode rdf:type owl:Restriction";
                $triples[] = "$bnode owl:onProperty ex:aPourActiviteRecommandee";
                $triples[] = "$bnode owl:someValuesFrom <$actUri>";
            }

            // Contre-indications
            foreach ($contraindicated as $actUri) {
                $actUri = trim((string)$actUri);
                if ($actUri === '' || !str_starts_with($actUri, ONTO_NAMESPACE)) continue;
                $bnode = "_:ci" . substr(md5($pathoUri . $actUri), 0, 8);
                $triples[] = "<$pathoUri> rdfs:subClassOf $bnode";
                $triples[] = "$bnode rdf:type owl:Restriction";
                $triples[] = "$bnode owl:onProperty ex:aPourContreIndication";
                $triples[] = "$bnode owl:someValuesFrom <$actUri>";
            }

            $insertQuery = sparqlPrefixes() . " INSERT DATA {\n" . implode(" .\n", $triples) . " .\n}";
            $res = sparqlUpdate($insertQuery);
            if ($res['success']) {
                $flash = ['type' => 'success', 'msg' => 'Pathologie « ' . htmlspecialchars($rawName) . ' » créée.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
            }
        }
    }

    // ─── MODIFICATION D'UNE PATHOLOGIE ─────────────────────────────────
    elseif ($action === 'edit') {
        $pathoUri = trim((string)($_POST['patho_uri'] ?? ''));
        $newName = trim((string)($_POST['name'] ?? ''));
        $recommended = $_POST['recommended'] ?? [];
        $contraindicated = $_POST['contraindicated'] ?? [];

        if (!is_array($recommended)) $recommended = [];
        if (!is_array($contraindicated)) $contraindicated = [];

        if ($pathoUri === '' || !str_starts_with($pathoUri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Pathologie invalide.'];
        } else {
            // 1) Supprimer toutes les restrictions existantes (reco + CI) de cette patho
            //    On supprime les blank nodes liés par rdfs:subClassOf qui sont des restrictions
            $deleteQuery = sparqlPrefixes() . "
                DELETE {
                    <$pathoUri> rdfs:subClassOf ?r .
                    ?r ?p ?o .
                }
                WHERE {
                    <$pathoUri> rdfs:subClassOf ?r .
                    ?r rdf:type owl:Restriction .
                    ?r owl:onProperty ?prop .
                    FILTER(?prop IN (ex:aPourActiviteRecommandee, ex:aPourContreIndication))
                    ?r ?p ?o .
                }
            ";
            sparqlUpdate($deleteQuery);

            // 2) Mettre à jour le label si changé
            if ($newName !== '') {
                $delLabel = sparqlPrefixes() . "
                    DELETE { <$pathoUri> rdfs:label ?l }
                    WHERE  { <$pathoUri> rdfs:label ?l }
                ";
                sparqlUpdate($delLabel);

                $insLabel = sparqlPrefixes() . " INSERT DATA {
                    <$pathoUri> rdfs:label \"" . sparqlEscapeString($newName) . "\"@fr
                }";
                sparqlUpdate($insLabel);
            }

            // 3) Réinsérer les nouvelles restrictions
            $triples = [];
            foreach ($recommended as $actUri) {
                $actUri = trim((string)$actUri);
                if ($actUri === '' || !str_starts_with($actUri, ONTO_NAMESPACE)) continue;
                $bnode = "_:rec" . substr(md5($pathoUri . $actUri . microtime()), 0, 10);
                $triples[] = "<$pathoUri> rdfs:subClassOf $bnode";
                $triples[] = "$bnode rdf:type owl:Restriction";
                $triples[] = "$bnode owl:onProperty ex:aPourActiviteRecommandee";
                $triples[] = "$bnode owl:someValuesFrom <$actUri>";
            }
            foreach ($contraindicated as $actUri) {
                $actUri = trim((string)$actUri);
                if ($actUri === '' || !str_starts_with($actUri, ONTO_NAMESPACE)) continue;
                $bnode = "_:ci" . substr(md5($pathoUri . $actUri . microtime()), 0, 10);
                $triples[] = "<$pathoUri> rdfs:subClassOf $bnode";
                $triples[] = "$bnode rdf:type owl:Restriction";
                $triples[] = "$bnode owl:onProperty ex:aPourContreIndication";
                $triples[] = "$bnode owl:someValuesFrom <$actUri>";
            }

            if (!empty($triples)) {
                $insQuery = sparqlPrefixes() . " INSERT DATA {\n" . implode(" .\n", $triples) . " .\n}";
                $res = sparqlUpdate($insQuery);
                if (!$res['success']) {
                    $flash = ['type' => 'error', 'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
                }
            }
            if (!$flash) {
                $flash = ['type' => 'success', 'msg' => 'Pathologie mise à jour.'];
            }
        }
    }

    // ─── DÉSACTIVATION (soft delete) ───────────────────────────────────
    elseif ($action === 'deactivate') {
        $pathoUri = trim((string)($_POST['patho_uri'] ?? ''));
        if ($pathoUri === '' || !str_starts_with($pathoUri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Pathologie invalide.'];
        } else {
            // Vérifier qu'elle n'est liée à aucun patient
            $checkQuery = sparqlPrefixes() . "
                SELECT (COUNT(?p) AS ?n) WHERE {
                    { ?p ex:aPourPathologie <$pathoUri> }
                    UNION { ?p ex:aPourPathologieArchivee <$pathoUri> }
                }
            ";
            $bindings = sparqlO($checkQuery);
            $nbUsage = (int)($bindings[0]['n']['value'] ?? 0);

            if ($nbUsage > 0) {
                $flash = ['type' => 'error',
                          'msg' => "Impossible de désactiver : cette pathologie est utilisée par {$nbUsage} patient(s). " .
                                   "Archivez d'abord la pathologie chez tous les patients concernés."];
            } else {
                $now = date('Y-m-d\TH:i:s');
                $insQuery = sparqlPrefixes() . " INSERT DATA {
                    <$pathoUri> ex:estPathologieInactive \"true\"^^xsd:boolean ;
                                ex:aPourDateDesactivation \"$now\"^^xsd:dateTime .
                }";
                $res = sparqlUpdate($insQuery);
                $flash = $res['success']
                    ? ['type' => 'success', 'msg' => 'Pathologie désactivée. Elle reste dans Fuseki pour traçabilité.']
                    : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
            }
        }
    }

    // ─── RÉACTIVATION ──────────────────────────────────────────────────
    elseif ($action === 'reactivate') {
        $pathoUri = trim((string)($_POST['patho_uri'] ?? ''));
        if ($pathoUri === '' || !str_starts_with($pathoUri, ONTO_NAMESPACE)) {
            $flash = ['type' => 'error', 'msg' => 'Pathologie invalide.'];
        } else {
            $delQuery = sparqlPrefixes() . "
                DELETE {
                    <$pathoUri> ex:estPathologieInactive ?v1 ;
                                ex:aPourDateDesactivation ?v2 .
                }
                WHERE {
                    <$pathoUri> ex:estPathologieInactive ?v1 ;
                                ex:aPourDateDesactivation ?v2 .
                }
            ";
            $res = sparqlUpdate($delQuery);
            $flash = $res['success']
                ? ['type' => 'success', 'msg' => 'Pathologie réactivée.']
                : ['type' => 'error',   'msg' => 'Erreur SPARQL : ' . ($res['error'] ?? '?')];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  CHARGEMENT DES DONNÉES POUR L'AFFICHAGE
// ─────────────────────────────────────────────────────────────────────────

// Les 5 racines de l'ontologie (cohérent avec index.php)
$rootCategories = [
    ONTO_NAMESPACE . 'AffectionDeLongueDuree',
    ONTO_NAMESPACE . 'PathologieCardiaque',
    ONTO_NAMESPACE . 'PathologieDigestive',
    ONTO_NAMESPACE . 'PathologieMusculosquelettique',
    ONTO_NAMESPACE . 'PathologieRespiratoire',
];

// Dictionnaire des libellés "propres" (repris de index.php categoryTitle)
function categoryTitleO(string $local): string {
    return match ($local) {
        'AffectionDeLongueDuree'         => 'Affections de longue durée',
        'PathologieCardiaque'            => 'Pathologies cardiaques',
        'PathologieDigestive'            => 'Pathologies digestives',
        'PathologieMusculosquelettique'  => 'Pathologies musculosquelettiques',
        'PathologieRespiratoire'         => 'Pathologies respiratoires',
        'PathologieCoronarienne'         => 'Pathologies coronariennes',
        'CardiopathiesInflammatoires'    => 'Cardiopathies inflammatoires',
        'CardiopathiesStructurelle'      => 'Cardiopathies structurelles',
        'CoronaropathieChronique'        => 'Coronaropathie chronique',
        'CoronaropathieFonctionnelle'    => 'Coronaropathie fonctionnelle',
        'SyndromeCoronarienAigu'         => 'Syndrome coronarien aigu',
        'Diabete'                        => 'Diabète',
        'Arthrose'                       => 'Arthrose',
        default                          => prettyO($local),
    };
}

// 1) Charger TOUTE l'arborescence depuis les 5 racines, avec deux types de relations :
//    - rdfs:subClassOf direct (héritage classique)
//    - rdfs:subClassOf sur un nœud anonyme avec owl:intersectionOf (héritage via expression)
//    Identique à ce que fait l'app praticien.
$treeQuery = '
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX owl:  <http://www.w3.org/2002/07/owl#>
PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT DISTINCT ?child ?parent WHERE {
  {
    # Héritage direct vers classe nommée
    ?child rdfs:subClassOf ?parent .
    FILTER(isIRI(?parent))
    FILTER(STRSTARTS(STR(?child),  "' . ONTO_NAMESPACE . '"))
    FILTER(STRSTARTS(STR(?parent), "' . ONTO_NAMESPACE . '"))
    FILTER(?child != ?parent)
  }
  UNION
  {
    # Héritage via intersection OWL (cas fréquent dans Protégé)
    ?child rdfs:subClassOf ?anon .
    FILTER(isBlank(?anon))
    ?anon owl:intersectionOf/rdf:rest*/rdf:first ?parent .
    FILTER(isIRI(?parent))
    FILTER(STRSTARTS(STR(?child),  "' . ONTO_NAMESPACE . '"))
    FILTER(STRSTARTS(STR(?parent), "' . ONTO_NAMESPACE . '"))
    FILTER(?child != ?parent)
  }
}
';

// Construction de l'index parent → enfants
$childrenOf = [];
foreach (sparqlO($treeQuery) as $row) {
    $child  = $row['child']['value']  ?? '';
    $parent = $row['parent']['value'] ?? '';
    if ($child === '' || $parent === '' || $child === $parent) continue;
    $childrenOf[$parent][$child] = true;
}

// Parcours récursif depuis les 5 racines pour collecter tous les nœuds
$allPathologies = []; // uri => ['uri', 'local', 'label', 'parent_uri', 'is_category']
$visited = [];

function walkTreeO(string $uri, ?string $parentUri, array $childrenOf, array &$allPathologies, array &$visited): void {
    if (isset($visited[$uri])) return;
    $visited[$uri] = true;

    $local = localO($uri);
    $hasChildren = !empty($childrenOf[$uri]);

    $allPathologies[$uri] = [
        'uri'         => $uri,
        'local'       => $local,
        'label'       => categoryTitleO($local),
        'parent_uri'  => $parentUri,
        'is_category' => $hasChildren,
    ];

    if ($hasChildren) {
        foreach (array_keys($childrenOf[$uri]) as $childUri) {
            walkTreeO($childUri, $uri, $childrenOf, $allPathologies, $visited);
        }
    }
}

foreach ($rootCategories as $rootUri) {
    walkTreeO($rootUri, null, $childrenOf, $allPathologies, $visited);
}

// 2) Charger l'état inactif et la date de désactivation (séparément)
$inactiveQuery = sparqlPrefixes() . "
    SELECT ?patho ?dateDeact WHERE {
        ?patho ex:estPathologieInactive \"true\"^^xsd:boolean .
        OPTIONAL { ?patho ex:aPourDateDesactivation ?dateDeact }
    }
";
foreach (sparqlO($inactiveQuery) as $r) {
    $uri = $r['patho']['value'] ?? '';
    if (isset($allPathologies[$uri])) {
        $allPathologies[$uri]['inactive']   = true;
        $allPathologies[$uri]['date_deact'] = $r['dateDeact']['value'] ?? '';
    }
}
// Compléter avec inactive=false pour les autres
foreach ($allPathologies as &$p) {
    if (!isset($p['inactive']))   $p['inactive']   = false;
    if (!isset($p['date_deact'])) $p['date_deact'] = '';
}
unset($p);

// 3) Pour chaque pathologie, compter combien de patients l'utilisent
$pathoUsage = []; // uri => nb patients
$usageQuery = sparqlPrefixes() . "
    SELECT ?patho (COUNT(DISTINCT ?p) AS ?n) WHERE {
        { ?p ex:aPourPathologie ?patho } UNION { ?p ex:aPourPathologieArchivee ?patho }
        FILTER(STRSTARTS(STR(?patho), '" . ONTO_NAMESPACE . "'))
    }
    GROUP BY ?patho
";
foreach (sparqlO($usageQuery) as $r) {
    $pathoUsage[$r['patho']['value']] = (int)($r['n']['value'] ?? 0);
}

// 4) Pour chaque pathologie, ses recommandations et CI
//    On considère TOUTES les voies d'héritage des restrictions (direct + intersection)
$pathoRecs = [];
$restrQuery = sparqlPrefixes() . "
    SELECT ?patho ?prop ?target WHERE {
        {
            # Restriction directe
            ?patho rdfs:subClassOf ?r .
            ?r rdf:type owl:Restriction ;
               owl:onProperty ?prop ;
               owl:someValuesFrom ?target .
        }
        UNION
        {
            # Restriction dans une intersection
            ?patho rdfs:subClassOf ?anon .
            FILTER(isBlank(?anon))
            ?anon owl:intersectionOf/rdf:rest*/rdf:first ?r .
            ?r rdf:type owl:Restriction ;
               owl:onProperty ?prop ;
               owl:someValuesFrom ?target .
        }
        FILTER(?prop IN (ex:aPourActiviteRecommandee, ex:aPourContreIndication))
        FILTER(STRSTARTS(STR(?patho), '" . ONTO_NAMESPACE . "'))
    }
";
foreach (sparqlO($restrQuery) as $r) {
    $pUri = $r['patho']['value'];
    $prop = localO($r['prop']['value']);
    $tgt  = $r['target']['value'];
    if (!isset($pathoRecs[$pUri])) $pathoRecs[$pUri] = ['recommended' => [], 'contraindicated' => []];
    if ($prop === 'aPourActiviteRecommandee' && !in_array($tgt, $pathoRecs[$pUri]['recommended'], true))
        $pathoRecs[$pUri]['recommended'][] = $tgt;
    elseif ($prop === 'aPourContreIndication' && !in_array($tgt, $pathoRecs[$pUri]['contraindicated'], true))
        $pathoRecs[$pUri]['contraindicated'][] = $tgt;
}

// 5) Listes pour les modales : catégories (= nœuds avec enfants) et feuilles (= pathologies)
$categories = [];
$leafPathos = []; // pathologies "feuilles" (sans enfants) — les seules qu'on affiche

foreach ($allPathologies as $uri => $p) {
    if ($p['is_category']) {
        $categories[$uri] = $p;
    } else {
        $leafPathos[$uri] = $p;
    }
}

// 6) Toutes les activités disponibles (pour les cases à cocher)
//    On part de l'activité racine "Activite" et on prend toutes ses descendantes
$activities = [];
$actChildrenOf = [];
foreach ($childrenOf as $parent => $kids) {
    if (str_starts_with($parent, ONTO_NAMESPACE)) {
        $actChildrenOf[$parent] = $kids;
    }
}
function walkActivities(string $uri, array $childrenOf, array &$activities): void {
    if (isset($activities[$uri])) return;
    $activities[$uri] = [
        'uri'   => $uri,
        'local' => localO($uri),
        'label' => prettyO(localO($uri)),
    ];
    foreach (array_keys($childrenOf[$uri] ?? []) as $childUri) {
        walkActivities($childUri, $childrenOf, $activities);
    }
}
$actRootUri = ONTO_NAMESPACE . 'Activite';
walkActivities($actRootUri, $childrenOf, $activities);
// On retire l'activité racine elle-même de la liste
unset($activities[$actRootUri]);

/**
 * Rendu récursif d'un nœud de l'arbre.
 * Les nœuds avec enfants = catégories (dépliables).
 * Les nœuds sans enfants = pathologies feuilles (avec boutons d'action au hover).
 */
function renderTreeNodeO(string $uri, int $level, array $allPathologies, array $childrenOf,
                          array $leafPathos, array $pathoUsage, array $pathoRecs): void {
    if (!isset($allPathologies[$uri])) return;
    $node = $allPathologies[$uri];
    $children = array_keys($childrenOf[$uri] ?? []);

    if (!empty($children)) {
        // ─── Catégorie (branche dépliable) ───
        // Compter récursivement le nombre de feuilles sous cette branche
        $countLeaves = countLeavesUnderO($uri, $childrenOf, $allPathologies);
        ?>
        <div class="tree-node" data-level="<?= $level ?>" data-uri="<?= hO($uri) ?>">
            <div class="tree-cat" onclick="this.parentElement.classList.toggle('open')">
                <span class="chevron">▶</span>
                <span class="icon">📁</span>
                <span class="lbl"><?= hO($node['label']) ?></span>
                <span class="count"><?= $countLeaves ?></span>
            </div>
            <div class="tree-children">
                <?php foreach ($children as $childUri) {
                    renderTreeNodeO($childUri, $level + 1, $allPathologies, $childrenOf, $leafPathos, $pathoUsage, $pathoRecs);
                } ?>
            </div>
        </div>
        <?php
    } else {
        // ─── Pathologie feuille ───
        if (!isset($leafPathos[$uri])) return;
        $p = $leafPathos[$uri];
        $usage = $pathoUsage[$uri] ?? 0;
        $editData = [
            'uri'             => $p['uri'],
            'label'           => $p['label'],
            'recommended'     => $pathoRecs[$uri]['recommended']     ?? [],
            'contraindicated' => $pathoRecs[$uri]['contraindicated'] ?? [],
        ];
        ?>
        <div class="tree-leaf<?= $p['inactive'] ? ' inactive' : '' ?>"
             data-search="<?= hO(strtolower($p['label'] . ' ' . $p['local'])) ?>">
            <span class="leaf-dot"></span>
            <span class="leaf-name">
                <span class="label"><?= hO($p['label']) ?></span>
                <?php if ($p['inactive']): ?>
                    <span class="badge-inactive">Inactive</span>
                <?php endif; ?>
                <?php if ($usage > 0): ?>
                    <span class="badge-usage" title="<?= $usage ?> patient(s) li\u00e9(s)">👥 <?= $usage ?></span>
                <?php endif; ?>
            </span>
            <div class="actions">
                <button class="btn-action btn-edit"
                        onclick='openEditModal(<?= json_encode($editData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    ✏ Modifier
                </button>
                <?php if ($p['inactive']): ?>
                    <form method="post" style="margin:0;display:inline">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="patho_uri" value="<?= hO($p['uri']) ?>">
                        <button type="submit" class="btn-action btn-react">↻ Réactiver</button>
                    </form>
                <?php elseif ($usage > 0): ?>
                    <span class="btn-action btn-deact disabled"
                          title="Cette pathologie est li\u00e9e \u00e0 <?= $usage ?> patient(s). Archivez-la d'abord chez tous les patients concern\u00e9s pour pouvoir la d\u00e9sactiver.">
                        🔒 Bloqué
                    </span>
                <?php else: ?>
                    <button type="button" class="btn-action btn-deact"
                            onclick="confirmDeactivate('<?= hO($p['uri']) ?>', '<?= hO(addslashes($p['label'])) ?>')">
                        🗑 Désactiver
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Compte récursivement le nombre de pathologies feuilles sous une branche.
 */
function countLeavesUnderO(string $uri, array $childrenOf, array $allPathologies): int {
    $children = array_keys($childrenOf[$uri] ?? []);
    if (empty($children)) {
        return isset($allPathologies[$uri]) && !$allPathologies[$uri]['is_category'] ? 1 : 0;
    }
    $sum = 0;
    foreach ($children as $childUri) {
        $sum += countLeavesUnderO($childUri, $childrenOf, $allPathologies);
    }
    return $sum;
}

// 7) Stats finales (basées sur les pathologies "feuilles")
$totalActives  = 0;
$totalInactive = 0;
foreach ($leafPathos as $p) {
    if ($p['inactive']) $totalInactive++;
    else $totalActives++;
}
$totalCategories = count($categories);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Gestion de l'ontologie · APA4CAD Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
     background:#f4f7fb;color:#1e293b;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:#2563eb;text-decoration:none}

/* Topbar admin sombre (cohérent avec le dashboard) */
.topbar-admin{background:linear-gradient(135deg,#0f172a,#1e293b);border-bottom:2px solid #1d4ed8;
              padding:14px 0;color:#f8fafc}
.topbar-inner{max-width:1400px;margin:0 auto;padding:0 28px;display:flex;align-items:center;gap:24px}
.topbar-brand{font-weight:700;font-size:17px;color:#fff;display:flex;align-items:center;gap:10px}
.topbar-brand::before{content:"";width:5px;height:22px;background:#3b82f6;border-radius:2px}
.admin-badge{background:#dc2626;color:#fff;font-size:10px;font-weight:800;
             padding:3px 9px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.topbar-nav{display:flex;gap:6px;margin-left:auto;align-items:center}
.topbar-nav a{padding:8px 14px;border-radius:8px;color:#cbd5e1;font-weight:500;font-size:13px;transition:.15s}
.topbar-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.topbar-nav a.active{background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600}
.topbar-nav .logout-btn{background:#dc2626;color:#fff !important;padding:7px 14px;border-radius:8px;font-weight:600;font-size:12px}

.app{max-width:1400px;margin:0 auto;padding:28px}

.dash-header{margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px}
.dash-header h1{margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a}
.dash-header p{margin:0;color:#64748b;font-size:13px}

.btn-primary{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;
             border-radius:11px;padding:11px 22px;font-size:14px;font-weight:700;
             cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:8px;
             box-shadow:0 6px 16px rgba(37,99,235,.3);transition:.15s;text-decoration:none}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(37,99,235,.4)}

/* Stats : 3 cards */
.onto-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
@media(max-width:700px){.onto-stats{grid-template-columns:1fr}}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 22px;
           box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid;
           display:flex;flex-direction:column;gap:6px;transition:.2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08)}
.stat-num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-.5px}
.stat-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
.sc-active{border-left-color:#059669} .sc-active .stat-num{color:#059669}
.sc-inactive{border-left-color:#94a3b8} .sc-inactive .stat-num{color:#475569}
.sc-cat{border-left-color:#7c3aed} .sc-cat .stat-num{color:#7c3aed}

/* Toolbar : recherche + bouton ajouter */
.toolbar{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;
         margin-bottom:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.toolbar input[type="text"]{flex:1;min-width:200px;padding:10px 14px;border:1px solid #e5e7eb;
                              border-radius:9px;font-size:14px;font-family:inherit}
.toolbar input[type="text"]:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}

/* Card des pathologies */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:0;
      box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:14px;overflow:hidden}

/* ═══════════════════════════════════════════════════════════════════════
   ARBRE HIÉRARCHIQUE (réplique l'app praticien)
   ═══════════════════════════════════════════════════════════════════════ */
.onto-tree{padding:6px 0}
.tree-node{margin:0}

/* Catégorie (branche dépliable) */
.tree-cat{display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;
          user-select:none;border-radius:10px;margin:2px 0;transition:.15s;font-weight:700;
          color:#1e293b;font-size:14px}
.tree-cat:hover{background:#f1f5f9}
.tree-cat .chevron{display:inline-flex;width:16px;height:16px;align-items:center;justify-content:center;
                   font-size:10px;color:#64748b;transition:transform .2s;flex-shrink:0}
.tree-node.open > .tree-cat .chevron{transform:rotate(90deg)}
.tree-cat .icon{font-size:14px;flex-shrink:0}
.tree-cat .lbl{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tree-cat .count{font-size:11px;font-weight:600;color:#64748b;background:#e5e7eb;
                  padding:2px 9px;border-radius:10px;flex-shrink:0}

/* Niveau 1 (catégorie racine) : un peu plus marquée */
.tree-node[data-level="0"] > .tree-cat{background:#f8fafc;border:1px solid #e5e7eb;
                                         font-size:14px;font-weight:700}
.tree-node[data-level="0"] > .tree-cat:hover{background:#eff6ff;border-color:#bfdbfe}
.tree-node[data-level="0"].open > .tree-cat{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}

/* Enfants (cachés tant que pas open) */
.tree-children{display:none;padding-left:24px;border-left:2px dashed #e5e7eb;margin-left:14px}
.tree-node.open > .tree-children{display:block}

/* Pathologie feuille */
.tree-leaf{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:2px 0;border-radius:9px;
           transition:.15s;border:1px solid transparent}
.tree-leaf:hover{background:#f8fafc;border-color:#e0e7ff}
.tree-leaf.inactive{opacity:.6;background:#fafbfc}
.tree-leaf .leaf-dot{width:5px;height:5px;border-radius:50%;background:#94a3b8;flex-shrink:0;margin-left:2px}
.tree-leaf.inactive .leaf-dot{background:#cbd5e1}
.tree-leaf .leaf-name{flex:1;font-size:13.5px;color:#1e293b;font-weight:500;
                       display:flex;align-items:center;gap:8px;min-width:0}
.tree-leaf .leaf-name .label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tree-leaf .badge-usage{font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;
                         border:1px solid #fcd34d;padding:1px 8px;border-radius:10px;flex-shrink:0}
.tree-leaf .badge-inactive{font-size:9px;font-weight:700;background:#f1f5f9;color:#475569;
                            text-transform:uppercase;padding:2px 7px;border-radius:5px;letter-spacing:.4px;flex-shrink:0}
.tree-leaf .actions{display:flex;gap:6px;opacity:0;transition:.15s;flex-shrink:0}
.tree-leaf:hover .actions{opacity:1}
.tree-leaf.inactive .actions{opacity:1}

.btn-action{padding:5px 11px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;
            border:1px solid;background:#fff;font-family:inherit;transition:.15s;
            text-decoration:none;display:inline-block;white-space:nowrap}
.btn-edit{border-color:#bfdbfe;color:#1d4ed8}
.btn-edit:hover{background:#eff6ff}
.btn-deact{border-color:#fcd34d;color:#92400e}
.btn-deact:hover{background:#fef3c7}
.btn-deact.disabled{opacity:.45;cursor:not-allowed;pointer-events:none}
.btn-react{border-color:#a7f3d0;color:#047857}
.btn-react:hover{background:#dcfce7}

/* État "filtré" (recherche active) */
.tree-node.search-hidden{display:none}
.tree-leaf.search-hidden{display:none}
/* Mise en évidence du texte qui matche la recherche */
.search-match{background:#fef08a;color:#854d0e;padding:0 2px;border-radius:3px;font-weight:700}

/* Flash messages */
.flash{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;
       display:flex;align-items:flex-start;gap:10px;line-height:1.5}
.flash-success{background:#dcfce7;border:1px solid #6ee7b7;color:#065f46}
.flash-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}

/* Modales */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);
               display:none;align-items:flex-start;justify-content:center;
               z-index:1000;padding:60px 20px;overflow-y:auto;animation:fadeIn .2s ease-out}
.modal-overlay.open{display:flex}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:#fff;border-radius:18px;width:100%;max-width:680px;
       box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;
       animation:slideIn .25s cubic-bezier(.4,0,.2,1)}
@keyframes slideIn{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
.modal-head h2{margin:0;font-size:18px;font-weight:800;color:#1e293b}
.modal-close{background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer;
             padding:4px 10px;border-radius:8px;font-family:inherit}
.modal-close:hover{background:#f1f5f9;color:#1e293b}
.modal-body{padding:20px 24px;max-height:60vh;overflow-y:auto}
.modal-foot{padding:14px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;gap:10px;background:#f8fafc}

.field{margin-bottom:16px}
.field label{display:block;font-size:12px;font-weight:600;color:#475569;
              margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input[type="text"], .field select{width:100%;padding:10px 12px;border:1.5px solid #e5e7eb;
             border-radius:9px;font-size:14px;font-family:inherit;background:#fff}
.field input:focus, .field select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.field .hint{font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic}

.category-toggle{display:flex;gap:10px;margin-bottom:8px}
.category-toggle label{flex:1;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:9px;
                        cursor:pointer;font-size:12px;font-weight:600;color:#475569;
                        display:flex;align-items:center;gap:6px;text-align:center;justify-content:center;
                        transition:.15s;text-transform:none;letter-spacing:0}
.category-toggle input[type="radio"]{display:none}
.category-toggle input[type="radio"]:checked + label{background:#eff6ff;border-color:#2563eb;color:#1d4ed8}

.checklist{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;
            max-height:200px;overflow-y:auto;padding:10px;background:#fafbfc;border:1px solid #e5e7eb;border-radius:9px}
.checklist label{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;
                  font-size:13px;font-weight:500;cursor:pointer;transition:.1s;text-transform:none;letter-spacing:0}
.checklist label:hover{background:#fff}
.checklist input[type="checkbox"]{width:15px;height:15px;cursor:pointer;flex-shrink:0}

.section-title{font-size:13px;font-weight:700;color:#1e293b;margin:18px 0 8px;
                display:flex;align-items:center;gap:8px}
.section-icon{font-size:14px}

.btn-cancel{background:#fff;color:#64748b;border:1px solid #e5e7eb;border-radius:9px;
            padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-cancel:hover{background:#f8fafc;color:#1e293b}
.btn-submit{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:9px;
            padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
            box-shadow:0 4px 12px rgba(37,99,235,.3)}
.btn-submit:hover{transform:translateY(-1px)}
.btn-danger{background:#dc2626;color:#fff;border:none;border-radius:9px;padding:10px 18px;
            font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}

.empty{padding:30px 20px;text-align:center;color:#94a3b8;font-style:italic;font-size:13px}
</style>
</head>
<body>

<div class="topbar-admin">
    <div class="topbar-inner">
        <a href="index.php" class="topbar-brand">APA4CAD</a>
        <span class="admin-badge">Admin</span>
        <nav class="topbar-nav">
            <a href="index.php">📊 Dashboard</a>
            <a href="praticiens.php">👥 Praticiens</a>
            <a href="ontology.php" class="active">🩺 Ontologie</a>
            <a href="../welcome.php">← Accueil</a>
            <a href="change_password.php">🔑 Mon compte</a>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </nav>
    </div>
</div>

<div class="app">

    <div class="dash-header">
        <div>
            <h1>🩺 Gestion de l'ontologie</h1>
            <p>Gestion des pathologies de la base de connaissance médicale.</p>
        </div>
        <button class="btn-primary" onclick="openModal('modal-add')">＋ Ajouter une pathologie</button>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= hO($flash['type']) ?>">
            <span><?= $flash['type'] === 'success' ? '✓' : '⚠' ?></span>
            <span><?= hO($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="onto-stats">
        <div class="stat-card sc-active">
            <div class="stat-num"><?= $totalActives ?></div>
            <div class="stat-lbl">Pathologies actives</div>
        </div>
        <div class="stat-card sc-inactive">
            <div class="stat-num"><?= $totalInactive ?></div>
            <div class="stat-lbl">Pathologies inactives</div>
        </div>
        <div class="stat-card sc-cat">
            <div class="stat-num"><?= $totalCategories ?></div>
            <div class="stat-lbl">Catégories</div>
        </div>
    </div>

    <!-- Toolbar : recherche -->
    <div class="toolbar">
        <input type="text" id="search-input" placeholder="🔍 Rechercher une pathologie..."
               autocomplete="off">
    </div>

    <!-- Arbre hiérarchique des pathologies (réplique l'app praticien) -->
    <div class="card" style="padding:16px 18px">
        <?php if (empty($leafPathos)): ?>
            <div class="empty">Aucune pathologie dans l'ontologie pour l'instant.</div>
        <?php else: ?>
            <div class="onto-tree" id="onto-tree">
                <?php foreach ($rootCategories as $rootUri):
                    if (isset($allPathologies[$rootUri])):
                        renderTreeNodeO($rootUri, 0, $allPathologies, $childrenOf, $leafPathos, $pathoUsage, $pathoRecs);
                    endif;
                endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ━━━ MODALE : Ajouter une pathologie ━━━ -->
<div class="modal-overlay" id="modal-add">
    <div class="modal">
        <div class="modal-head">
            <h2>＋ Ajouter une pathologie</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-add')">✕</button>
        </div>
        <form method="post" id="form-add">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">

                <div class="field">
                    <label>Nom de la pathologie *</label>
                    <input type="text" name="name" required placeholder="Ex : Asthme allergique">
                    <div class="hint">Le nom sera automatiquement converti en identifiant OWL (Asthme_allergique).</div>
                </div>

                <div class="field">
                    <label>Catégorie parente</label>
                    <div class="category-toggle">
                        <input type="radio" name="cat_choice" id="cat-existing" value="existing" checked>
                        <label for="cat-existing">📁 Catégorie existante</label>
                        <input type="radio" name="cat_choice" id="cat-new" value="new">
                        <label for="cat-new">＋ Nouvelle catégorie</label>
                    </div>
                    <select name="category_uri" id="cat-select">
                        <option value="">— Aucune (directement sous Pathologie) —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= hO($cat['uri']) ?>"><?= hO($cat['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="new_category_name" id="cat-new-input" style="display:none"
                           placeholder="Nom de la nouvelle catégorie (ex : Maladies respiratoires)">
                </div>

                <div class="section-title">
                    <span class="section-icon">✅</span> Activités recommandées
                </div>
                <div class="checklist">
                    <?php foreach ($activities as $act): ?>
                        <label>
                            <input type="checkbox" name="recommended[]" value="<?= hO($act['uri']) ?>">
                            <?= hO($act['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="section-title">
                    <span class="section-icon">🚫</span> Contre-indications
                </div>
                <div class="checklist">
                    <?php foreach ($activities as $act): ?>
                        <label>
                            <input type="checkbox" name="contraindicated[]" value="<?= hO($act['uri']) ?>">
                            <?= hO($act['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-add')">Annuler</button>
                <button type="submit" class="btn-submit">Créer la pathologie →</button>
            </div>
        </form>
    </div>
</div>

<!-- ━━━ MODALE : Modifier une pathologie ━━━ -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal">
        <div class="modal-head">
            <h2>✏ Modifier une pathologie</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-edit')">✕</button>
        </div>
        <form method="post" id="form-edit">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="patho_uri" id="edit-patho-uri">
            <div class="modal-body">

                <div class="field">
                    <label>Nom de la pathologie *</label>
                    <input type="text" name="name" id="edit-name" required>
                    <div class="hint">Tu peux modifier le libellé affiché. L'identifiant OWL ne change pas.</div>
                </div>

                <div class="section-title">
                    <span class="section-icon">✅</span> Activités recommandées
                </div>
                <div class="checklist" id="edit-recommended-list">
                    <?php foreach ($activities as $act): ?>
                        <label>
                            <input type="checkbox" name="recommended[]" value="<?= hO($act['uri']) ?>"
                                   data-act="<?= hO($act['uri']) ?>" class="edit-rec-cb">
                            <?= hO($act['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="section-title">
                    <span class="section-icon">🚫</span> Contre-indications
                </div>
                <div class="checklist" id="edit-ci-list">
                    <?php foreach ($activities as $act): ?>
                        <label>
                            <input type="checkbox" name="contraindicated[]" value="<?= hO($act['uri']) ?>"
                                   data-act="<?= hO($act['uri']) ?>" class="edit-ci-cb">
                            <?= hO($act['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-edit')">Annuler</button>
                <button type="submit" class="btn-submit">Enregistrer →</button>
            </div>
        </form>
    </div>
</div>

<!-- ━━━ MODALE : Confirmation désactivation ━━━ -->
<div class="modal-overlay" id="modal-deactivate">
    <div class="modal" style="max-width:480px">
        <div class="modal-head">
            <h2>🗑 Désactiver une pathologie</h2>
            <button type="button" class="modal-close" onclick="closeModal('modal-deactivate')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="patho_uri" id="deact-patho-uri">
            <div class="modal-body">
                <p style="margin:0 0 14px;line-height:1.6">
                    Tu vas désactiver la pathologie <strong id="deact-patho-name">...</strong>.
                </p>
                <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;
                            padding:12px 14px;font-size:13px;color:#78350f;line-height:1.5">
                    💡 <strong>Soft delete</strong> : la pathologie ne sera plus proposée dans
                    l'application mais restera dans Fuseki avec une marque « inactive ».
                    Elle pourra être réactivée à tout moment.
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal('modal-deactivate')">Annuler</button>
                <button type="submit" class="btn-danger">Confirmer la désactivation</button>
            </div>
        </form>
    </div>
</div>

<script>
// ─── Modales : ouverture / fermeture ──────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        const first = document.querySelector('#' + id + ' input[type="text"]');
        if (first) first.focus();
    }, 100);
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
    if (e.target === o) closeModal(o.id);
}));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => closeModal(o.id));
});

// ─── Modale "Ajouter" : toggle entre catégorie existante / nouvelle ──
const catExisting = document.getElementById('cat-existing');
const catNew      = document.getElementById('cat-new');
const catSelect   = document.getElementById('cat-select');
const catNewInput = document.getElementById('cat-new-input');
function refreshCatToggle() {
    if (catNew.checked) {
        catSelect.style.display = 'none';
        catSelect.value = '';
        catNewInput.style.display = 'block';
        setTimeout(() => catNewInput.focus(), 50);
    } else {
        catSelect.style.display = 'block';
        catNewInput.style.display = 'none';
        catNewInput.value = '';
    }
}
catExisting.addEventListener('change', refreshCatToggle);
catNew.addEventListener('change', refreshCatToggle);

// ─── Modale "Modifier" : pré-remplir avec les données existantes ──────
function openEditModal(pathoData) {
    document.getElementById('edit-patho-uri').value = pathoData.uri;
    document.getElementById('edit-name').value      = pathoData.label;
    // Décocher toutes les cases puis cocher les bonnes
    document.querySelectorAll('.edit-rec-cb').forEach(cb => {
        cb.checked = pathoData.recommended.includes(cb.dataset.act);
    });
    document.querySelectorAll('.edit-ci-cb').forEach(cb => {
        cb.checked = pathoData.contraindicated.includes(cb.dataset.act);
    });
    openModal('modal-edit');
}

// ─── Modale "Désactiver" ─────────────────────────────────────────────
function confirmDeactivate(uri, name) {
    document.getElementById('deact-patho-uri').value = uri;
    document.getElementById('deact-patho-name').textContent = name;
    openModal('modal-deactivate');
}

// ─── Recherche temps réel dans l'arbre ───────────────────────────────
const searchInput = document.getElementById('search-input');
const tree        = document.getElementById('onto-tree');

searchInput?.addEventListener('input', () => {
    const term = searchInput.value.trim().toLowerCase();

    if (tree === null) return;

    if (term === '') {
        // Reset : tout réafficher, tout replier
        tree.querySelectorAll('.tree-node, .tree-leaf').forEach(el => el.classList.remove('search-hidden'));
        tree.querySelectorAll('.tree-node').forEach(n => n.classList.remove('open'));
        return;
    }

    // 1) Marquer chaque feuille selon qu'elle match ou pas
    tree.querySelectorAll('.tree-leaf').forEach(leaf => {
        const hay = leaf.dataset.search || '';
        if (hay.includes(term)) {
            leaf.classList.remove('search-hidden');
        } else {
            leaf.classList.add('search-hidden');
        }
    });

    // 2) Pour chaque catégorie : afficher seulement si elle contient au moins une feuille visible
    //    On parcourt de bas en haut (les plus profondes d'abord)
    const allNodes = Array.from(tree.querySelectorAll('.tree-node'));
    // Tri par profondeur DOM (plus profond d'abord)
    allNodes.sort((a, b) => getDepth(b) - getDepth(a));

    allNodes.forEach(node => {
        const visibleLeaves = node.querySelectorAll(':scope .tree-leaf:not(.search-hidden)').length;
        // Compter aussi les sous-catégories visibles
        const visibleSubCats = Array.from(node.querySelectorAll(':scope .tree-node')).filter(n =>
            !n.classList.contains('search-hidden')
        ).length;

        if (visibleLeaves > 0 || visibleSubCats > 0) {
            node.classList.remove('search-hidden');
            node.classList.add('open');           // déplier auto les branches matchantes
        } else {
            node.classList.add('search-hidden');
            node.classList.remove('open');
        }
    });
});

// Fonction utilitaire : profondeur d'un nœud dans l'arbre
function getDepth(el) {
    let depth = 0;
    let p = el.parentElement;
    while (p && p !== tree) {
        if (p.classList.contains('tree-node')) depth++;
        p = p.parentElement;
    }
    return depth;
}
</script>

</body>
</html>
