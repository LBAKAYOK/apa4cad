<?php
declare(strict_types=1);
set_time_limit(300);

require_once __DIR__ . '/patient_session.php';

// Si on a un prescription_id dans l'URL, on peut récupérer les données depuis Fuseki
// (mode post-enregistrement). Sinon, on attend que la session contienne les données.
$prescriptionIdInUrl = $_GET['prescription_id'] ?? '';

if ($prescriptionIdInUrl === '') {
    // Mode "parcours en cours" : on a besoin de la session
    requirePathologiesSelected();
    requirePatientSelected();
}

const FUSEKI_ENDPOINT = 'http://localhost:3030/mononto/query';
const NS              = 'http://www.semanticweb.org/mmolina/ontologies/2025/11/untitled-ontology-50#';
const OLLAMA_ENDPOINT = 'http://127.0.0.1:11434/api/generate';
const OLLAMA_MODEL    = 'llama3.2:1b';

function sparqlQuery(string $query): array {
    $url = FUSEKI_ENDPOINT . '?query=' . urlencode($query) . '&output=json';
    $ctx = stream_context_create(['http' => ['method'=>'GET','header'=>"Accept: application/sparql-results+json\r\n",'timeout'=>30,'ignore_errors'=>true]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['ok' => false];
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['results']['bindings'])) return ['ok' => false];
    return ['ok' => true, 'bindings' => $data['results']['bindings']];
}

// ─────────────────────────────────────────────────────────────────────────
//  Mode "post-enregistrement" : recharger la session depuis Fuseki
// ─────────────────────────────────────────────────────────────────────────
if ($prescriptionIdInUrl !== '') {
    $prescUri = NS . $prescriptionIdInUrl;

    // 1) Vérifier l'existence et récupérer le patient
    $infoQ = "PREFIX ex: <" . NS . ">
              PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
              SELECT ?patient ?nom ?prenom ?age ?dossier ?genreLabel WHERE {
                  <$prescUri> a ex:Prescription ;
                              ex:concerne ?patient .
                  OPTIONAL { ?patient ex:aPourNom ?nom }
                  OPTIONAL { ?patient ex:aPourPrenom ?prenom }
                  OPTIONAL { ?patient ex:aPourAge ?age }
                  OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
                  OPTIONAL { ?patient ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
              } LIMIT 1";
    $infoRes = sparqlQuery($infoQ);

    if (!$infoRes['ok'] || empty($infoRes['bindings'])) {
        die('<div style="padding:30px;text-align:center;font-family:Arial">
              <h2 style="color:#b91c1c">❌ Prescription introuvable</h2>
              <p>L\'identifiant <code>' . htmlspecialchars($prescriptionIdInUrl) . '</code> n\'existe pas dans la base.</p>
              <p><a href="prescriptions.php" style="color:#2563eb">← Retour à l\'historique</a></p>
            </div>');
    }

    $b = $infoRes['bindings'][0];
    $patientUri = $b['patient']['value'] ?? '';
    $patientNom = $b['nom']['value'] ?? '';
    $patientPrenom = $b['prenom']['value'] ?? '';

    // 2) Recharger le patient en session (variables directes)
    $_SESSION['patient_uri']     = $patientUri;
    $_SESSION['patient_nom']     = $patientNom;
    $_SESSION['patient_prenom']  = $patientPrenom;
    $_SESSION['patient_age']     = $b['age']['value'] ?? '';
    $_SESSION['patient_dossier'] = $b['dossier']['value'] ?? '';
    $_SESSION['patient_genre']   = $b['genreLabel']['value'] ?? '';
    if (str_contains($patientUri, '#')) {
        $_SESSION['patient_fragment'] = substr($patientUri, strrpos($patientUri, '#') + 1);
    }

    // 3) Recharger les pathologies du patient depuis Fuseki
    $pathosQ = "PREFIX ex: <" . NS . ">
                SELECT ?patho WHERE {
                    <$patientUri> ex:aPourPathologie ?patho .
                }";
    $pathosRes = sparqlQuery($pathosQ);
    $pathoUris = [];
    if ($pathosRes['ok']) {
        foreach ($pathosRes['bindings'] as $r) {
            $pathoUris[] = $r['patho']['value'];
        }
    }
    if (!empty($pathoUris)) {
        $_SESSION['parcours_pathologies'] = $pathoUris;
    }

    // 4) Recharger les activités de la prescription depuis Fuseki
    $actsQ = "PREFIX ex: <" . NS . ">
              SELECT DISTINCT ?activite WHERE {
                  <$prescUri> ex:contient ?activite .
              }";
    $actsRes = sparqlQuery($actsQ);
    $actUris = [];
    if ($actsRes['ok']) {
        foreach ($actsRes['bindings'] as $r) {
            $actInstanceUri = $r['activite']['value'];
            $classQ = "PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
                       PREFIX owl: <http://www.w3.org/2002/07/owl#>
                       SELECT ?type WHERE {
                           <$actInstanceUri> rdf:type ?type .
                           FILTER(STRSTARTS(STR(?type), \"" . NS . "\"))
                           FILTER(?type != owl:NamedIndividual)
                       } LIMIT 1";
            $classRes = sparqlQuery($classQ);
            if ($classRes['ok'] && !empty($classRes['bindings'])) {
                $classUri = $classRes['bindings'][0]['type']['value'] ?? '';
                if ($classUri !== '' && !in_array($classUri, $actUris, true)) {
                    $actUris[] = $classUri;
                }
            }
        }
    }
    if (!empty($actUris)) {
        $_SESSION['parcours_activites'] = $actUris;
    }

    // 5) Recharger les freins/leviers/CI depuis les rdfs:comment de la prescription
    $commentsQ = "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                  SELECT ?comment WHERE {
                      <$prescUri> rdfs:comment ?comment .
                  }";
    $commentsRes = sparqlQuery($commentsQ);
    $freinsLabels = [];
    $leviersLabels = [];
    $cisFromFuseki = [];
    if ($commentsRes['ok']) {
        foreach ($commentsRes['bindings'] as $r) {
            $txt = $r['comment']['value'] ?? '';
            if ($txt === '') continue;
            if (str_starts_with($txt, '[CI]')) {
                $ciTxt = trim(substr($txt, 4));
                if (preg_match('/^(.*?)\s*—\s*bloquée par\s*(.*)$/u', $ciTxt, $m)) {
                    $cisFromFuseki[] = [
                        'activity' => trim($m[1]),
                        'reasons'  => array_map('trim', explode(',', $m[2])),
                    ];
                }
            } elseif (str_starts_with($txt, '[FREIN]')) {
                $freinsLabels[] = trim(substr($txt, 7));
            } elseif (str_starts_with($txt, '[LEVIER]')) {
                $leviersLabels[] = trim(substr($txt, 8));
            }
        }
    }

    $_SESSION['parcours_freins'] = $freinsLabels;
    $_SESSION['parcours_leviers'] = $leviersLabels;
    $_SESSION['parcours_contraindications'] = $cisFromFuseki;
}
function localName(string $uri): string {
    if (str_contains($uri, '#')) return substr($uri, strrpos($uri, '#') + 1);
    if (str_contains($uri, '/')) return substr($uri, strrpos($uri, '/') + 1);
    return $uri;
}
function prettyLabel(string $name): string {
    $name = str_replace('_', ' ', $name);
    $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    return trim((string)$name);
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function categoryTitle(string $local): string {
    return match ($local) {
        'Cancer'=>'Cancer','Hypertension_arterielle'=>'Hypertension arterielle','Obesite'=>'Obesite',
        'Diabete'=>'Diabete','DT1'=>'Diabete de type 1','DT2'=>'Diabete de type 2',
        'AngorStable'=>'Angor stable','Myocardite'=>'Myocardite','Lombalgie'=>'Lombalgie','Arthrose'=>'Arthrose',
        default => prettyLabel($local),
    };
}
function modalityLabel(string $prop): string {
    return match ($prop) {
        'aPourIntensite'=>'Intensite','aPourFrequence'=>'Frequence',
        'aPourFrequenceHebdomadaire'=>'Frequence hebdomadaire','aPourDuree'=>'Duree (min)',
        'aPourNbRepetitions'=>'Repetitions','aPourNbSeries'=>'Series','aPourNbExercices'=>'Exercices',
        'aPour1RM_Bas'=>'Charge membres inf.','aPour1RM_Haut'=>'Charge membres sup.',
        default => prettyLabel($prop),
    };
}
function loadRecommendations(string $uri): array {
    $q = 'PREFIX ex: <'.NS.'> PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#> PREFIX owl: <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?nomActivite ?adaptation WHERE {
  VALUES ?patho { <'.$uri.'> }
  { ?patho rdfs:subClassOf+ ?s . ?s rdfs:subClassOf ?e . ?e owl:intersectionOf ?l .
    ?l rdf:rest*/rdf:first ?r . ?r owl:onProperty ex:aPourActiviteRecommandee ; owl:someValuesFrom ?c .
    FILTER(isIRI(?c)) BIND(STRAFTER(STR(?c),"#") AS ?nomActivite)
  } UNION {
    ?patho rdfs:subClassOf+ ?s . ?s rdfs:subClassOf ?e . ?e owl:intersectionOf ?l .
    ?l rdf:rest*/rdf:first ?r . ?r owl:onProperty ex:aPourActiviteRecommandee ; owl:someValuesFrom ?c .
    FILTER(isBlank(?c)) ?c owl:intersectionOf ?l2 . ?l2 rdf:rest*/rdf:first ?elt .
    FILTER(isIRI(?elt)) FILTER(?elt!=ex:Pathologie) FILTER(?elt!=ex:ActivitePhysique)
    FILTER(?elt!=ex:Adaptation) FILTER(?elt!=ex:Frein)
    BIND(STRAFTER(STR(?elt),"#") AS ?nomActivite)
    OPTIONAL { ?l2 rdf:rest*/rdf:first ?r2 . ?r2 owl:onProperty ex:aPourAdaptation ;
      owl:someValuesFrom ?ad . BIND(STRAFTER(STR(?ad),"#") AS ?adaptation) }
  }
} ORDER BY ?nomActivite';
    $res = sparqlQuery($q); if (!$res['ok']) return [];
    $g = [];
    foreach ($res['bindings'] as $row) {
        $act=$row['nomActivite']['value']??''; $adap=$row['adaptation']['value']??'';
        if ($act==='') continue;
        if (!isset($g[$act])) $g[$act]=['activity'=>$act,'adaptations'=>[]];
        if ($adap!==''&&!in_array($adap,$g[$act]['adaptations'],true)) $g[$act]['adaptations'][]=$adap;
    }
    return array_values($g);
}
function loadContraindications(string $uri): array {
    $q = 'PREFIX ex: <'.NS.'> PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#> PREFIX owl: <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?nomElement WHERE {
  VALUES ?patho { <'.$uri.'> }
  ?patho rdfs:subClassOf+ ?s . ?s rdfs:subClassOf ?e . ?e owl:intersectionOf ?l .
  ?l rdf:rest*/rdf:first ?r . ?r owl:onProperty ex:aPourContreIndication ; owl:someValuesFrom ?c .
  FILTER(isIRI(?c)) BIND(STRAFTER(STR(?c),"#") AS ?nomElement)
}';
    $res = sparqlQuery($q); if (!$res['ok']) return [];
    $items = [];
    foreach ($res['bindings'] as $row) { $v=$row['nomElement']['value']??''; if ($v!=='') $items[$v]=$v; }
    return array_values($items);
}
function isGlobalCI(string $ci): bool {
    // Seul ActivitePhysique est une CI vraiment globale (bloque tout)
    // Les autres CI (SportCollectif, SportDeCombat...) sont specifiques
    return $ci === 'ActivitePhysique';
}
function loadModalitiesPerActivity(array $actNames): array {
    if (empty($actNames)) return [];
    $vals=implode(' ',array_map(fn($n)=>'ex:'.$n,$actNames));
    $q='PREFIX ex: <'.NS.'> PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#> PREFIX owl: <http://www.w3.org/2002/07/owl#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
SELECT DISTINCT ?actName ?prop ?valueName WHERE {
  VALUES ?activity { '.$vals.' }
  BIND(STRAFTER(STR(?activity),"#") AS ?actName)
  VALUES ?targetProp { ex:aPourIntensite ex:aPourFrequence ex:aPourFrequenceHebdomadaire
    ex:aPourDuree ex:aPourNbRepetitions ex:aPourNbSeries ex:aPourNbExercices
    ex:aPour1RM_Bas_min ex:aPour1RM_Bas_max ex:aPour1RM_Haut_min ex:aPour1RM_Haut_max }
  BIND(STRAFTER(STR(?targetProp),"#") AS ?prop)
  { ?activity rdfs:subClassOf ?expr . ?expr owl:intersectionOf ?list .
    ?list rdf:rest*/rdf:first ?restr . ?restr owl:onProperty ?targetProp .
    { ?restr owl:someValuesFrom ?v . FILTER(isIRI(?v)) BIND(STRAFTER(STR(?v),"#") AS ?valueName) }
    UNION { ?restr owl:hasValue ?v . FILTER(isLiteral(?v)) BIND(STR(?v) AS ?valueName) }
    UNION { ?restr owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?f .
      { ?f xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?f xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) } }
  } UNION {
    ?activity rdfs:subClassOf+ ?parent . ?parent rdfs:subClassOf ?expr .
    ?expr owl:intersectionOf ?list . ?list rdf:rest*/rdf:first ?restr . ?restr owl:onProperty ?targetProp .
    { ?restr owl:someValuesFrom ?v . FILTER(isIRI(?v)) BIND(STRAFTER(STR(?v),"#") AS ?valueName) }
    UNION { ?restr owl:hasValue ?v . FILTER(isLiteral(?v)) BIND(STR(?v) AS ?valueName) }
    UNION { ?restr owl:someValuesFrom ?dt . FILTER(isBlank(?dt))
      ?dt owl:withRestrictions/rdf:rest*/rdf:first ?f .
      { ?f xsd:minInclusive ?v . BIND(CONCAT("min:",STR(?v)) AS ?valueName) }
      UNION { ?f xsd:maxInclusive ?v . BIND(CONCAT("max:",STR(?v)) AS ?valueName) } }
  }
} ORDER BY ?actName ?prop ?valueName';
    $res=sparqlQuery($q); if (!$res['ok']) return [];
    $items=[];
    foreach ($res['bindings'] as $row) {
        $act=$row['actName']['value']??''; $prop=$row['prop']['value']??''; $val=$row['valueName']['value']??'';
        if ($act===''||$prop===''||$val==='') continue;
        $items[$act][$prop][]=$val;
    }
    foreach ($items as &$props) {
        foreach ($props as &$vArr) {
            $vArr=array_values(array_unique($vArr));
            $mins=array_values(array_filter($vArr,fn($v)=>str_starts_with($v,'min:')));
            $maxs=array_values(array_filter($vArr,fn($v)=>str_starts_with($v,'max:')));
            $rest=array_values(array_filter($vArr,fn($v)=>!str_starts_with($v,'min:')&&!str_starts_with($v,'max:')));
            if (!empty($mins)||!empty($maxs)) {
                $mn=!empty($mins)?substr($mins[0],4):null; $mx=!empty($maxs)?substr($maxs[0],4):null;
                $vArr=array_merge($rest,[($mn&&$mx)?"$mn - $mx":($mn??$mx)]);
            }
        }
        foreach (['aPour1RM_Bas'=>['aPour1RM_Bas_min','aPour1RM_Bas_max'],'aPour1RM_Haut'=>['aPour1RM_Haut_min','aPour1RM_Haut_max']] as $merged=>$parts) {
            if (isset($props[$parts[0]])||isset($props[$parts[1]])) {
                $mn=$props[$parts[0]][0]??null; $mx=$props[$parts[1]][0]??null;
                $props[$merged]=[($mn&&$mx)?"$mn - $mx %":(($mn??$mx).' %')];
                unset($props[$parts[0]],$props[$parts[1]]);
            }
        }
    }
    return $items;
}

// ── Chargement freins & leviers ───────────────────────────────────────────
function loadFreinsAndLeviers(): array {
    $q = 'PREFIX ex: <' . NS . '> PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#> PREFIX owl: <http://www.w3.org/2002/07/owl#>
SELECT DISTINCT ?frein ?freinType ?levier
WHERE {
  ?frein rdfs:subClassOf ?anon . ?anon owl:intersectionOf ?list .
  ?list rdf:rest*/rdf:first ?freinType . FILTER(isIRI(?freinType))
  FILTER(STRSTARTS(STR(?freinType),"' . NS . '"))
  ?freinType rdfs:subClassOf ex:Frein . FILTER(?freinType != ex:Frein)
  FILTER(STRSTARTS(STR(?frein),"' . NS . '")) FILTER(?frein != ?freinType)
  OPTIONAL {
    ?list rdf:rest*/rdf:first ?restr . ?restr owl:onProperty ex:aPourLevier .
    { ?restr owl:someValuesFrom ?lev . FILTER(isIRI(?lev))
      FILTER(STRSTARTS(STR(?lev),"' . NS . '")) BIND(STRAFTER(STR(?lev),"#") AS ?levier) }
    UNION
    { ?restr owl:someValuesFrom ?union . FILTER(isBlank(?union))
      ?union owl:unionOf/rdf:rest*/rdf:first ?lev .
      FILTER(isIRI(?lev)) FILTER(STRSTARTS(STR(?lev),"' . NS . '"))
      BIND(STRAFTER(STR(?lev),"#") AS ?levier) }
  }
} ORDER BY ?freinType ?frein ?levier';

    $res = sparqlQuery($q);
    if (!$res['ok']) return [];

    $typeMeta = [
        'FreinPhysique'        => ['label'=>'Frein physique',        'icon'=>'🩺','order'=>1],
        'FreinPsychologique'   => ['label'=>'Frein psychologique',   'icon'=>'🧠','order'=>2],
        'FreinMotivationnel'   => ['label'=>'Frein motivationnel',   'icon'=>'','order'=>3],
        'FreinSituationnel'    => ['label'=>'Frein situationnel',    'icon'=>'⏰','order'=>4],
        'FreinSocial'          => ['label'=>'Frein social',          'icon'=>'👥','order'=>5],
        'FreinEnvironnemental' => ['label'=>'Frein environnemental', 'icon'=>'🌍','order'=>6],
    ];

    $items = [];
    foreach ($res['bindings'] as $row) {
        $frein     = localName($row['frein']['value']     ?? '');
        $typeLocal = localName($row['freinType']['value'] ?? '');
        $levier    = $row['levier']['value'] ?? '';
        if ($frein===''||isset($typeMeta[$frein])) continue;
        if (!isset($items[$frein])) {
            $m = $typeMeta[$typeLocal] ?? ['label'=>prettyLabel($typeLocal),'icon'=>'•','order'=>99];
            $items[$frein] = ['id'=>$frein,'label'=>prettyLabel($frein),'typeKey'=>$typeLocal,
                'typeLabel'=>$m['label'],'typeIcon'=>$m['icon'],'typeOrder'=>$m['order'],'leviers'=>[]];
        }
        if ($levier!==''&&!in_array($levier,$items[$frein]['leviers'],true))
            $items[$frein]['leviers'][]=$levier;
    }
    usort($items,fn($a,$b)=>$a['typeOrder']<=>$b['typeOrder']?:strcmp($a['label'],$b['label']));
    $grouped=[];
    foreach ($items as $d) $grouped[$d['typeLabel']][]=$d;
    return $grouped;
}

// Parametres
$selected=$_GET['pathologies']??getParcoursPathologies();
if (!is_array($selected)) $selected=[$selected];
$selected=array_values(array_filter($selected,fn($v)=>is_string($v)&&$v!==''));

// ── Mode "post-enregistrement" : recharger depuis Fuseki si session vide ──
if (empty($selected) && $prescriptionIdInUrl !== '') {
    $prescUri = NS . $prescriptionIdInUrl;

    // 1) Charger le patient associé à la prescription
    $patientQ = "PREFIX ex: <" . NS . "> SELECT ?patient ?nom ?prenom ?age ?dossier ?genreLabel WHERE {
        <$prescUri> ex:concerne ?patient .
        OPTIONAL { ?patient ex:aPourNom ?nom }
        OPTIONAL { ?patient ex:aPourPrenom ?prenom }
        OPTIONAL { ?patient ex:aPourAge ?age }
        OPTIONAL { ?patient ex:aPourNumeroDossier ?dossier }
        OPTIONAL { ?patient ex:aPourGenre ?genre . BIND(STRAFTER(STR(?genre), \"#\") AS ?genreLabel) }
    } LIMIT 1";
    $patientRes = sparqlQuery($patientQ);
    if (!empty($patientRes['bindings'])) {
        $b = $patientRes['bindings'][0];
        $_SESSION['patient'] = [
            'uri'      => $b['patient']['value']      ?? '',
            'nom'      => $b['nom']['value']          ?? '',
            'prenom'   => $b['prenom']['value']       ?? '',
            'age'      => $b['age']['value']          ?? '',
            'dossier'  => $b['dossier']['value']      ?? '',
            'genre'    => $b['genreLabel']['value']   ?? '',
            'fullname' => trim(($b['prenom']['value'] ?? '') . ' ' . ($b['nom']['value'] ?? '')),
        ];
    }

    // 2) Charger les pathologies du patient
    if (isset($_SESSION['patient']['uri']) && $_SESSION['patient']['uri'] !== '') {
        $patU = $_SESSION['patient']['uri'];
        $pathoQ = "PREFIX ex: <" . NS . "> SELECT ?patho WHERE { <$patU> ex:aPourPathologie ?patho }";
        $pathoRes = sparqlQuery($pathoQ);
        if (!empty($pathoRes['bindings'])) {
            foreach ($pathoRes['bindings'] as $row) {
                $selected[] = $row['patho']['value'];
            }
        }
    }

    // 3) Charger les freins/leviers depuis les rdfs:comment de la prescription
    $commentsQ = "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                  SELECT ?comment WHERE { <$prescUri> rdfs:comment ?comment }";
    $commentsRes = sparqlQuery($commentsQ);
    if (!empty($commentsRes['bindings'])) {
        $_SESSION['parcours_freins'] = $_SESSION['parcours_freins'] ?? [];
        $_SESSION['parcours_leviers'] = $_SESSION['parcours_leviers'] ?? [];
        foreach ($commentsRes['bindings'] as $row) {
            $txt = $row['comment']['value'] ?? '';
            if (str_starts_with($txt, '[FREIN]')) {
                $label = trim(substr($txt, 7));
                $uri = NS . str_replace(' ', '', $label);
                if (!in_array($uri, $_SESSION['parcours_freins'], true)) {
                    $_SESSION['parcours_freins'][] = $uri;
                }
            } elseif (str_starts_with($txt, '[LEVIER]')) {
                $label = trim(substr($txt, 8));
                $uri = NS . str_replace(' ', '', $label);
                if (!in_array($uri, $_SESSION['parcours_leviers'], true)) {
                    $_SESSION['parcours_leviers'][] = $uri;
                }
            }
        }
    }
}

if (empty($selected)){header('Location: index.php');exit;}

$freinsCoches=$_GET['freins']??[];
if (!is_array($freinsCoches)) $freinsCoches=[$freinsCoches];
$freinsCoches=array_values(array_filter($freinsCoches,fn($v)=>is_string($v)&&$v!==''));

// Donnees
$pathologyLabels=[];$recoByPatho=[];$contraByPatho=[];
foreach ($selected as $uri){
    $pathologyLabels[$uri]=categoryTitle(localName($uri));
    $recoByPatho[$uri]=loadRecommendations($uri);
    $contraByPatho[$uri]=loadContraindications($uri);
}
$seenActs=[];$finalRecos=[];$finalContra=[];
foreach ($selected as $uri){
    $lbl=$pathologyLabels[$uri];
    foreach ($recoByPatho[$uri] as $item){
        $act=$item['activity'];
        if (!isset($seenActs[$act])){$seenActs[$act]=count($finalRecos);$item['pathoLabels']=[$lbl];$finalRecos[]=$item;}
        else{foreach ($item['adaptations'] as $adap) if (!in_array($adap,$finalRecos[$seenActs[$act]]['adaptations'],true)) $finalRecos[$seenActs[$act]]['adaptations'][]=$adap;}
    }
    foreach ($contraByPatho[$uri] as $c){if (!isset($finalContra[$c])) $finalContra[$c]=[];$finalContra[$c][]=$lbl;}
}
$okRecos=[];
foreach ($finalRecos as $item){
    $blocked=false;
    foreach ($selected as $uri) foreach ($contraByPatho[$uri] as $c) if (isGlobalCI($c)){$blocked=true;break 2;}
    if (!$blocked) $okRecos[]=$item;
}
$finalRecos=$okRecos;
$actNamesArr=array_map(fn($r)=>$r['activity'],$finalRecos);
$modalitiesPerActivity=loadModalitiesPerActivity($actNamesArr);
$freinsGrouped=loadFreinsAndLeviers();

// Prompt
$pathoList=implode(', ',array_values($pathologyLabels));
$actNames2=implode(", ",array_map(fn($r)=>prettyLabel($r['activity']),$finalRecos));
$ciNames2=implode(", ",array_map(fn($c)=>prettyLabel((string)$c),array_keys($finalContra)));
$freinsStr=!empty($freinsCoches)?implode(", ",array_map(fn($f)=>prettyLabel((string)$f),$freinsCoches)):"";
$actDetail="";
foreach ($finalRecos as $item){
    $act=prettyLabel($item['activity']);$mods=$modalitiesPerActivity[$item['activity']]??[];$parts=[];
    foreach ($mods as $prop=>$vArr){$flat=[];foreach ((array)$vArr as $v) $flat[]=is_array($v)?implode(', ',$v):(string)$v;if (!empty($flat)) $parts[]=modalityLabel($prop)." ".implode(", ",$flat);}
    $adaptations=$item['adaptations']??[];
    // Construction en phrases naturelles pour le LLM
$actDetail .= "- " . $act . " : ";
if (!empty($parts)) {
    // Reformuler les paramètres en phrase lisible
    $actDetail .= implode(", ", $parts);
}
if (!empty($adaptations)) {
    $actDetail .= ". Suggestion : " . implode(", ", array_map("prettyLabel", $adaptations));
}
$actDetail .= ".\n";
}
$prompt="Tu t'adresses directement au patient avec vous. Redige 3 paragraphes courts en francais, bienveillants. Utilise UNIQUEMENT les donnees ci-dessous. N'invente aucune activite.\n\nPathologies: ".$pathoList."\nActivites prescrites:\n".$actDetail."Activites interdites: ".$ciNames2."\n".($freinsStr?"Freins: ".$freinsStr."\n":"")."\nParagraphe 1: pathologies et importance APA. Paragraphe 2: activites exactes avec parametres et interdictions. Paragraphe 3: encouragement. Commencer par Dans le cadre de votre suivi. Pas de titre.";

$rapportUrl='rapport.php?'.http_build_query(['pathologies'=>$selected]);
$date=(new DateTime())->format('d/m/Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Formulaire de prescription APA</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Times+New+Roman&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',serif;background:#f5f5f0;color:#000;font-size:13px;-webkit-font-smoothing:antialiased}
.actions{display:flex;gap:8px;padding:12px 20px;background:#fff;border-bottom:1px solid #ddd;position:sticky;top:0;z-index:100}
.btn{border:1px solid #ccc;background:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:sans-serif;text-decoration:none;color:#333;display:inline-flex;align-items:center;gap:4px}
.btn:hover{background:#f5f5f5}
.btn-blue{background:#1D4ED8;color:#fff;border-color:#1D4ED8}
.btn-blue:hover{background:#1e40af}

/* Page A4 */
.page{
  max-width:800px;margin:20px auto;background:#fff;
  padding:30px 40px 40px;
  box-shadow:0 2px 20px rgba(0,0,0,.12);
  border:1px solid #ccc;
}

/* Header officiel */
.doc-title{
  text-align:center;font-size:14px;font-weight:bold;
  text-transform:uppercase;letter-spacing:.5px;
  border:2px solid #000;padding:10px 16px;margin-bottom:20px;
  line-height:1.4;
}
.doc-subtitle{font-size:11px;text-align:center;margin-bottom:16px;font-style:italic;color:#333}

/* Sections */
.part-header{
  background:#000;color:#fff;font-weight:bold;font-size:12px;
  padding:5px 10px;text-transform:uppercase;margin-bottom:12px;
  letter-spacing:.5px;
}
.part-note{font-size:10px;margin-bottom:12px;line-height:1.5;color:#333;font-style:italic}

/* Champs */
.field-row{display:flex;align-items:baseline;gap:8px;margin-bottom:9px}
.field-label{font-size:12px;font-weight:bold;white-space:nowrap;flex-shrink:0}
.field-line{flex:1;border-bottom:1px solid #000;min-height:18px;padding:2px 4px;font-size:12px}
.field-filled{flex:1;border-bottom:1px solid #000;min-height:18px;padding:2px 4px;font-size:12px;font-weight:bold;color:#1D4ED8}
.field-block{border-bottom:1px solid #000;min-height:22px;margin-bottom:6px;padding:2px 4px;font-size:12px}
.field-block-filled{border-bottom:1px solid #000;min-height:22px;margin-bottom:6px;padding:3px 6px;font-size:12px;font-weight:bold;color:#1D4ED8;background:#eff6ff;border-radius:2px}

/* Blocs spéciaux */
.preconisation-box{
  border:1px solid #aaa;padding:10px 12px;margin:8px 0 14px;
  min-height:80px;background:#f9f9f9;
}
.preconisation-item{display:flex;align-items:flex-start;gap:6px;margin-bottom:6px}
.preconisation-item:last-child{margin-bottom:0}
.prec-bullet{font-weight:bold;font-size:14px;flex-shrink:0;color:#059669}
.prec-text{font-size:12px;color:#0f172a;line-height:1.4}
.prec-param{font-size:11px;color:#475569;margin-top:2px}

.restriction-box{
  border:1px solid #f87171;padding:8px 12px;margin:6px 0 12px;
  background:#fef2f2;min-height:40px;
}
.restriction-item{font-size:12px;color:#b91c1c;font-weight:bold;margin-bottom:3px;display:flex;align-items:center;gap:5px}

/* Signature */
.signature-zone{
  border:1px solid #000;min-height:60px;margin-top:10px;
  display:flex;align-items:flex-end;padding:6px 10px;
  font-size:11px;color:#888;
}

/* Séparateur */
.separator{border:none;border-top:2px dashed #aaa;margin:24px 0}

/* Footer */
.doc-footer{font-size:9px;color:#888;text-align:center;margin-top:20px;font-style:italic}

/* Résumé IA */
.ia-section{margin-top:24px;border:1px solid #1D4ED8;border-radius:4px;overflow:hidden}
.ia-header{background:#1D4ED8;color:#fff;padding:7px 12px;font-size:12px;font-weight:bold;font-family:sans-serif;display:flex;align-items:center;gap:8px}
.ia-body{padding:14px 16px;font-family:sans-serif;font-size:13px;line-height:1.75;color:#1e293b}
.ia-text p{margin-bottom:10px}
.ia-text p:last-child{margin-bottom:0}
.spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Freins & Leviers */
.fw-section{margin-top:20px;border:1px solid #7c3aed;border-radius:4px;overflow:visible}
.fw-header{background:#f5f3ff;border-bottom:1px solid #ddd6fe;padding:8px 14px;font-size:12px;font-weight:bold;font-family:sans-serif;color:#5b21b6;display:flex;align-items:center;gap:8px;cursor:pointer}
.fw-body{padding:14px 16px;font-family:sans-serif}
.fw-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.fw-col-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid #e5e7eb}
.fw-group{margin-bottom:10px}
.fw-group-hd{font-size:10px;font-weight:700;color:#6b7280;display:flex;align-items:center;gap:4px;margin-bottom:5px}
.fw-cb-row{display:flex;align-items:center;gap:7px;padding:4px 6px;border-radius:5px;cursor:pointer;border:1px solid transparent;margin-bottom:2px;transition:all .1s}
.fw-cb-row:hover{background:#f5f3ff;border-color:#ddd6fe}
.fw-cb-row.on{background:#f5f3ff;border-color:#c4b5fd}
.fw-cb-row input{width:13px;height:13px;cursor:pointer;accent-color:#7c3aed}
.fw-cb-lbl{font-size:12px;font-weight:500;flex:1}
.fw-cb-row.on .fw-cb-lbl{font-weight:700;color:#5b21b6}
.fw-lev-n{font-size:9px;font-weight:700;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;border-radius:8px;padding:1px 5px}
.fw-lev-empty{font-size:12px;color:#9ca3af;font-style:italic}
.fw-lev-wrap{display:flex;flex-wrap:wrap;gap:5px}
.fw-lev-chip{font-size:11px;font-weight:600;background:#f5f3ff;color:#5b21b6;border:1px solid #c4b5fd;border-radius:5px;padding:3px 8px}

@media print{
  body{background:#fff}
  .actions{display:none!important}
  .page{box-shadow:none;border:none;margin:0;padding:20px 30px}
  .fw-section,.ia-section{break-inside:avoid}
}
</style>
</head>
<body>
<?php renderPatientBanner(); ?>

<!-- Boutons -->
<div class="actions" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
  <?php $prescriptionIdInUrl = $_GET['prescription_id'] ?? ''; ?>
  <?php if ($prescriptionIdInUrl !== ''): ?>
    <!-- Mode "post-enregistrement" : boutons orientés vers la suite -->
    <a class="btn" href="prescription_detail.php?id=<?= urlencode($prescriptionIdInUrl) ?>">← Voir le détail</a>
    <a class="btn" href="prescriptions.php">📂 Historique</a>
    <button class="btn btn-blue" onclick="window.print()">🖨️ Imprimer / PDF</button>
    <a class="btn"
       href="index.php?restart=1"
       style="margin-left:auto;background:#10b981;color:#fff;border-color:#059669;box-shadow:0 4px 12px rgba(16,185,129,.25)">
       ➕ Nouvelle prescription
    </a>
  <?php else: ?>
    <!-- Mode "parcours en cours" : retour rapport -->
    <a class="btn" href="<?= h($rapportUrl) ?>">← Retour rapport</a>
    <button class="btn btn-blue" onclick="window.print()">🖨️ Imprimer / PDF</button>
  <?php endif; ?>
</div>

<div class="page">

  <!-- ── TITRE OFFICIEL ── -->
  <div class="doc-title">
    Formulaire de Synthèse des activités préconisé<br>
    <span style="font-size:11px;font-weight:normal">Arrêté du 28 décembre 2023 — Article D.1172-2 du Code de la Santé Publique</span>
  </div>

  <!-- ── PARTIE MÉDECIN ── -->
  <div class="part-header">Partie destinée au médecin / professionnel EAPA</div>
  <div class="part-note">
    Cette prescription ouvre droit au patient à la réalisation d'un bilan d'évaluation de sa condition physique et de ses capacités fonctionnelles ainsi qu'à un bilan motivationnel (article D.1172-2 du CSP).
  </div>

  <?php
    // Récupérer les infos du patient depuis la session
    $patientInfo = getPatient();
    $patientFullName = trim(($patientInfo['prenom'] ?? '') . ' ' . ($patientInfo['nom'] ?? ''));
    if ($patientFullName === '') {
        $patientFullName = $patientInfo['fullname'] ?? '';
    }
    $patientAge     = $patientInfo['age']     ?? '';
    $patientDossier = $patientInfo['dossier'] ?? '';
    $patientGenre   = $patientInfo['genre']   ?? '';
  ?>

  <!-- Ligne 1 : Date + Nom et prénom du patient (modèle officiel) -->
  <div class="field-row" style="display:flex;gap:30px;flex-wrap:wrap;align-items:baseline">
    <div style="display:flex;align-items:baseline;gap:6px">
      <span class="field-label">Date :</span>
      <span class="field-filled"><?= h($date) ?></span>
    </div>
    <div style="display:flex;align-items:baseline;gap:6px;flex:1;min-width:280px">
      <span class="field-label">Nom et prénom du patient :</span>
      <span class="field-filled" style="flex:1"><?= h($patientFullName ?: '—') ?></span>
    </div>
  </div>

  <!-- Ligne 2 : Âge + Sexe + N° dossier -->
  <div class="field-row" style="display:flex;gap:30px;flex-wrap:wrap;align-items:baseline;margin-top:6px">
    <?php if ($patientAge !== ''): ?>
    <div style="display:flex;align-items:baseline;gap:6px">
      <span class="field-label">Âge :</span>
      <span class="field-filled"><?= h($patientAge) ?> ans</span>
    </div>
    <?php endif; ?>
    <?php if ($patientGenre !== ''): ?>
    <div style="display:flex;align-items:baseline;gap:6px">
      <span class="field-label">Sexe :</span>
      <span class="field-filled"><?= h($patientGenre) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($patientDossier !== ''): ?>
    <div style="display:flex;align-items:baseline;gap:6px;flex:1;min-width:200px">
      <span class="field-label">N° dossier :</span>
      <span class="field-filled" style="flex:1;font-family:monospace"><?= h($patientDossier) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Ligne 3 : Pathologies -->
  <div class="field-row" style="margin-top:6px">
    <span class="field-label">Pathologie(s) du patient :</span>
    <span class="field-filled"><?= h($pathoList) ?></span>
  </div>

  <div style="margin:14px 0 6px;font-size:12px;font-weight:bold">
    Préconisations d'activité (type, fréquence, intensité, durée) :
  </div>
  <div class="preconisation-box">
    <?php if (empty($finalRecos)): ?>
      <p style="color:#999;font-style:italic;font-size:11px">Aucune activité à préconiser.</p>
    <?php else: ?>
      <?php foreach ($finalRecos as $item): ?>
        <?php
          $act     = $item['activity'];
          $adapts  = $item['adaptations'] ?? [];
          $actMods = $modalitiesPerActivity[$act] ?? [];
          $params  = [];
          foreach ($actMods as $prop => $vals)
            $params[] = modalityLabel($prop) . ' : ' . implode(', ', array_map('prettyLabel', (array)$vals));
        ?>
        <div class="preconisation-item" style="margin-bottom:10px;background:#f0fdf8;border:1px solid #a7f3d0;border-radius:8px;padding:10px 12px">
          <div style="width:100%">
            <div style="font-weight:bold;font-size:12px;color:#064e3b;margin-bottom:4px;border-bottom:1px solid #d1fae5;padding-bottom:3px">
              <?= h(prettyLabel($act)) ?>
            </div>
            <?php if (!empty($adapts)): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;background:linear-gradient(90deg,#dcfce7,#f0fdf4);border:1px solid #a7f3d0;border-left:4px solid #059669;border-radius:7px;padding:6px 10px;margin-bottom:6px">
                <span style="color:#064e3b;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.5px">Suggestion EAPA</span>
                <span style="font-weight:800;color:#064e3b;background:#bbf7d0;border:1px solid #6ee7b7;border-radius:5px;padding:2px 9px;font-size:12px"><?= h(implode(' — ', array_map('prettyLabel', $adapts))) ?></span>
              </div>
            <?php endif; ?>
            <?php if (!empty($params)): ?>
              <div style="margin:6px 0 2px">
                <span style="font-size:11px;font-weight:700;color:#334155">Suggestion Morganne</span>
              </div>
              <table style="width:calc(100% - 36px);margin-left:36px;border-collapse:collapse;font-size:11px;background:#fbfcfd;border:1px dashed #cbd5e1;border-radius:6px">
                <?php foreach ($actMods as $prop => $vals): ?>
                  <tr>
                    <td style="padding:3px 8px;color:#475569;font-weight:600;width:45%"><?= h(modalityLabel($prop)) ?></td>
                    <td style="padding:3px 8px;font-weight:700;color:#334155"><?= h(implode(', ', array_map('prettyLabel', (array)$vals))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php else: ?>
              <div style="font-size:11px;color:#94a3b8;font-style:italic">Aucun paramètre spécifique</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div style="margin-bottom:6px;font-size:12px;font-weight:bold">
    Restrictions et/ou limitations fonctionnelles à prendre en compte :
  </div>
  <div class="restriction-box">
    <?php if (empty($finalContra)): ?>
      <p style="color:#999;font-style:italic;font-size:11px">Aucune contre-indication formelle.</p>
    <?php else: ?>
      <?php foreach ($finalContra as $c => $pathos): ?>
        <div class="restriction-item">🚫 <?= h(prettyLabel($c)) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="field-row" style="margin-top:12px">
    <span class="field-label">Indication de renouvellement par médecin :</span>
    <span class="field-line">OUI / NON</span>
  </div>

  <div style="margin-top:14px;font-size:12px;font-weight:bold">Tampon &amp; Signature du prescripteur :</div>
  <div class="signature-zone">Signature / Cachet</div>

  <hr class="separator">

  <!-- ── FREINS & LEVIERS ── -->


  <!-- ── RÉSUMÉ IA (génération manuelle à la demande) ── -->
  <?php
    $prescriptionIdForIA = $_GET['prescription_id'] ?? '';

    // Si on a un prescription_id, on essaie de lire le résumé existant depuis Fuseki
    $existingResume = '';
    if ($prescriptionIdForIA !== '') {
        $prescUri = ONTO_NAMESPACE . $prescriptionIdForIA;
        $resumeQ = "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                    SELECT ?comment WHERE {
                        <$prescUri> rdfs:comment ?comment .
                        FILTER(!STRSTARTS(STR(?comment), \"[CI]\"))
                        FILTER(!STRSTARTS(STR(?comment), \"[FREIN]\"))
                        FILTER(!STRSTARTS(STR(?comment), \"[LEVIER]\"))
                    } LIMIT 1";
        $resumeUrl = FUSEKI_ENDPOINT . '?query=' . urlencode($resumeQ);
        $ctx = stream_context_create(['http' => ['header' => "Accept: application/sparql-results+json\r\n"]]);
        $resp = @file_get_contents($resumeUrl, false, $ctx);
        if ($resp !== false) {
            $rd = json_decode($resp, true);
            $bindings = $rd['results']['bindings'] ?? [];
            if (!empty($bindings)) {
                $existingResume = $bindings[0]['comment']['value'] ?? '';
            }
        }
    }
  ?>

  <div class="ia-section" style="margin-top:24px;border:1.5px solid #93c5fd;border-radius:12px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#1d4ed8,#4b8df8);color:#fff;
                padding:14px 22px;display:flex;align-items:center;gap:10px;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:18px">✨</span>
        <span style="font-weight:800;font-size:15px;letter-spacing:.3px">RÉSUMÉ PATIENT — INTELLIGENCE ARTIFICIELLE</span>
      </div>
      <span style="font-size:10px;background:rgba(255,255,255,.2);border-radius:20px;
                   padding:3px 10px;font-weight:700">⚡ <?= h(OLLAMA_MODEL) ?></span>
    </div>

    <div style="padding:22px 26px;background:#fff">

      <!-- État initial : bouton centré (visible si pas de résumé existant) -->
      <div id="iaInitial" class="ia-state-block" style="text-align:center;padding:30px 10px;<?= $existingResume !== '' ? 'display:none' : '' ?>">
        <p style="margin:0 0 16px;color:#475569;font-size:13px;line-height:1.6">
          Cliquez sur le bouton ci-dessous pour générer un résumé personnalisé<br>
          à partir des informations enregistrées (patient, pathologies, activités, freins, leviers).
        </p>
        <?php if ($prescriptionIdForIA !== ''): ?>
          <button type="button" id="btnGenerateIA" onclick="generateResumeIA()"
                  style="background:#1D4ED8;color:#fff;border:none;border-radius:10px;
                         padding:12px 28px;font-size:14px;font-weight:700;cursor:pointer;
                         box-shadow:0 4px 12px rgba(37,99,235,.3);transition:.15s">
            ✨ Générer le résumé IA
          </button>
        <?php else: ?>
          <span style="display:inline-block;background:#f1f5f9;color:#94a3b8;border-radius:10px;
                       padding:12px 22px;font-size:13px;font-weight:600;font-style:italic">
            Disponible après enregistrement
          </span>
        <?php endif; ?>
      </div>

      <!-- État loading -->
      <div id="iaLoad" class="ia-state-block" style="display:none;align-items:center;gap:12px;
                                                       color:#1d4ed8;font-size:13px;
                                                       background:#eff6ff;border:1px solid #bfdbfe;
                                                       border-radius:10px;padding:14px 18px">
        <div class="ia-spinner" style="border:3px solid #ddd;border-top-color:#1D4ED8;
                                        border-radius:50%;width:18px;height:18px;
                                        animation:spin 1s linear infinite"></div>
        <strong>Génération en cours...</strong> Cela peut prendre 30 à 90 secondes.
      </div>

      <!-- État erreur -->
      <div id="iaErr" class="ia-state-block" style="display:none;color:#b91c1c;font-size:13px;
                                                      background:#fef2f2;border:1px solid #fca5a5;
                                                      padding:12px 16px;border-radius:10px"></div>

      <!-- État succès : texte du résumé -->
      <div id="iaText" class="ia-state-block ia-text" style="<?= $existingResume === '' ? 'display:none;' : '' ?>font-size:14px;line-height:1.75;color:#1e293b">
        <?php if ($existingResume !== ''):
            $paragraphs = preg_split('/\n\n+/', trim($existingResume));
            foreach ($paragraphs as $para) {
                if (trim($para) !== '') {
                    echo '<p style="margin:0 0 12px">' . h(trim($para)) . '</p>';
                }
            }
        endif; ?>
      </div>

      <!-- Boutons d'action (regénération + copie), visibles seulement après succès -->
      <div id="iaActions" style="margin-top:14px;display:<?= $existingResume === '' ? 'none' : 'flex' ?>;gap:8px;justify-content:flex-end;flex-wrap:wrap">
        <button type="button" onclick="generateResumeIA(true)"
                style="background:#fff;color:#1D4ED8;border:1.5px solid #93c5fd;
                       border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;
                       cursor:pointer;transition:.15s">
          ↻ Regénérer le résumé
        </button>
        <button type="button" onclick="copyResumeIA(event)"
                style="background:#fff;color:#1D4ED8;border:1.5px solid #93c5fd;
                       border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;
                       cursor:pointer;transition:.15s">
          📋 Copier le texte
        </button>
      </div>

    </div>
  </div>

  <style>
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>

  <script>
    const PRESCRIPTION_ID_RESUME = <?= json_encode($prescriptionIdForIA) ?>;

    async function generateResumeIA(isRegenerate = false) {
        if (isRegenerate && !confirm('Regénérer le résumé remplacera celui existant. Continuer ?')) {
            return;
        }

        const initial = document.getElementById('iaInitial');
        const load    = document.getElementById('iaLoad');
        const err     = document.getElementById('iaErr');
        const txt     = document.getElementById('iaText');
        const actions = document.getElementById('iaActions');

        initial.style.display = 'none';
        err.style.display = 'none';
        if (!isRegenerate) { txt.style.display = 'none'; }
        actions.style.display = 'none';
        load.style.display = 'flex';

        try {
            const response = await fetch('generer_resume.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prescription_id: PRESCRIPTION_ID_RESUME })
            });
            const data = await response.json();

            if (!data.success) throw new Error(data.error || 'Erreur inconnue');

            // Affiche le résumé
            const paragraphs = (data.resume || '').split(/\n\n+/).filter(p => p.trim());
            txt.innerHTML = paragraphs.map(p =>
                '<p style="margin:0 0 12px">' + p.trim()
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>'
            ).join('');

            load.style.display = 'none';
            txt.style.display = 'block';
            actions.style.display = 'flex';

        } catch (e) {
            load.style.display = 'none';
            err.style.display = 'block';
            err.innerHTML = '<strong>❌ Erreur :</strong> ' + (e.message || e);
            // Remettre le bouton initial pour réessayer
            initial.style.display = 'block';
        }
    }

    function copyResumeIA(ev) {
        const txt = document.getElementById('iaText').innerText;
        navigator.clipboard?.writeText(txt).then(() => {
            const b = ev.target;
            const o = b.textContent;
            b.textContent = '✅ Copié !';
            setTimeout(() => b.textContent = o, 2000);
        });
    }
  </script>

  <!-- ── FOOTER OFFICIEL ── -->
  <div class="doc-footer">
    Ces éléments doivent être versés au dossier médical partagé, avec l'accord du patient.<br>
    Formulaire basé sur l'arrêté du 28 décembre 2023 fixant le modèle de formulaire de prescription d'une activité physique adaptée.
    APA4CAD · <?= h($date) ?>
  </div>

</div>

<script>
// ── Freins & Leviers ──
function fwToggle(){
  const b=document.getElementById('fwBody');
  const a=document.getElementById('fwArrow');
  const open=b.style.display==='none';
  b.style.display=open?'block':'none';
  a.style.transform=open?'rotate(90deg)':'';
}
const fwSet=new Set();
function fwChange(cb){
  const row=document.getElementById('fw-'+cb.value);
  if(cb.checked){fwSet.add(cb.value);if(row){row.classList.add('on');}}
  else{fwSet.delete(cb.value);if(row){row.classList.remove('on');}}
  fwUpdate();
}
function fwReset(){
  fwSet.clear();
  document.querySelectorAll('.fw-cb').forEach(cb=>{
    cb.checked=false;
    document.getElementById('fw-'+cb.value)?.classList.remove('on');
  });
  fwUpdate();
}
function fwUpdate(){
  const badge=document.getElementById('fwBadge');
  if(badge){badge.textContent=fwSet.size;badge.style.display=fwSet.size>0?'inline-flex':'none';}
  const levFreq={};
  document.querySelectorAll('.fw-cb:checked').forEach(cb=>{
    try{JSON.parse(cb.dataset.leviers||'[]').forEach(l=>{levFreq[l]=(levFreq[l]||0)+1;});}catch(e){}
  });
  const box=document.getElementById('fwLev');if(!box)return;
  if(Object.keys(levFreq).length===0){box.innerHTML='<p class="fw-lev-empty">Cochez des freins pour voir les leviers.</p>';return;}
  const sorted=Object.entries(levFreq).sort(([,a],[,b])=>b-a);
  let html='<div class="fw-lev-wrap">';
  sorted.forEach(([lev,freq])=>{
    const label=lev.replace(/_/g,' ').replace(/([A-Z])/g,' $1').trim();
    html+='<span class="fw-lev-chip">'+label+(freq>1?' <span style="font-size:9px;background:#ddd6fe;color:#5b21b6;border-radius:3px;padding:1px 3px">'+freq+'</span>':'')+' </span>';
  });
  html+='</div>';
  box.innerHTML=html;
}
</script>

<!-- ─────────────────────────────────────────────────────────────────── -->
<!--  MODULE 2 : ENREGISTREMENT FINAL DE LA PRESCRIPTION                 -->
<!-- ─────────────────────────────────────────────────────────────────── -->
<div id="save-prescription-section" style="max-width:1360px;margin:30px auto 40px;padding:0 20px">
    <div style="background:linear-gradient(135deg,#dbeafe,#eff6ff);border:1.5px solid #93c5fd;
                border-radius:18px;padding:24px 28px;display:flex;justify-content:space-between;
                align-items:center;gap:20px;flex-wrap:wrap;box-shadow:0 6px 16px rgba(37,99,235,.08)">
        <div style="flex:1;min-width:280px">
            <h3 style="margin:0 0 6px;color:#1d4ed8;font-size:20px">💾 Finaliser la prescription</h3>
            <p style="margin:0;color:#475569;font-size:14px;line-height:1.5">
                Sauvegarder l'ensemble de la prescription dans l'ontologie pour
                <strong><?= h(getPatient()['fullname'] ?? '') ?></strong>.
                <br><span style="font-size:13px;color:#6b7280">
                    Vous pouvez aussi passer cette étape pour enregistrer sans résumé IA.
                </span>
            </p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" id="btn-skip-save"
                    style="background:#fff;color:#1d4ed8;border:1.5px solid #93c5fd;
                           border-radius:12px;padding:12px 22px;font-size:14px;font-weight:700;
                           cursor:pointer;transition:.15s">
                ⏭ Passer (enregistrer sans IA)
            </button>
            <button type="button" id="btn-save-prescription"
                    style="background:#2563eb;color:#fff;border:none;border-radius:12px;
                           padding:14px 26px;font-size:15px;font-weight:700;cursor:pointer;
                           box-shadow:0 4px 12px rgba(37,99,235,.3);transition:.15s">
                💾 Enregistrer la prescription
            </button>
        </div>
    </div>
    <div id="save-feedback" style="margin-top:16px;display:none"></div>
</div>

<script>
(function() {
    const btnSave = document.getElementById('btn-save-prescription');
    const btnSkip = document.getElementById('btn-skip-save');
    const feedback = document.getElementById('save-feedback');

    async function doSave(includeResume) {
        btnSave.disabled = true;
        btnSkip.disabled = true;
        btnSave.innerHTML = '⏳ Enregistrement...';
        btnSave.style.opacity = '0.7';

        const fd = new FormData();

        // Si le résumé IA a été généré (texte visible dans #iaText), on l'inclut
        if (includeResume) {
            const iaTextEl = document.getElementById('iaText');
            if (iaTextEl && iaTextEl.style.display !== 'none') {
                const resumeText = (iaTextEl.innerText || iaTextEl.textContent || '').trim();
                if (resumeText) {
                    fd.append('resume_ia', resumeText);
                }
            }
        }

        try {
            const res = await fetch('enregistrer_prescription.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                // Redirection automatique vers le détail
                window.location.href = 'prescription_detail.php?id=' + encodeURIComponent(data.prescription_fragment);
                return;
            } else {
                feedback.style.display = 'block';
                feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;
                                                    padding:18px 22px;color:#7f1d1d">
                    <strong>❌ Échec :</strong> ${data.error || 'erreur inconnue'}</div>`;
                btnSave.disabled = false;
                btnSkip.disabled = false;
                btnSave.innerHTML = '💾 Réessayer';
                btnSave.style.opacity = '1';
            }
        } catch (e) {
            feedback.style.display = 'block';
            feedback.innerHTML = `<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;
                                                padding:18px 22px;color:#7f1d1d">
                <strong>❌ Erreur réseau :</strong> ${e.message}</div>`;
            btnSave.disabled = false;
            btnSkip.disabled = false;
            btnSave.innerHTML = '💾 Réessayer';
            btnSave.style.opacity = '1';
        }
    }

    btnSave.addEventListener('click', () => doSave(true));
    btnSkip.addEventListener('click', () => doSave(false));
})();
</script>

</body>
</html>
