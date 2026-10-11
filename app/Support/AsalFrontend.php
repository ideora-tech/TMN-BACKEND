<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class AsalFrontend
{
    public const HEADER = 'X-Frontend-Url';

    public static function dari(Request $request): ?string
    {
        return self::bersihkan(config('app.frontend_url'))
            ?? self::bersihkan($request->header(self::HEADER));
    }

    public static function tautan(?string $asal, ?string $path): ?string
    {
        if ($asal === null || $path === null || $path === '' || $path[0] !== '/') {
            return null;
        }

        return $asal . $path;
    }

    private static function bersihkan(mixed $nilai): ?string
    {
        if (!is_string($nilai)) {
            return null;
        }

        $nilai = rtrim(trim($nilai), '/');

        return preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$#', $nilai) === 1 ? $nilai : null;
    }
}
