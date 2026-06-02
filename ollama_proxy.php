<?php
/**
 * APA4CAD - Proxy Ollama
 *
 * Contourne le blocage Windows/XAMPP de localhost:11434 en utilisant
 * 3 méthodes de connexion en cascade (cURL → fsockopen → file_get_contents).
 *
 * Appelé en POST depuis le JavaScript de resume.php avec le payload Ollama.
 * Réponse : JSON ou flux de chunks selon le mode.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// ─── Configuration Ollama ────────────────────────────────────────────────
const OLLAMA_HOST = '127.0.0.1';
const OLLAMA_PORT = 11434;
const OLLAMA_URL  = 'http://127.0.0.1:11434/api/generate';

// ─── Récupération du payload reçu de resume.php ──────────────────────────
$rawInput = file_get_contents('php://input');
if ($rawInput === false || $rawInput === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Aucun payload reçu.']);
    exit;
}

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON invalide.']);
    exit;
}

// Forcer non-streaming pour cette version simple
$payload['stream'] = false;

// ─── Méthode 1 : cURL (préférée) ──────────────────────────────────────────
function tryCurl(array $payload): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'cURL indisponible'];
    $ch = curl_init(OLLAMA_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
        return ['ok' => false, 'error' => "cURL: $err (HTTP $httpCode)"];
    }
    return ['ok' => true, 'response' => $response];
}

// ─── Méthode 2 : fsockopen (fallback) ────────────────────────────────────
function tryFsockopen(array $payload): array {
    $body = json_encode($payload);
    $fp = @fsockopen(OLLAMA_HOST, OLLAMA_PORT, $errno, $errstr, 5);
    if (!$fp) return ['ok' => false, 'error' => "fsockopen: $errstr ($errno)"];

    $request = "POST /api/generate HTTP/1.1\r\n";
    $request .= "Host: " . OLLAMA_HOST . ":" . OLLAMA_PORT . "\r\n";
    $request .= "Content-Type: application/json\r\n";
    $request .= "Content-Length: " . strlen($body) . "\r\n";
    $request .= "Connection: close\r\n\r\n";
    $request .= $body;

    fwrite($fp, $request);
    stream_set_timeout($fp, 240);
    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        $response .= $chunk;
    }
    fclose($fp);

    // Séparer headers et body
    $parts = explode("\r\n\r\n", $response, 2);
    if (count($parts) < 2) return ['ok' => false, 'error' => 'Réponse HTTP invalide'];
    $body = $parts[1];

    // Si Transfer-Encoding: chunked, décoder
    if (stripos($parts[0], 'transfer-encoding: chunked') !== false) {
        $body = decodeChunked($body);
    }
    return ['ok' => true, 'response' => $body];
}

function decodeChunked(string $str): string {
    $result = '';
    $offset = 0;
    while ($offset < strlen($str)) {
        $newlinePos = strpos($str, "\r\n", $offset);
        if ($newlinePos === false) break;
        $hexLen = substr($str, $offset, $newlinePos - $offset);
        $len = hexdec(trim($hexLen));
        if ($len === 0) break;
        $offset = $newlinePos + 2;
        $result .= substr($str, $offset, $len);
        $offset += $len + 2;
    }
    return $result;
}

// ─── Méthode 3 : file_get_contents (dernier recours) ─────────────────────
function tryFileGetContents(array $payload): array {
    $body = json_encode($payload);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 240,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents(OLLAMA_URL, false, $ctx);
    if ($response === false) {
        return ['ok' => false, 'error' => 'file_get_contents échoué'];
    }
    return ['ok' => true, 'response' => $response];
}

// ─── Cascade ──────────────────────────────────────────────────────────────
$errors = [];

$res = tryCurl($payload);
if (!$res['ok']) {
    $errors[] = $res['error'];
    $res = tryFsockopen($payload);
}
if (!$res['ok']) {
    $errors[] = $res['error'];
    $res = tryFileGetContents($payload);
}

if (!$res['ok']) {
    $errors[] = $res['error'];
    http_response_code(502);
    echo json_encode([
        'error' => 'Ollama injoignable. Vérifiez qu\'il tourne (ollama serve).',
        'attempts' => $errors,
    ]);
    exit;
}

// ─── Renvoyer la réponse Ollama ──────────────────────────────────────────
echo $res['response'];
