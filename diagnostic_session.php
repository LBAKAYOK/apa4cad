<?php
/**
 * APA4CAD - DIAGNOSTIC SESSION
 *
 * Affiche le contenu actuel de la session de parcours.
 * Utile pour débugger : "qu'est-ce qui est stocké en session ?"
 *
 * À ouvrir dans un onglet pendant que tu fais ton parcours
 * pour voir si les CI sont bien stockées après rapport.php.
 */

require_once __DIR__ . '/patient_session.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Diagnostic Session - APA4CAD</title>
<style>
body{font-family:'Courier New',monospace;max-width:1000px;margin:30px auto;padding:20px;
     background:#1e293b;color:#e2e8f0;font-size:13px}
h1{color:#fbbf24}
h2{color:#60a5fa;border-bottom:1px solid #475569;padding-bottom:6px;margin-top:30px}
.section{background:#0f172a;padding:15px 20px;border-radius:8px;margin:10px 0;
         border-left:4px solid #3b82f6}
.empty{color:#94a3b8;font-style:italic}
.count{background:#3b82f6;color:#fff;padding:2px 10px;border-radius:999px;
       font-size:12px;font-weight:bold;margin-left:8px}
.count.zero{background:#475569}
.count.warn{background:#dc2626}
ul{margin:5px 0;padding-left:25px}
li{margin:3px 0}
pre{background:#020617;padding:10px;border-radius:6px;overflow-x:auto;color:#94a3b8;font-size:12px}
.reload{background:#3b82f6;color:#fff;padding:10px 18px;border-radius:8px;
        text-decoration:none;font-weight:bold;display:inline-block;margin-top:10px}
</style>
</head>
<body>

<h1>🔍 Diagnostic Session APA4CAD</h1>
<p style="color:#94a3b8">Contenu actuel de la session de parcours.</p>
<a href="diagnostic_session.php" class="reload">↻ Recharger</a>

<h2>🩺 Pathologies sélectionnées <span class="count <?= empty(getParcoursPathologies()) ? 'zero' : '' ?>"><?= count(getParcoursPathologies()) ?></span></h2>
<div class="section">
<?php $p = getParcoursPathologies(); ?>
<?php if (empty($p)): ?>
    <span class="empty">— aucune —</span>
<?php else: ?>
    <ul>
    <?php foreach ($p as $uri): ?>
        <li><?= htmlspecialchars($uri) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<h2>🏃 Activités recommandées <span class="count <?= empty(getParcoursActivites()) ? 'zero' : '' ?>"><?= count(getParcoursActivites()) ?></span></h2>
<div class="section">
<?php $a = getParcoursActivites(); ?>
<?php if (empty($a)): ?>
    <span class="empty">— aucune —</span>
<?php else: ?>
    <ul>
    <?php foreach ($a as $uri): ?>
        <li><?= htmlspecialchars($uri) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<h2>⛔ Contre-indications <span class="count <?= empty(getParcoursContraindications()) ? 'warn' : '' ?>"><?= count(getParcoursContraindications()) ?></span></h2>
<div class="section">
<?php $ci = getParcoursContraindications(); ?>
<?php if (empty($ci)): ?>
    <span class="empty">— aucune contre-indication bloquante stockée —</span>
    <br><br>
    <span style="color:#fbbf24">⚠️ Si tu attendais des CI, c'est qu'il n'y en a pas avec ces pathologies, OU que tu n'es pas encore passé par rapport.php.</span>
<?php else: ?>
    <ul>
    <?php foreach ($ci as $entry): ?>
        <li>
            <strong style="color:#fca5a5"><?= htmlspecialchars($entry['activity'] ?? '?') ?></strong>
            — bloquée par :
            <em><?= htmlspecialchars(implode(', ', $entry['reasons'] ?? [])) ?></em>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<h2>⚠️ Freins cochés <span class="count <?= empty(getParcoursFreins()) ? 'zero' : '' ?>"><?= count(getParcoursFreins()) ?></span></h2>
<div class="section">
<?php $f = getParcoursFreins(); ?>
<?php if (empty($f)): ?>
    <span class="empty">— aucun —</span>
<?php else: ?>
    <ul>
    <?php foreach ($f as $uri): ?>
        <li><?= htmlspecialchars($uri) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<h2>👤 Patient sélectionné</h2>
<div class="section">
<?php $pt = getPatient(); ?>
<?php if (!$pt): ?>
    <span class="empty">— aucun patient en session —</span>
<?php else: ?>
    <ul>
        <li><strong>Nom complet :</strong> <?= htmlspecialchars($pt['fullname']) ?></li>
        <li><strong>URI :</strong> <?= htmlspecialchars($pt['uri']) ?></li>
        <li><strong>Dossier :</strong> <?= htmlspecialchars($pt['dossier']) ?></li>
    </ul>
<?php endif; ?>
</div>

<h2>🗂️ Session brute (pour debug avancé)</h2>
<div class="section">
<pre><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
</div>

<a href="diagnostic_session.php" class="reload">↻ Recharger</a>
&nbsp;
<a href="index.php?restart=1" class="reload" style="background:#dc2626">🗑 Vider la session et recommencer</a>

</body>
</html>
