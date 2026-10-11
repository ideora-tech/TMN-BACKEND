<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu     = 'm0000001-0000-4000-8000-000000000108';
    private string $idKeuangan = 'm0000001-0000-4000-8000-000000000030';

    private array $urutanLaporan = [
        'm0000001-0000-4000-8000-000000000108' => 8,
        'm0000001-0000-4000-8000-000000000106' => 9,
        'm0000001-0000-4000-8000-000000000107' => 10,
    ];

    private array $peran = ['KEUANGAN', 'MANAGER', 'ADMIN'];

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Laporan Pemasukan', 'path' => '/laporan-pemasukan',
                'icon' => 'chartLineUp', 'id_menu_induk' => $this->idKeuangan, 'urutan' => 8,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        foreach ($this->urutanLaporan as $idMenu => $urutan) {
            DB::table('menu')->where('id_menu', $idMenu)->update(['urutan' => $urutan]);
        }

        DB::table('menu_peran')->insertOrIgnore(array_map(
            fn (string $kodePeran) => ['id_menu' => $this->idMenu, 'kode_peran' => $kodePeran],
            [...$this->peran, 'SUPERADMIN'],
        ));

        foreach ($this->peran as $kodePeran) {
            foreach (['lihat' => 1, 'tambah' => 0, 'ubah' => 0, 'hapus' => 0] as $aksi => $diizinkan) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $this->idMenu)
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
                    'id_menu'       => $this->idMenu,
                    'aksi'          => $aksi,
                    'diizinkan'     => $diizinkan,
                    'dibuat_pada'   => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();
    }
};
