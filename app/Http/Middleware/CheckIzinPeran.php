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
        if ($kodePeran === 'SUPERADMIN') return $next($request);

        $aksi = self::AKSI[$request->method()] ?? 'lihat';
        $idPerusahaanUser = $user?->id_perusahaan;

        // Beberapa kunci menu boleh dipisah '|' — dipakai endpoint data referensi yang
        // dibutuhkan lintas modul (mis. jenis kendaraan dibaca form perawatan/armada).
        // Cukup satu menu mengizinkan agar aksi lolos.
        $paths = array_map(fn ($key) => '/' . trim($key), explode('|', $menuKey));

        $rows = DB::table('izin_peran as ip')
            ->join('menu as m', 'm.id_menu', '=', 'ip.id_menu')
            ->whereNull('m.dihapus_pada')
            ->whereIn('m.path', $paths)
            ->where('ip.kode_peran', $kodePeran)
            ->where('ip.aksi', $aksi)
            ->whereNull('ip.dihapus_pada')
            ->where(function ($q) use ($idPerusahaanUser) {
                $q->where('ip.id_perusahaan', $idPerusahaanUser)
                    ->orWhereNull('ip.id_perusahaan');
            })
            ->get(['m.path', 'ip.diizinkan', 'ip.id_perusahaan']);

        // Baris per-perusahaan (jika ada) selalu menang atas baris global — termasuk
        // saat baris per-perusahaan tersebut adalah revoke (diizinkan = 0). Evaluasi
        // dilakukan per menu, lalu hasilnya di-OR antar menu.
        $diizinkan = false;
        foreach ($rows->groupBy('path') as $barisMenu) {
            $baris = $barisMenu->first(fn ($r) => $r->id_perusahaan !== null)
                ?? $barisMenu->first(fn ($r) => $r->id_perusahaan === null);
            if ($baris !== null && (int) $baris->diizinkan === 1) {
                $diizinkan = true;
                break;
            }
        }

        if (!$diizinkan) {
            return ApiResponse::error('Anda tidak memiliki izin untuk aksi ini', null, 403);
        }
        return $next($request);
    }
}
