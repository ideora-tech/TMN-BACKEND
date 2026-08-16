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
        ['ADMIN', '/penugasan', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['ADMIN', '/jadwal', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['ADMIN', '/tarif-rute', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['DISPATCHER', '/jadwal', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['MANAGER', '/jadwal', ['lihat']],
        ['SUPIR', '/trip', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['SUPIR', '/jadwal', ['lihat']],
        ['SUPIR', '/supir', ['lihat']],
        ['SUPIR', '/penugasan', ['lihat']],
        ['DISPATCHER', '/project', ['lihat']],
        ['DISPATCHER', '/tarif-rute', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['DISPATCHER', '/penugasan', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['DISPATCHER', '/karyawan', ['lihat']],
        ['DISPATCHER', '/jenis-kendaraan', ['lihat']],
        ['DISPATCHER', '/klien', ['lihat']],
        ['DISPATCHER', '/armada', ['lihat']],
        ['DISPATCHER', '/trip', ['lihat']],
        ['DISPATCHER', '/laporan', ['lihat']],
        ['MANAGER', '/penugasan', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['SALES', '/penugasan', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['SALES', '/rute', ['lihat']],
        ['SALES', '/jenis-kendaraan', ['lihat']],
        ['SALES', '/tarif-rute', ['lihat', 'tambah']],
        ['SALES', '/parameter-bok', ['lihat']],
        ['SALES', '/armada', ['lihat']],
        ['SALES', '/supir', ['lihat']],
        ['SALES', '/karyawan', ['lihat']],
        ['SALES', '/trip', ['lihat']],
        ['SALES', '/shift', ['lihat']],
        ['SALES', '/faktur', ['lihat']],
        ['KEUANGAN', '/klien', ['lihat']],
        ['KEUANGAN', '/project', ['lihat']],
        ['KEUANGAN', '/supir', ['lihat']],
        ['KEUANGAN', '/armada', ['lihat']],
        ['KEUANGAN', '/trip', ['lihat', 'tambah', 'ubah']],
        ['KEUANGAN', '/penugasan', ['lihat']],
        ['KEUANGAN', '/shift', ['lihat']],
    ];

    /**
     * Role yang punya baris menu_peran (menu tampil di sidebar) tapi sengaja
     * DIBATASI hanya sebagian aksi. Baris deny (diizinkan=0) dibuat SEBELUM loop
     * menu_peran di bawah, sehingga loop tersebut meng-skip aksi ini dan tidak
     * memberi izin penuh otomatis. Baris yang sudah ada tidak diubah (beda dengan
     * IZIN_EKSPLISIT) supaya grant manual admin via UI tidak ikut tercabut saat re-seed.
     * Format: [kode_peran, menu path, [aksi yang ditolak, ...]]
     */
    private const IZIN_TOLAK = [
        ['MANAGER', '/invoice-vendor', ['tambah', 'ubah', 'hapus']],
        ['ADMIN', '/invoice-vendor', ['tambah', 'ubah', 'hapus']],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::IZIN_TOLAK as [$kodePeran, $path, $aksiList]) {
            $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
            if ($idMenu === null) {
                $this->command?->warn("IzinPeranSeeder: menu path '{$path}' tidak ditemukan — deny [{$kodePeran}, {$path}] TIDAK terpasang");
                continue;
            }

            foreach ($aksiList as $aksi) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', $kodePeran)
                    ->where('aksi', $aksi)
                    ->whereNull('id_perusahaan')
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
                    'diizinkan'     => 0,
                    'dibuat_pada'   => $now,
                ]);
            }
        }

        $menuPeran = DB::table('menu_peran')->get(['id_menu', 'kode_peran']);

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

        $idMenuHome = DB::table('menu')->where('path', '/home')->value('id_menu');

        if ($idMenuHome !== null) {
            $peranNonSuperadmin = DB::table('peran')
                ->whereNull('dihapus_pada')
                ->pluck('kode_peran')
                ->map(fn ($k) => strtoupper((string) $k))
                ->unique()
                ->reject(fn ($k) => $k === 'SUPERADMIN');

            foreach ($peranNonSuperadmin as $kodePeran) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $idMenuHome)
                    ->where('kode_peran', $kodePeran)
                    ->where('aksi', 'lihat')
                    ->whereNull('id_perusahaan')
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('izin_peran')->insertOrIgnore([
                    'id_izin'       => (string) Str::uuid(),
                    'id_perusahaan' => null,
                    'kode_peran'    => $kodePeran,
                    'id_menu'       => $idMenuHome,
                    'aksi'          => 'lihat',
                    'diizinkan'     => 1,
                    'dibuat_pada'   => $now,
                ]);
            }
        } else {
            $this->command?->warn("IzinPeranSeeder: menu path '/home' tidak ditemukan — izin lihat dashboard TIDAK terpasang");
        }

        // Izin eksplisit per role — dibuat langsung di izin_peran, TANPA menyentuh
        // menu_peran, supaya menu tersebut tetap tidak tampil di sidebar web untuk role ini.
        foreach (self::IZIN_EKSPLISIT as [$kodePeran, $path, $aksiList]) {
            $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
            if ($idMenu === null) {
                // Menu bisa saja belum ada di lingkungan ini (mis. dibuat via UI di
                // instance lain) — jangan hard-fail, tapi jangan pula skip diam-diam:
                // tanpa baris izin, modul ber-guard path ini deny-all utk role tsb.
                $this->command?->warn("IzinPeranSeeder: menu path '{$path}' tidak ditemukan — izin [{$kodePeran}, {$path}] TIDAK terpasang");
                continue;
            }

            foreach ($aksiList as $aksi) {
                $existing = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', $kodePeran)
                    ->where('aksi', $aksi)
                    ->whereNull('id_perusahaan')
                    ->first(['id_izin', 'diizinkan']);

                if ($existing !== null) {
                    // Izin eksplisit = baseline fungsional aplikasi (mis. mobile supir) —
                    // baris yang keburu ter-set 0 dipaksa kembali 1 saat re-seed.
                    if ((int) $existing->diizinkan !== 1) {
                        DB::table('izin_peran')->where('id_izin', $existing->id_izin)
                            ->update(['diizinkan' => 1, 'diubah_pada' => $now]);
                    }
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
