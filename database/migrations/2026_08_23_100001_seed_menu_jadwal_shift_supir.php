<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuJadwalShiftSupir = 'a3d51f2e-8c4b-4e7a-9f10-5b2d7c8e4a01';
    private string $idOperasional          = 'm0000001-0000-4000-8000-000000000020';

    /**
     * Izin fallback bila menu /penugasan tidak ditemukan (DB baru yang belum
     * pernah membuat menu Penugasan) — set yang sama dengan IzinPeranSeeder
     * untuk /penugasan.
     */
    private const IZIN_FALLBACK = [
        ['ADMIN', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['DISPATCHER', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['MANAGER', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['SALES', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['KEUANGAN', ['lihat']],
        ['SUPIR', ['lihat']],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('menu') || !Schema::hasTable('izin_peran')) {
            return;
        }

        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuJadwalShiftSupir, 'nama_menu' => 'Jadwal Shift Supir', 'path' => '/jadwal-shift-supir',
                'icon' => 'userCheck', 'id_menu_induk' => $this->idOperasional, 'urutan' => 3,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        $idMenuPenugasan = DB::table('menu')->where('path', '/penugasan')->whereNull('dihapus_pada')->value('id_menu');

        if ($idMenuPenugasan !== null) {
            if (Schema::hasTable('menu_peran')) {
                $peranSumber = DB::table('menu_peran')->where('id_menu', $idMenuPenugasan)->pluck('kode_peran');
                foreach ($peranSumber as $kodePeran) {
                    DB::table('menu_peran')->insertOrIgnore([
                        ['id_menu' => $this->idMenuJadwalShiftSupir, 'kode_peran' => $kodePeran],
                    ]);
                }
            }

            $izinSumber = DB::table('izin_peran')
                ->where('id_menu', $idMenuPenugasan)
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
            ->where('id_menu', $this->idMenuJadwalShiftSupir)
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
            'id_menu'       => $this->idMenuJadwalShiftSupir,
            'aksi'          => $aksi,
            'diizinkan'     => $diizinkan,
            'dibuat_pada'   => $now,
        ]);
    }

    public function down(): void
    {
    }
};
