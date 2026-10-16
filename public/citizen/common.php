<?php
// public/citizen/common.php — citizen shared: config, base URLs, authenticated API client
session_start();
$cfg = require __DIR__ . '/../../lib/config.php';
$api_base = $cfg['api_base'];
$citizen_base = '/public/citizen';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function citizen_user() {
    if (empty($_SESSION['citizen_token'])) return null;
    return $_SESSION['citizen_user'] ?? null;
}

function citizen_token() {
    return $_SESSION['citizen_token'] ?? '';
}

function citizen_require_login() {
    if (empty($_SESSION['citizen_token'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Authenticated call to the JSON API. The citizen pages previously called the API with
 * no Authorization header at all, so every request came back 401.
 * Returns ['ok'=>bool, 'data'=>..., 'error'=>...] or ['ok'=>false,'error'=>['message'=>'...']].
 */
function citizen_api($method, $path, $body = null, $token = null) {
    global $api_base;
    $token = $token ?? citizen_token();
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$resp, true);
    if (!is_array($d)) {
        return ['ok' => false, 'error' => ['message' => 'API unreachable (HTTP ' . $code . ')']];
    }
    return $d;
}

/** Convenience: data payload or empty array. */
function citizen_data(array $resp, string $key = 'data') {
    return (!empty($resp['ok']) && isset($resp[$key])) ? $resp[$key] : [];
}

/** Error message or ''. */
function citizen_error(array $resp) {
    return empty($resp['ok']) ? ($resp['error']['message'] ?? 'Request failed') : '';
}

/** Multipart POST (file upload) to the API. */
function citizen_api_upload($path, $tmpPath, $fileName, $mime, $token = null, $field = 'image') {
    global $api_base;
    $token = $token ?? citizen_token();
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = [];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [$field => new CURLFile($tmpPath, $mime ?: 'application/octet-stream', $fileName)],
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$resp, true);
    return is_array($d) ? $d : ['ok' => false, 'error' => ['message' => 'Upload failed']];
}

/** kip formatting. */
function kip($v) { return number_format((float)$v, 0) . ' ກີບ'; }