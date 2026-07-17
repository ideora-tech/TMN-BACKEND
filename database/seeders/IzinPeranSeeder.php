<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IzinPeranSeeder extends Seeder
{
    private const AKSI = ['lihat', 'tambah', 'ubah', 'hapus'];

    /**
     * SUPIR (dan sebagian DISPATCHER) tidak selalu punya baris di menu_peran karena
     * menu tersebut tidak tampil di sidebar web untuk role tersebut, tapi role ini tetap
     * butuh akses API granular ke endpoint-endpoint tertentu (mis. dari aplikasi mobile).
     * Format: [kode_peran, menu path, [aksi, ...]]
     */
    private const IZIN_EKSPLISIT = [
        ['SUPIR', '/trip', ['lihat', 'tambah', 'ubah']],
        ['SUPIR', '/jadwal', ['lihat']],
        ['SUPIR', '/supir', ['lihat']],
        ['DISPATCHER', '/project', ['lihat']],
        ['DISPATCHER', '/tarif-rute', ['lihat']],
    ];

    public function run(): void
    {
        $menuPeran = DB::table('menu_peran')->get(['id_menu', 'kode_peran']);
        $now = now();

        foreach ($menuPeran as $row) {
            foreach (self::AKSI as $aksi) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $row->id_menu)
                    ->where('kode_peran', $row->kode_peran)
                    ->where('aksi', $aksi)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('izin_peran')->insertOrIgnore([
                    'id_izin'     => (string) Str::uuid(),
                    'kode_peran'  => $row->kode_peran,
                    'id_menu'     => $row->id_menu,
                    'aksi'        => $aksi,
                    'diizinkan'   => 1,
                    'dibuat_pada' => $now,
                ]);
            }
        }

        // Izin eksplisit per role — dibuat langsung di izin_peran, TANPA menyentuh
        // menu_peran, supaya menu tersebut tetap tidak tampil di sidebar web untuk role ini.
        foreach (self::IZIN_EKSPLISIT as [$kodePeran, $path, $aksiList]) {
            $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
            if ($idMenu === null) {
                continue;
            }

            foreach ($aksiList as $aksi) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', $kodePeran)
                    ->where('aksi', $aksi)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('izin_peran')->insertOrIgnore([
                    'id_izin'       => (string) Str::uuid(),
                    'id_perusahaan' => null,
                    'kode_peran'    => $kodePeran,
                    'id_menu'       => $idMenu,
                    'aksi'          => $aksi,
                    'diizinkan'     => 1,
                    'dibuat_pada'   => $now,
                ]);
            }
        }
    }
}
