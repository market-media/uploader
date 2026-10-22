<?php

// Development examples: enable explicitly; integrate your application's authentication in production.
if (getenv('UPLOADER_EXAMPLES_ENABLED') !== '1') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
foreach (['Uploader', 'Image', 'File', 'Media', 'Send'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function exampleStorage(): string
{
    $configured = getenv('UPLOADER_STORAGE_DIR');
    if (!$configured || !is_dir($configured) || is_link($configured)) {
        throw new RuntimeException('Private storage must be configured');
    }
    $root = str_replace('\\', '/', realpath($configured));
    $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    if ($public === false) {
        throw new RuntimeException('Could not establish document root');
    }
    $public = rtrim(str_replace('\\', '/', $public), '/');
    $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
    $comparisonPublic = PHP_OS_FAMILY === 'Windows' ? strtolower($public) : $public;
    if ($comparisonRoot === $comparisonPublic || str_starts_with($comparisonRoot, $comparisonPublic . '/')) {
        throw new RuntimeException('Storage must be outside the document root');
    }
    return $root;
}

function handleExample(string $kind): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return '';
    }
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403);
        return '<p>Requisição inválida.</p>';
    }
    try {
        if (!is_string($_POST['name'] ?? null) || strlen($_POST['name']) > 255
            || !is_array($_FILES['file'] ?? null)) {
            throw new InvalidArgumentException('Invalid form data');
        }
        $root = exampleStorage();
        // Bound the amount this demonstration can store per session.
        $records = $_SESSION['uploads'] ?? [];
        $bytes = array_sum(array_column($records, 'size'));
        if (count($records) >= 20 || $bytes >= 104857600) {
            throw new RuntimeException('Example upload quota reached');
        }
        $directory = 'session-' . hash('sha256', session_id());
        $uploader = match ($kind) {
            'image' => new MarketMedia\Uploader\Image($root, $directory),
            'media' => new MarketMedia\Uploader\Media($root, $directory),
            'send' => new MarketMedia\Uploader\Send($root, $directory, ['application/postscript'], ['ai']),
            default => new MarketMedia\Uploader\File($root, $directory),
        };
        $uploader->setLimits(maxBytes: min(20971520, 104857600 - $bytes));
        $path = $uploader->upload($_FILES['file'], $_POST['name']);
        $identifier = bin2hex(random_bytes(16));
        $_SESSION['uploads'][$identifier] = ['path' => $path, 'size' => filesize($path)];
        $url = 'download.php?id=' . rawurlencode($identifier);
        return '<p><a href="' . escape($url) . '">Baixar arquivo enviado</a></p>';
    } catch (Throwable $error) {
        error_log('Uploader example: ' . $error->getMessage());
        http_response_code(422);
        return '<p>Não foi possível receber o arquivo. Confira o formato, o tamanho e os limites de envio.</p>';
    }
}
