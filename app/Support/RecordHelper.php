<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class RecordHelper
{
    public static function stampCreate(array $data, string $primaryKey): array
    {
        $data[$primaryKey] ??= (string) Str::uuid();
        $data['dibuat_pada'] = now();
        if (auth()->check()) {
            $data['dibuat_oleh'] = auth()->id();
        }
        return $data;
    }

    public static function stampUpdate(array $data): array
    {
        $data['diubah_pada'] = now();
        if (auth()->check()) {
            $data['diubah_oleh'] = auth()->id();
        }
        return $data;
    }

    public static function stampDelete(): array
    {
        $data = ['dihapus_pada' => now()];
        if (auth()->check()) {
            $data['dihapus_oleh'] = auth()->id();
        }
        return $data;
    }
}
