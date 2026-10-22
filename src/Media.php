<?php

namespace MarketMedia\Uploader;

class Media extends Uploader
{
    protected const TYPES = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'mp4' => ['video/mp4', 'application/mp4'],
    ];

    public function isAllowed(): array
    {
        return array_merge(...array_values(self::TYPES));
    }

    public function isExtension(): array
    {
        return array_keys(self::TYPES);
    }

    public function upload(array $media, string $name): string
    {
        $mime = $this->validateUpload($media);
        $this->validateType($mime, self::TYPES);
        return $this->storeUpload($media, $name);
    }
}