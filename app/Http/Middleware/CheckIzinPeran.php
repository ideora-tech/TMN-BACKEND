<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckIzinPeran
{
    private const AKSI = ['GET' => 'lihat', 'POST' => 'tambah', 'PUT' => 'ubah', 'PATCH' => 'ubah', 'DELETE' => 'hapus'];

    public function handle(Request $request, Closure $next, string $menuKey): Response
    {
        $user = $request->user();
        $kodePeran = $user?->kode_peran;
        if (in_array($kodePeran, ['SUPERADMIN', 'ADMIN'], true)) return $next($request);

        $aksi = self::AKSI[$request->method()] ?? 'lihat';
        $idPerusahaanUser = $user?->id_perusahaan;

        $rows = DB::table('izin_peran as ip')
            ->join('menu as m', 'm.id_menu', '=', 'ip.id_menu')
            ->where('m.path', '/' . $menuKey)
            ->where('ip.kode_peran', $kodePeran)
            ->where('ip.aksi', $aksi)
            ->whereNull('ip.dihapus_pada')
            ->where(function ($q) use ($idPerusahaanUser) {
                $q->where('ip.id_perusahaan', $idPerusahaanUser)
                    ->orWhereNull('ip.id_perusahaan');
            })
            ->get(['ip.diizinkan', 'ip.id_perusahaan']);

        // Baris per-perusahaan (jika ada) selalu menang atas baris global — termasuk
        // saat baris per-perusahaan tersebut adalah revoke (diizinkan = 0).
        $baris = $rows->first(fn ($r) => $r->id_perusahaan !== null) ?? $rows->first(fn ($r) => $r->id_perusahaan === null);
        $diizinkan = $baris !== null && (int) $baris->diizinkan === 1;

        if (!$diizinkan) {
            return ApiResponse::error('Anda tidak memiliki izin untuk aksi ini', null, 403);
        }
        return $next($request);
    }
}
