<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuStrukturOrganisasi = 'f4a8c3d1-2b6e-4f19-8a7d-3e91c5b6d820';
    private string $idHr                     = 'm0000001-0000-4000-8000-000000000060';

    /**
     * Izin fallback bila menu /jabatan tidak ditemukan (DB baru yang belum
     * pernah membuat menu Jabatan).
     */
    private const IZIN_FALLBACK = [
        ['ADMIN', ['lihat']],
        ['MANAGER', ['lihat']],
        ['SUPERADMIN', ['lihat']],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('menu') || !Schema::hasTable('izin_peran')) {
            return;
        }

        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuStrukturOrganisasi, 'nama_menu' => 'Struktur Organisasi', 'path' => '/struktur-organisasi',
                'icon' => 'usersThree', 'id_menu_induk' => $this->idHr, 'urutan' => 5,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        $idMenuJabatan = DB::table('menu')->where('path', '/jabatan')->whereNull('dihapus_pada')->value('id_menu');

        if ($idMenuJabatan !== null) {
            if (Schema::hasTable('menu_peran')) {
                $peranSumber = DB::table('menu_peran')->where('id_menu', $idMenuJabatan)->pluck('kode_peran');
                foreach ($peranSumber as $kodePeran) {
                    DB::table('menu_peran')->insertOrIgnore([
                        ['id_menu' => $this->idMenuStrukturOrganisasi, 'kode_peran' => $kodePeran],
                    ]);
                }
            }

            $izinSumber = DB::table('izin_peran')
                ->where('id_menu', $idMenuJabatan)
                ->whereNull('dihapus_pada')
                ->get(['id_perusahaan', 'kode_peran', 'aksi', 'diizinkan']);

            foreach ($izinSumber as $izin) {
                $this->insertIzinJikaBelumAda($izin->kode_peran, $izin->aksi, (int) $izin->diizinkan, $izin->id_perusahaan, $now);
            }

            return;
        }

        foreach (self::IZIN_FALLBACK as [$kodePeran, $aksiList]) {
            foreach ($aksiList as $aksi) {
                $this->insertIzinJikaBelumAda($kodePeran, $aksi, 1, null, $now);
            }
        }
    }

    private function insertIzinJikaBelumAda(string $kodePeran, string $aksi, int $diizinkan, ?string $idPerusahaan, $now): void
    {
        $exists = DB::table('izin_peran')
            ->where('id_menu', $this->idMenuStrukturOrganisasi)
            ->where('kode_peran', $kodePeran)
            ->where('aksi', $aksi)
            ->when(
                $idPerusahaan === null,
                fn ($q) => $q->whereNull('id_perusahaan'),
                fn ($q) => $q->where('id_perusahaan', $idPerusahaan),
            )
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('izin_peran')->insert([
            'id_izin'       => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaan,
            'kode_peran'    => $kodePeran,
            'id_menu'       => $this->idMenuStrukturOrganisasi,
            'aksi'          => $aksi,
            'diizinkan'     => $diizinkan,
            'dibuat_pada'   => $now,
        ]);
    }

    public function down(): void
    {
    }
};
