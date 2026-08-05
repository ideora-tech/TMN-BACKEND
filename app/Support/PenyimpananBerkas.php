<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PenyimpananBerkas
{
    public static function simpan(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, 'public');
    }

    public static function url(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (str_starts_with($nilai, 'http://') || str_starts_with($nilai, 'https://')) {
            return $nilai;
        }

        return Storage::disk('public')->url($nilai);
    }
}
