<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $perans = DB::table('peran')->whereNull('dihapus_pada')
            ->pluck('kode_peran')
            ->map(fn ($k) => strtoupper((string) $k))
            ->unique()
            ->reject(fn ($k) => $k === 'SUPERADMIN')
            ->values()->all();

        $menuPeran = DB::table('menu_peran')->get()->groupBy('id_menu');
        $izinAda = DB::table('izin_peran')
            ->where('aksi', 'lihat')->whereNull('id_perusahaan')->whereNull('dihapus_pada')
            ->get(['id_menu', 'kode_peran'])
            ->map(fn ($r) => $r->id_menu . '|' . strtoupper((string) $r->kode_peran))
            ->flip();

        $baru = [];
        foreach (DB::table('menu')->whereNull('dihapus_pada')->where('aktif', 1)->whereNotNull('path')->get(['id_menu']) as $m) {
            $rows    = ($menuPeran[$m->id_menu] ?? collect())->map(fn ($r) => strtoupper((string) $r->kode_peran));
            $terbuka = $rows->isEmpty();
            foreach ($perans as $kode) {
                if (!$terbuka && !$rows->contains($kode)) continue;
                if (isset($izinAda[$m->id_menu . '|' . $kode])) continue;
                $baru[] = [
                    'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null,
                    'kode_peran' => $kode, 'id_menu' => $m->id_menu,
                    'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => $now,
                ];
            }
        }
        foreach (array_chunk($baru, 500) as $chunk) {
            DB::table('izin_peran')->insert($chunk);
        }

        DB::table('menu')->where('path', '/akses-menu')->whereNull('dihapus_pada')
            ->update(['dihapus_pada' => $now]);
    }

    public function down(): void
    {
        DB::table('menu')->where('path', '/akses-menu')->update(['dihapus_pada' => null]);
    }
};
