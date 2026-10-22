<?php

namespace MarketMedia\Uploader;

use InvalidArgumentException;
use RuntimeException;

class Image extends Uploader
{
    protected const TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    public function isAllowed(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TYPES))));
    }

    public function isExtension(): array
    {
        return array_keys(self::TYPES);
    }

    public function upload(array $image, string $name, int $width = 2000, ?array $quality = null): string
    {
        if ($width < 1 || $width > $this->maxWidth) {
            throw new InvalidArgumentException('Invalid output width');
        }
        $quality = array_replace(['jpg' => 75, 'png' => 5, 'webp' => 75], $quality ?? []);
        foreach (['jpg' => 100, 'png' => 9, 'webp' => 100] as $format => $maximum) {
            if (!is_int($quality[$format]) || $quality[$format] < 0 || $quality[$format] > $maximum) {
                throw new InvalidArgumentException('Invalid image quality');
            }
        }
        $mime = $this->validateUpload($image);
        $this->validateType($mime, self::TYPES);
        $size = $this->io(static fn () => getimagesize($image['tmp_name']));
        if ($size === false || ($size['mime'] ?? null) !== $mime || $size[0] < 1 || $size[1] < 1
            || $size[0] > $this->maxWidth || $size[1] > $this->maxHeight
            || $size[0] > intdiv($this->maxPixels, $size[1])) {
            throw new RuntimeException('Invalid image or image dimensions exceed limits');
        }
        $decoder = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
        };
        $decoded = $this->io(static fn () => $decoder($image['tmp_name']));
        if (!$decoded instanceof \GdImage) {
            throw new RuntimeException('Could not decode image');
        }
        $this->file = $decoded;
        try {
            if (imagesx($decoded) !== $size[0] || imagesy($decoded) !== $size[1]) {
                throw new RuntimeException('Inconsistent image dimensions');
            }
            if ($this->ext === 'jpeg') {
                $this->ext = 'jpg';
            }
            return $this->store($name, function ($stream) use ($width, $quality): void {
                $this->imageGenerate($stream, $width, $quality);
            });
        } finally {
            $this->file = null;
        }
    }

    private function imageGenerate($stream, int $width, array $quality): void
    {
        $sourceWidth = imagesx($this->file);
        $sourceHeight = imagesy($this->file);
        $targetWidth = min($width, $sourceWidth);
        $targetHeight = max(1, (int) floor($targetWidth * $sourceHeight / $sourceWidth));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new RuntimeException('Could not allocate output image');
        }
        if ($this->ext !== 'jpg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
            if ($this->ext === 'gif') {
                imagecolortransparent($target, $transparent);
            }
        }
        if (!imagecopyresampled($target, $this->file, 0, 0, 0, 0,
            $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
            throw new RuntimeException('Could not resize image');
        }
        $saved = match ($this->ext) {
            'jpg' => imagejpeg($target, $stream, $quality['jpg']),
            'png' => imagepng($target, $stream, $quality['png']),
            'gif' => imagegif($target, $stream),
            'webp' => imagewebp($target, $stream, $quality['webp']),
        };
        if (!$saved) {
            throw new RuntimeException('Could not encode image');
        }
    }
}