<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $kodePeran = $request->user()?->kode_peran;

        if (!$kodePeran || !in_array($kodePeran, $roles, true)) {
            return ApiResponse::error('Anda tidak memiliki akses untuk resource ini', null, 403);
        }

        return $next($request);
    }
}
