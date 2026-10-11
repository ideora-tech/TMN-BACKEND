<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idKeuangan = 'm0000001-0000-4000-8000-000000000030';

    private array $menu = [
        [
            'id_menu' => 'm0000001-0000-4000-8000-000000000106', 'nama_menu' => 'Laporan Pengeluaran',
            'path' => '/laporan-pengeluaran', 'icon' => 'chartBar', 'urutan' => 8,
        ],
        [
            'id_menu' => 'm0000001-0000-4000-8000-000000000107', 'nama_menu' => 'Rekap Pajak',
            'path' => '/rekap-pajak', 'icon' => 'percent', 'urutan' => 9,
        ],
    ];

    private array $peran = ['KEUANGAN', 'MANAGER', 'ADMIN'];

    public function up(): void
    {
        $now = now();

        foreach ($this->menu as $menu) {
            DB::table('menu')->upsert([
                $menu + ['id_menu_induk' => $this->idKeuangan, 'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null],
            ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

            DB::table('menu_peran')->insertOrIgnore(array_map(
                fn (string $kodePeran) => ['id_menu' => $menu['id_menu'], 'kode_peran' => $kodePeran],
                [...$this->peran, 'SUPERADMIN'],
            ));

            foreach ($this->peran as $kodePeran) {
                foreach (['lihat' => 1, 'tambah' => 0, 'ubah' => 0, 'hapus' => 0] as $aksi => $diizinkan) {
                    $exists = DB::table('izin_peran')
                        ->where('id_menu', $menu['id_menu'])
                        ->where('kode_peran', $kodePeran)
                        ->where('aksi', $aksi)
                        ->whereNull('id_perusahaan')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('izin_peran')->insert([
                        'id_izin'       => (string) Str::uuid(),
                        'id_perusahaan' => null,
                        'kode_peran'    => $kodePeran,
                        'id_menu'       => $menu['id_menu'],
                        'aksi'          => $aksi,
                        'diizinkan'     => $diizinkan,
                        'dibuat_pada'   => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = array_column($this->menu, 'id_menu');

        DB::table('izin_peran')->whereIn('id_menu', $ids)->delete();
        DB::table('menu_peran')->whereIn('id_menu', $ids)->delete();
        DB::table('menu')->whereIn('id_menu', $ids)->delete();
    }
};
