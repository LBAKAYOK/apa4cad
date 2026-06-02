<?php
/**
 * APA4CAD - Diagnostic Ollama
 *
 * Page de test rapide pour vérifier que Ollama est accessible
 * depuis PHP via les 3 méthodes (cURL, fsockopen, file_get_contents).
 *
 * Ouvre cette page si le résumé IA ne fonctionne pas.
 */

declare(strict_types=1);

const OLLAMA_URL = 'http://127.0.0.1:11434/api/tags';

function testCurl(): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'msg' => 'cURL non disponible'];
    $ch = curl_init(OLLAMA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) return ['ok' => false, 'msg' => "Erreur cURL : $err"];
    if ($code !== 200) return ['ok' => false, 'msg' => "HTTP $code"];
    return ['ok' => true, 'msg' => 'OK (HTTP 200)', 'data' => substr($r, 0, 300)];
}

function testFsockopen(): array {
    $fp = @fsockopen('127.0.0.1', 11434, $errno, $errstr, 3);
    if (!$fp) return ['ok' => false, 'msg' => "fsockopen : $errstr ($errno)"];
    fwrite($fp, "GET /api/tags HTTP/1.1\r\nHost: 127.0.0.1:11434\r\nConnection: close\r\n\r\n");
    $r = '';
    while (!feof($fp)) $r .= fread($fp, 4096);
    fclose($fp);
    return ['ok' => true, 'msg' => 'OK', 'data' => substr($r, 0, 300)];
}

function testFileGetContents(): array {
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $r = @file_get_contents(OLLAMA_URL, false, $ctx);
    if ($r === false) return ['ok' => false, 'msg' => 'file_get_contents échoué'];
    return ['ok' => true, 'msg' => 'OK', 'data' => substr($r, 0, 300)];
}

$results = [
    'cURL' => testCurl(),
    'fsockopen' => testFsockopen(),
    'file_get_contents' => testFileGetContents(),
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Test Ollama - APA4CAD</title>
<style>
body{font-family:-apple-system,Arial,sans-serif;background:#f4f7fb;padding:32px;
     max-width:900px;margin:0 auto;color:#1e293b}
h1{color:#1d4ed8;margin:0 0 8px;font-size:24px}
.intro{color:#6b7280;margin-bottom:24px;font-size:14px}
.test{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:14px}
.test h2{margin:0 0 8px;font-size:16px}
.test .status{display:inline-block;padding:3px 10px;border-radius:999px;
              font-size:12px;font-weight:700;margin-bottom:8px}
.status.ok{background:#dcfce7;color:#065f46}
.status.fail{background:#fee2e2;color:#991b1b}
.data{background:#f1f5f9;padding:10px;border-radius:8px;font-family:monospace;
      font-size:12px;color:#475569;overflow-x:auto;white-space:pre-wrap}
.tip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
     padding:14px 18px;margin-top:18px;font-size:13px;color:#1e40af}
.tip code{background:#fff;padding:2px 6px;border-radius:4px;font-family:monospace}
</style>
</head>
<body>
<h1>🔬 Test de connexion Ollama</h1>
<div class="intro">Diagnostic pour vérifier qu'Ollama est joignable depuis PHP/XAMPP.</div>

<?php foreach ($results as $name => $r): ?>
    <div class="test">
        <h2><?= htmlspecialchars($name) ?></h2>
        <div class="status <?= $r['ok'] ? 'ok' : 'fail' ?>">
            <?= $r['ok'] ? '✓ Connexion OK' : '✗ Échec' ?>
        </div>
        <div style="font-size:13px;color:#475569;margin-bottom:8px"><?= htmlspecialchars($r['msg']) ?></div>
        <?php if (!empty($r['data'])): ?>
            <div class="data"><?= htmlspecialchars($r['data']) ?>...</div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="tip">
    💡 <strong>Si tous échouent :</strong>
    <ol>
        <li>Vérifiez qu'Ollama tourne : <code>ollama serve</code> dans un terminal</li>
        <li>Vérifiez le pare-feu Windows pour le port <code>11434</code></li>
        <li>Testez en direct dans votre navigateur : <a href="http://localhost:11434/api/tags" target="_blank">http://localhost:11434/api/tags</a></li>
        <li>Vérifiez que vous avez bien <code>llama3.2:1b</code> téléchargé : <code>ollama list</code></li>
    </ol>
</div>
</body>
</html>
