<?php

require __DIR__ . '/common.php';

$identifier = $_GET['id'] ?? null;
$record = is_string($identifier) && preg_match('/\A[a-f0-9]{32}\z/D', $identifier)
    ? ($_SESSION['uploads'][$identifier] ?? null) : null;
if (!is_array($record)) {
    http_response_code(404);
    exit;
}
try {
    $root = rtrim(exampleStorage(), '/') . '/';
    $path = realpath($record['path']);
    if ($path === false || is_link($record['path']) || !is_file($path)
        || !str_starts_with(str_replace('\\', '/', $path), $root)) {
        throw new RuntimeException('Download unavailable');
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Download unavailable');
    }
    $size = fstat($stream)['size'];
} catch (Throwable $error) {
    error_log('Uploader download: ' . $error->getMessage());
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . $size);
try {
    fpassthru($stream);
} finally {
    fclose($stream);
}