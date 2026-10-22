<?php

namespace MarketMedia\Uploader;

use InvalidArgumentException;

class Send extends Uploader
{
    private array $types;

    public function __construct(
        string $uploadDir,
        string $fileTypeDir,
        array $allowTypes,
        array $extensions,
        bool $monthYearPath = true,
        array $mimeTypesByExtension = []
    ) {
        if ($allowTypes === [] || $extensions === [] || !array_is_list($allowTypes)
            || !array_is_list($extensions) || count(array_unique($extensions, SORT_REGULAR)) !== count($extensions)) {
            throw new InvalidArgumentException('A nonempty file type policy is required');
        }
        foreach ($allowTypes as $mime) {
            if (!is_string($mime) || !preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~iD', $mime)) {
                throw new InvalidArgumentException('Invalid MIME type policy');
            }
        }
        foreach ($extensions as $extension) {
            if (!is_string($extension) || !preg_match('/\A[a-z0-9]{1,10}\z/D', $extension)) {
                throw new InvalidArgumentException('Invalid extension policy');
            }
        }
        if ($mimeTypesByExtension === []) {
            if (count($allowTypes) !== 1 && count($allowTypes) !== count($extensions)) {
                throw new InvalidArgumentException('Provide an explicit MIME map for ambiguous policies');
            }
            foreach ($extensions as $index => $extension) {
                $mimeTypesByExtension[$extension] = [count($allowTypes) === 1 ? $allowTypes[0] : $allowTypes[$index]];
            }
        }
        if (count($mimeTypesByExtension) !== count($extensions)) {
            throw new InvalidArgumentException('MIME map must match the extension policy');
        }
        foreach ($extensions as $extension) {
            $mimes = $mimeTypesByExtension[$extension] ?? null;
            if (!is_array($mimes) || $mimes === [] || !array_is_list($mimes)) {
                throw new InvalidArgumentException('Missing MIME mapping for extension');
            }
            foreach ($mimes as $mime) {
                if (!is_string($mime) || !in_array($mime, $allowTypes, true)) {
                    throw new InvalidArgumentException('MIME map contains an unapproved type');
                }
            }
        }
        $this->types = $mimeTypesByExtension;
        parent::__construct($uploadDir, $fileTypeDir, $monthYearPath);
    }

    public function isAllowed(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->types))));
    }

    public function isExtension(): array
    {
        return array_keys($this->types);
    }

    public function upload(array $file, string $name): string
    {
        $mime = $this->validateUpload($file);
        $this->validateType($mime, $this->types);
        return $this->storeUpload($file, $name);
    }
}