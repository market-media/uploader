<?php

namespace MarketMedia\Uploader;

use RuntimeException;

class File extends Uploader
{
    protected const TYPES = [
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
        'bz' => ['application/x-bzip', 'application/x-bzip2'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'csv' => ['text/csv', 'text/plain'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    ];

    public function isAllowed(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TYPES))));
    }

    public function isExtension(): array
    {
        return array_keys(self::TYPES);
    }

    public function upload(array $file, string $name): string
    {
        $mime = $this->validateUpload($file);
        $this->validateType($mime, self::TYPES);
        if (in_array($this->ext, ['docx', 'xlsx', 'ods', 'odt'], true)) {
            $this->validateOffice($file['tmp_name']);
        }
        return $this->storeUpload($file, $name);
    }

    private function validateOffice(string $path): void
    {
        $zip = new \ZipArchive();
        if ($this->io(static fn () => $zip->open($path)) !== true) {
            throw new RuntimeException('Invalid office document');
        }
        try {
            if ($zip->numFiles > 10000) {
                throw new RuntimeException('Office document has too many entries');
            }
            $required = match ($this->ext) {
                'docx' => ['[Content_Types].xml', 'word/document.xml'],
                'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
                default => ['mimetype', 'content.xml'],
            };
            foreach ($required as $entry) {
                if ($zip->locateName($entry) === false) {
                    throw new RuntimeException('Document container does not match its extension');
                }
            }
            if (in_array($this->ext, ['ods', 'odt'], true)) {
                $expected = self::TYPES[$this->ext][0];
                $declared = $this->io(static fn () => $zip->getFromName('mimetype', 128));
                if ($declared !== $expected) {
                    throw new RuntimeException('Document container does not match its extension');
                }
            }
        } finally {
            $zip->close();
        }
    }
}