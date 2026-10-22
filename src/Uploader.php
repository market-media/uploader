<?php

namespace MarketMedia\Uploader;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

abstract class Uploader
{
    protected string $path;
    protected string $root;
    protected string $name;
    protected string $ext;
    protected ?\GdImage $file = null;
    protected static array $allowTypes = [];
    protected static array $extensions = [];
    protected int $maxBytes = 20971520;
    protected int $maxFiles = 20;
    protected int $maxWidth = 8192;
    protected int $maxHeight = 8192;
    protected int $maxPixels = 12000000;

    public function __construct(string $uploadDir, string $fileTypeDir, bool $monthYearPath = true)
    {
        if ($uploadDir === '' || str_contains($uploadDir, "\0") || str_contains($uploadDir, '://')
            || is_link($uploadDir)) {
            throw new InvalidArgumentException('Invalid storage directory');
        }
        // Subdirectories are relative to the trusted storage root.
        $parts = explode('/', str_replace('\\', '/', $fileTypeDir));
        foreach ($parts as $part) {
            if (!preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\z/D', $part)) {
                throw new InvalidArgumentException('Invalid storage subdirectory');
            }
        }
        $this->dir($uploadDir);
        $resolved = realpath($uploadDir);
        if ($resolved === false) {
            throw new RuntimeException('Storage directory unavailable');
        }
        $this->root = str_replace('\\', '/', $resolved);
        $this->path = $this->root;
        if ($monthYearPath) {
            $parts = array_merge($parts, explode('/', date('Y/m')));
        }
        foreach ($parts as $part) {
            $this->path .= '/' . $part;
            $this->dir($this->path);
            $this->assertStorage();
        }
    }

    public function isAllowed(): array
    {
        return static::$allowTypes;
    }

    public function isExtension(): array
    {
        return static::$extensions;
    }

    public function setLimits(
        int $maxBytes = 20971520,
        int $maxFiles = 20,
        int $maxWidth = 8192,
        int $maxHeight = 8192,
        int $maxPixels = 12000000
    ): static {
        if (min($maxBytes, $maxFiles, $maxWidth, $maxHeight, $maxPixels) < 1) {
            throw new InvalidArgumentException('Limits must be positive integers');
        }
        $this->maxBytes = $maxBytes;
        $this->maxFiles = $maxFiles;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->maxPixels = $maxPixels;
        return $this;
    }

    protected function dir(string $dir, int $mode = 0700): void
    {
        if (is_link($dir)) {
            throw new RuntimeException('Symbolic links are not allowed in storage');
        }
        if (!is_dir($dir) && !$this->io(static fn () => mkdir($dir, $mode, true))) {
            throw new RuntimeException('Could not create storage directory');
        }
    }

    protected function assertStorage(): void
    {
        clearstatcache(true);
        $relative = substr($this->path, strlen($this->root));
        $current = $this->root;
        foreach (explode('/', ltrim($relative, '/')) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new RuntimeException('Symbolic links are not allowed in storage');
            }
        }
        $resolved = realpath($this->path);
        if ($resolved === false || str_replace('\\', '/', $resolved) !== $this->path
            || !str_starts_with($this->path, rtrim($this->root, '/') . '/')) {
            throw new RuntimeException('Storage path is outside its configured root');
        }
    }

    protected function validateUpload(array $upload): string
    {
        foreach (['name', 'tmp_name'] as $key) {
            if (!isset($upload[$key]) || !is_string($upload[$key])
                || $upload[$key] === '' || str_contains($upload[$key], "\0")) {
                throw new InvalidArgumentException('Invalid upload data');
            }
        }
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('A successful HTTP upload is required');
        }
        $size = $this->io(static fn () => filesize($upload['tmp_name']));
        if ($size === false || $size < 1 || $size > $this->maxBytes) {
            throw new RuntimeException('File exceeds the allowed size or is empty');
        }
        $this->ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!preg_match('/\A[a-z0-9]{1,10}\z/D', $this->ext)) {
            throw new InvalidArgumentException('Invalid file extension');
        }
        $mime = $this->io(static fn () => (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']));
        if (!is_string($mime) || $mime === '') {
            throw new RuntimeException('Could not identify file type');
        }
        return $mime;
    }

    protected function validateType(string $mime, array $types): void
    {
        if (!isset($types[$this->ext]) || !in_array($mime, $types[$this->ext], true)) {
            throw new RuntimeException('File content does not match an allowed extension');
        }
    }

    // The supplied display name is deliberately not used as a storage identifier.
    protected function name(string $name): string
    {
        $this->name = bin2hex(random_bytes(16)) . '.' . $this->ext;
        return $this->name;
    }

    protected function store(string $name, callable $writer): string
    {
        $this->assertStorage();
        $this->name($name);
        $destination = $this->path . '/' . $this->name;
        // Exclusive creation: never truncate an existing file, even on collision.
        $stream = $this->io(static fn () => fopen($destination, 'x+b'));
        if ($stream === false) {
            throw new RuntimeException('Could not reserve upload destination');
        }
        $success = false;
        try {
            if (!$this->io(static fn () => chmod($destination, 0600))) {
                throw new RuntimeException('Could not secure upload permissions');
            }
            $this->io(static fn () => $writer($stream));
            if (!$this->io(static fn () => fflush($stream))) {
                throw new RuntimeException('Could not persist uploaded file');
            }
            $metadata = $this->io(static fn () => fstat($stream));
            if ($metadata === false || $metadata['size'] < 1 || $metadata['size'] > $this->maxBytes) {
                throw new RuntimeException('Output file exceeds the allowed size or is empty');
            }
            if (!fclose($stream)) {
                throw new RuntimeException('Could not close uploaded file');
            }
            $stream = null;
            $success = true;
            return $destination;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$success && is_file($destination)) {
                $this->io(static fn () => unlink($destination));
            }
        }
    }

    protected function storeUpload(array $upload, string $name): string
    {
        return $this->store($name, function ($destination) use ($upload): void {
            $source = $this->io(static fn () => fopen($upload['tmp_name'], 'rb'));
            if ($source === false) {
                throw new RuntimeException('Could not read uploaded file');
            }
            try {
                $size = fstat($source)['size'];
                $copied = stream_copy_to_stream($source, $destination, $this->maxBytes + 1);
                if ($copied !== $size || $copied > $this->maxBytes) {
                    throw new RuntimeException('Could not save complete uploaded file');
                }
            } finally {
                fclose($source);
            }
        });
    }

    protected function io(callable $operation): mixed
    {
        set_error_handler(static function (): never {
            throw new RuntimeException('Upload processing failed');
        });
        try {
            return $operation();
        } catch (Throwable $error) {
            throw new RuntimeException('Upload processing failed', 0, $error);
        } finally {
            restore_error_handler();
        }
    }

    public function multiple($inputName, $files): array
    {
        if (!is_string($inputName) || !is_array($files) || !isset($files[$inputName])
            || !is_array($files[$inputName])) {
            throw new InvalidArgumentException('Invalid multiple upload data');
        }
        $group = $files[$inputName];
        $keys = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (!isset($group['name']) || !is_array($group['name'])
            || count($group['name']) > $this->maxFiles || !array_is_list($group['name'])) {
            throw new InvalidArgumentException('Invalid upload list or too many files');
        }
        $count = count($group['name']);
        foreach ($keys as $key) {
            if (!isset($group[$key]) || !is_array($group[$key])
                || !array_is_list($group[$key]) || count($group[$key]) !== $count) {
                throw new InvalidArgumentException('Invalid multiple upload structure');
            }
        }
        $result = [];
        for ($index = 0; $index < $count; $index++) {
            foreach ($keys as $key) {
                $value = $group[$key][$index];
                if (in_array($key, ['error', 'size'], true) ? !is_int($value) : !is_string($value)) {
                    throw new InvalidArgumentException('Invalid multiple upload field');
                }
                $result[$index][$key] = $value;
            }
        }
        return $result;
    }
}
