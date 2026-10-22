<?php

foreach (['gd', 'fileinfo', 'zip', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Enable the required PHP extension: $extension\n");
        exit(1);
    }
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
foreach (['Uploader', 'Image', 'File', 'Media', 'Send'] as $class) {
    require __DIR__ . '/../src/' . $class . '.php';
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException $error) {
        check(true, $message);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/uploader-test-' . bin2hex(random_bytes(8));
mkdir($root);
$server = null;
$token = bin2hex(random_bytes(24));
try {
    $image = imagecreatetruecolor(80, 40);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 20, 40, 60, 127));
    imagejpeg($image, "$root/source.jpg");
    imagepng($image, "$root/source.png");
    imagewebp($image, "$root/source.webp");
    imagegif($image, "$root/source.gif");
    unset($image);
    file_put_contents("$root/document.pdf", "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    file_put_contents("$root/fake.gif", 'This is plain text, not a GIF.');
    $zip = new ZipArchive();
    $zip->open("$root/document.docx", ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<document/>');
    $zip->close();
    $zip->open("$root/other.zip", ZipArchive::CREATE);
    $zip->addFromString('unrelated.txt', 'test');
    $zip->close();

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
    foreach (['gd', 'mbstring', 'fileinfo', 'zip'] as $extension) {
        array_push($command, '-d', 'extension=' . $extension);
    }
    array_push($command, '-d', 'display_errors=0', '-d', 'post_max_size=32M',
        '-d', 'upload_max_filesize=24M', '-S', $address, __DIR__ . '/http.php');
    $server = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['file', "$root/server.log", 'a'], 2 => ['file', "$root/server.log", 'a'],
    ], $pipes, dirname(__DIR__), array_merge(getenv(), [
        'UPLOADER_TEST_ROOT' => $root, 'UPLOADER_TEST_TOKEN' => $token,
        'UPLOADER_EXAMPLES_ENABLED' => '1', 'UPLOADER_STORAGE_DIR' => $root,
    ]), ['bypass_shell' => true]);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start test server');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        set_error_handler(static fn () => true);
        try {
            $socket = stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        } finally {
            restore_error_handler();
        }
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'HTTP test server starts');

    $request = static function (string $source, string $filename, string $mime, array $fields = [],
        string $route = '/', string $cookie = '', bool $json = true) use ($address, $token): array|string {
        $boundary = 'test' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
        $body .= "Content-Type: $mime\r\n\r\n" . file_get_contents($source) . "\r\n--$boundary--\r\n";
        $response = file_get_contents('http://' . $address . $route, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
            'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nX-Test-Token: $token\r\nCookie: $cookie\r\n",
            'content' => $body,
        ]]));
        return $json ? json_decode($response, true, 512, JSON_THROW_ON_ERROR) : $response;
    };

    foreach (['jpg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'] as $extension => $format) {
        $response = $request("$root/source.$extension", "source.$extension", "image/$format",
            ['name' => '../../sensitive <name>']);
        check($response['ok'], "$extension HTTP upload succeeds");
        $output = $response['path'];
        check((bool) preg_match('/\A[a-f0-9]{32}\.' . $extension . '\z/', basename($output)), "$extension uses a random safe name");
        $size = getimagesize($output);
        check($size[0] === 40 && $size[1] === 20, "$extension resized");
        if (in_array($extension, ['png', 'webp'], true)) {
            $decode = 'imagecreatefrom' . $format;
            $result = $decode($output);
            $color = imagecolorsforindex($result, imagecolorat($result, 0, 0));
            check($color['alpha'] === 127, "$extension transparency retained");
            unset($result);
        }
        $previous = hash_file('sha256', $output);
        for ($repeat = 0; $repeat < 3; $repeat++) {
            $again = $request("$root/source.$extension", "source.$extension", "image/$format", ['width' => 200]);
            check($again['ok'] && $again['path'] !== $output, "$extension repeated upload has a unique name");
            $size = getimagesize($again['path']);
            check($size[0] === 80 && $size[1] === 40, "$extension is not enlarged");
        }
        check(hash_file('sha256', $output) === $previous, "$extension previous upload preserved");
    }
    $response = $request("$root/source.jpg", 'source.jpeg', 'image/jpeg');
    check($response['ok'] && str_ends_with($response['path'], '.jpg'), 'JPEG alias supported');
    $response = $request("$root/source.png", 'source.png', 'image/png', ['mode' => 'dated']);
    check($response['ok'] && str_contains($response['path'], '/dated/' . date('Y/m') . '/'), 'Date directories supported');
    foreach ([
        ['fake.gif', 'fake.gif', 'image/gif', [], 'Forged GIF rejected'],
        ['source.jpg', 'fake.png', 'image/png', [], 'MIME and extension mismatch rejected'],
        ['fake.gif', 'fake.pdf', 'application/pdf', ['mode' => 'file'], 'Forged PDF rejected'],
        ['document.pdf', 'document.mp4', 'video/mp4', ['mode' => 'media'], 'Forged media rejected'],
        ['source.png', 'source.png', 'image/png', ['local' => 1], 'Local source rejected'],
        ['source.png', 'source.png', 'image/png', ['uploadError' => 1], 'Partial upload rejected'],
        ['source.png', 'source.png', 'image/png', ['maxBytes' => 10], 'Actual byte limit enforced'],
        ['source.png', 'source.png', 'image/png', ['maxPixels' => 100], 'Pixel limit enforced before decoding'],
        ['source.png', 'source.png', 'image/png', ['maxHeight' => 20], 'Height limit enforced'],
        ['source.png', 'source.png', 'image/png', ['width' => 0], 'Invalid resize width rejected'],
        ['source.png', 'source.png', 'image/png', ['badQuality' => 1], 'Invalid quality rejected'],
        ['other.zip', 'fake.docx', 'application/zip', ['mode' => 'file'], 'Unrelated ZIP rejected as DOCX'],
    ] as [$source, $filename, $mime, $fields, $message]) {
        $response = $request("$root/$source", $filename, $mime, $fields);
        check(!$response['ok'], $message);
        check(!str_contains($response['message'], $root), 'Error does not expose storage root');
    }
    foreach (['file', 'send'] as $mode) {
        $response = $request("$root/document.pdf", 'document.pdf', 'text/plain', ['mode' => $mode]);
        check($response['ok'], "$mode accepts real PDF despite misleading client MIME");
    }
    $response = $request("$root/document.docx", 'document.docx', 'application/zip', ['mode' => 'file']);
    check($response['ok'], 'DOCX container accepted');
    $response = $request("$root/source.png", 'source.png', 'image/png', ['mode' => 'send']);
    check(!$response['ok'], 'Second Send instance does not change first policy');
    $first = $request("$root/document.pdf", 'document.pdf', 'application/pdf', ['mode' => 'collision']);
    $second = $request("$root/document.pdf", 'document.pdf', 'application/pdf', ['mode' => 'collision']);
    check($first['ok'] && !$second['ok'] && is_file($first['path']), 'Exclusive creation rejects a forced destination collision');
    $response = $request("$root/document.pdf", 'document.pdf', 'application/pdf', ['mode' => 'failure']);
    check(!$response['ok'] && glob("$root/failure/*") === [], 'Failed write removes partial destination');

    $uploader = new MarketMedia\Uploader\Image($root, 'unit', false);
    rejects(fn () => $uploader->upload(['tmp_name' => "$root/source.png", 'name' => 'a.png', 'error' => 0], 'a'),
        'CLI local file cannot masquerade as HTTP upload');
    rejects(fn () => $uploader->upload(['name' => []], 'a'), 'Malformed upload rejected');
    foreach (['../escape', '/absolute', 'a/../../escape', 'a\\..\\escape', 'php://filter', 'C:\\escape'] as $directory) {
        rejects(fn () => new MarketMedia\Uploader\File($root, $directory), 'Unsafe subdirectory rejected: ' . $directory);
    }
    rejects(fn () => $uploader->setLimits(maxBytes: 0), 'Nonpositive limits rejected');
    $files = ['file' => ['name' => ['a.pdf', 'b.pdf'], 'type' => ['application/pdf', 'application/pdf'],
        'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [0, 0], 'size' => [10, 20]]];
    check($uploader->multiple('file', $files)[1]['size'] === 20, 'Multiple upload normalization');
    $uploader->setLimits(maxFiles: 1);
    rejects(fn () => $uploader->multiple('file', $files), 'Multiple upload count limit');
    rejects(fn () => $uploader->multiple(null, []), 'Malformed multiple upload rejected');
    $a = new MarketMedia\Uploader\Send($root, 'a', ['application/pdf'], ['pdf']);
    $b = new MarketMedia\Uploader\Send($root, 'b', ['image/png'], ['png']);
    check($a->isAllowed() === ['application/pdf'] && $b->isExtension() === ['png'], 'Send instance inspectors are isolated');
    rejects(fn () => new MarketMedia\Uploader\Send($root, 'bad', ['text/plain', 'application/pdf'], ['txt', 'pdf', 'csv']),
        'Ambiguous custom MIME mapping rejected');
    $get = static function (string $route, string $cookie = '') use ($address, $token): string {
        return file_get_contents('http://' . $address . $route, false, stream_context_create(['http' => [
            'ignore_errors' => true, 'header' => "X-Test-Token: $token\r\nCookie: $cookie\r\n",
        ]]));
    };
    $form = $get('/example/file.php');
    $headers = implode("\n", http_get_last_response_headers());
    preg_match('/Set-Cookie: ([^;]+)/i', $headers, $cookieMatch);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $csrfMatch);
    check(isset($cookieMatch[1], $csrfMatch[1]) && str_contains(strtolower($headers), 'httponly'),
        'Example creates CSRF token and protected session cookie');
    $cookie = $cookieMatch[1];
    $response = $request("$root/document.pdf", 'document.pdf', 'application/pdf', ['name' => 'test'],
        '/example/file.php', $cookie, false);
    check(str_contains($response, 'Requisição inválida'), 'Example rejects missing CSRF token');
    $response = $request("$root/document.pdf", 'document.pdf', 'application/pdf',
        ['name' => '\"<script>alert(1)</script>', 'csrf' => $csrfMatch[1]], '/example/file.php', $cookie, false);
    check((bool) preg_match('/download\.php\?id=([a-f0-9]{32})/', $response, $downloadMatch),
        'Example returns an opaque download link');
    check(!str_contains($response, $root) && !str_contains($response, '<script>'), 'Example output does not expose paths or raw input');
    $download = '/example/download.php?id=' . $downloadMatch[1];
    $content = $get($download, $cookie);
    $headers = strtolower(implode("\n", http_get_last_response_headers()));
    check($content === file_get_contents("$root/document.pdf") && str_contains($headers, 'content-disposition: attachment;')
        && str_contains($headers, 'x-content-type-options: nosniff'), 'Authorized example download is an attachment with nosniff');
    check($get($download) === '' && str_contains(http_get_last_response_headers()[0], '404'),
        'Different session cannot download the file');
    echo 'All tests passed on PHP ' . PHP_VERSION . " with E_ALL.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
