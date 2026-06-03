<?php
declare(strict_types=1);

const ADMIN_SESSION_KEY = 'ixovet_admin_authenticated';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('ixovet_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function is_admin(): bool
{
    return !empty($_SESSION[ADMIN_SESSION_KEY]);
}

function store_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.dataroom_ixovet';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function store_path(): string
{
    return store_dir() . DIRECTORY_SEPARATOR . 'store.json';
}

function files_dir(): string
{
    $dir = store_dir() . DIRECTORY_SEPARATOR . 'files';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function safe_file_id(string $id): string
{
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $id);
}

function extension_from_name(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,12}$/', $ext) ? $ext : 'bin';
}

function mime_from_extension(string $name, string $fallback = 'application/octet-stream'): string
{
    $ext = extension_from_name($name);
    $types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];
    return $types[$ext] ?? $fallback;
}

function detect_mime(string $path, string $fallbackName = ''): string
{
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }
    return mime_from_extension($fallbackName !== '' ? $fallbackName : $path);
}

function read_store(): ?array
{
    $path = store_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function write_store(array $store): void
{
    $store['serverUpdatedAt'] = gmdate('c');
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_store']);
        exit;
    }

    $path = store_path();
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'write_failed']);
        exit;
    }
    rename($tmp, $path);
    chmod($path, 0640);
}

function public_store(?array $store): ?array
{
    if ($store === null) {
        return null;
    }

    unset($store['prospects']);
    return $store;
}

function merge_by_id(array $current, array $incoming): array
{
    $byId = [];
    foreach ($current as $item) {
        if (is_array($item) && isset($item['id'])) {
            $byId[(string)$item['id']] = $item;
        }
    }
    foreach ($incoming as $item) {
        if (is_array($item) && isset($item['id'])) {
            $byId[(string)$item['id']] = $item;
        }
    }
    return array_values($byId);
}

function merge_public_write(?array $current, array $incoming): array
{
    if ($current === null) {
        $current = [];
    }

    foreach (['version', 'settings', 'nda', 'documents', 'prospects'] as $key) {
        if (array_key_exists($key, $current)) {
            $incoming[$key] = $current[$key];
        }
    }

    foreach (['investors', 'sessions', 'events'] as $key) {
        $incoming[$key] = merge_by_id(
            is_array($current[$key] ?? null) ? $current[$key] : [],
            is_array($incoming[$key] ?? null) ? $incoming[$key] : []
        );
    }

    return $incoming;
}

$action = $_GET['action'] ?? 'store';
if (!in_array($action, ['store', 'upload', 'file'], true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'file') {
    $id = safe_file_id((string)($_GET['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'missing_file_id']);
        exit;
    }

    $path = files_dir() . DIRECTORY_SEPARATOR . $id;
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'file_not_found']);
        exit;
    }

    $mime = detect_mime($path, $id);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="' . basename($id) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($action === 'upload') {
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_file']);
        exit;
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'upload_failed', 'code' => (int)$file['error']]);
        exit;
    }

    $originalName = (string)($file['name'] ?? 'arquivo.bin');
    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_upload']);
        exit;
    }

    $ext = extension_from_name($originalName);
    $fileId = 'file_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $target = files_dir() . DIRECTORY_SEPARATOR . $fileId;
    if (!move_uploaded_file($tmpName, $target)) {
        http_response_code(500);
        echo json_encode(['error' => 'move_failed']);
        exit;
    }
    chmod($target, 0640);

    $mime = detect_mime($target, $originalName);
    echo json_encode([
        'ok' => true,
        'fileId' => $fileId,
        'url' => '/api/file?id=' . rawurlencode($fileId),
        'filename' => $originalName,
        'mimeType' => $mime,
        'size' => filesize($target),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'GET') {
    $store = read_store();
    echo json_encode([
        'ok' => true,
        'persisted' => $store !== null,
        'store' => is_admin() ? $store : public_store($store),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'PUT' || $method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    $incoming = $payload['store'] ?? null;
    if (!is_array($incoming)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_store']);
        exit;
    }

    $current = read_store();
    $next = is_admin() ? $incoming : merge_public_write($current, $incoming);
    write_store($next);
    echo json_encode(['ok' => true, 'admin' => is_admin()]);
    exit;
}

if ($method === 'DELETE') {
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    $path = store_path();
    if (is_file($path)) {
        unlink($path);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
