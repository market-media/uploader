<?php

// Only available to the local test server started by run.php.
$token = getenv('UPLOADER_TEST_TOKEN');
if (PHP_SAPI !== 'cli-server' || !$token
    || !hash_equals($token, $_SERVER['HTTP_X_TEST_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
foreach (['Uploader', 'Image', 'File', 'Media', 'Send'] as $class) {
    require __DIR__ . '/../src/' . $class . '.php';
}

$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($route, ['/example/file.php', '/example/download.php'], true)) {
    require __DIR__ . '/..' . $route;
    return;
}

class CollisionUpload extends MarketMedia\Uploader\File
{
    protected function name(string $name): string
    {
        return $this->name = 'collision.pdf';
    }
}
class FailingUpload extends MarketMedia\Uploader\File
{
    protected function storeUpload(array $upload, string $name): string
    {
        return $this->store($name, static function ($stream): void {
            fwrite($stream, 'partial');
            throw new RuntimeException('Simulated write failure');
        });
    }
}

header('Content-Type: application/json');
try {
    $root = getenv('UPLOADER_TEST_ROOT');
    $mode = $_POST['mode'] ?? 'image';
    $uploader = match ($mode) {
        'file' => new MarketMedia\Uploader\File($root, 'files', false),
        'collision' => new CollisionUpload($root, 'collision', false),
        'failure' => new FailingUpload($root, 'failure', false),
        'media' => new MarketMedia\Uploader\Media($root, 'media', false),
        'send' => new MarketMedia\Uploader\Send($root, 'send', ['application/pdf'], ['pdf'], false),
        'dated' => new MarketMedia\Uploader\Image($root, 'dated'),
        default => new MarketMedia\Uploader\Image($root, 'images', false),
    };
    if ($mode === 'send') {
        new MarketMedia\Uploader\Send($root, 'other', ['image/png'], ['png'], false);
    }
    $uploader->setLimits(
        maxBytes: (int) ($_POST['maxBytes'] ?? 20971520),
        maxWidth: (int) ($_POST['maxWidth'] ?? 8192),
        maxHeight: (int) ($_POST['maxHeight'] ?? 8192),
        maxPixels: (int) ($_POST['maxPixels'] ?? 12000000)
    );
    $file = $_FILES['file'] ?? [];
    if (isset($_POST['local'])) {
        $file['tmp_name'] = $root . '/source.png';
    }
    if (isset($_POST['uploadError'])) {
        $file['error'] = UPLOAD_ERR_PARTIAL;
    }
    $arguments = [$file, $_POST['name'] ?? 'display name'];
    if ($uploader instanceof MarketMedia\Uploader\Image) {
        $arguments[] = (int) ($_POST['width'] ?? 40);
        $arguments[] = isset($_POST['badQuality']) ? ['png' => 10] : null;
    }
    $path = $uploader->upload(...$arguments);
    echo json_encode(['ok' => true, 'path' => $path], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
