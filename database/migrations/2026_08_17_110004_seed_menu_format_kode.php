<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuFormatKode = 'm0000001-0000-4000-8000-000000000092';
    private string $idPengaturan     = 'm0000001-0000-4000-8000-000000000040';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idMenuFormatKode,
                'nama_menu'     => 'Format Kode',
                'path'          => '/format-kode',
                'icon'          => 'shield',
                'id_menu_induk' => $this->idPengaturan,
                'urutan'        => 8,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuFormatKode, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuFormatKode, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'ubah', 1],
        ];

        foreach ($izin as [$kodePeran, $aksi, $diizinkan]) {
            $exists = DB::table('izin_peran')
                ->where('id_menu', $this->idMenuFormatKode)
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
                'id_menu'       => $this->idMenuFormatKode,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenuFormatKode)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenuFormatKode)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuFormatKode)->delete();
    }
};
