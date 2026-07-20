<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [
            'dashboard'        => 'm0000001-0000-4000-8000-000000000001',
            'sales'            => 'm0000001-0000-4000-8000-000000000010',
            'klien'            => 'm0000001-0000-4000-8000-000000000011',
            'project'          => 'm0000001-0000-4000-8000-000000000012',
            'penawaran'        => 'm0000001-0000-4000-8000-000000000013',
            'operasional'      => 'm0000001-0000-4000-8000-000000000020',
            'armada'           => 'm0000001-0000-4000-8000-000000000021',
            'supir'            => 'm0000001-0000-4000-8000-000000000022',
            'vendor'           => 'm0000001-0000-4000-8000-000000000023',
            'trip'             => 'm0000001-0000-4000-8000-000000000025',
            'laporan'          => 'm0000001-0000-4000-8000-000000000026',
            'rute'             => 'm0000001-0000-4000-8000-000000000027',
            'keuangan'         => 'm0000001-0000-4000-8000-000000000030',
            'faktur'           => 'm0000001-0000-4000-8000-000000000031',
            'rekonsiliasi'     => 'm0000001-0000-4000-8000-000000000032',
            // Pengaturan
            'pengaturan'       => 'm0000001-0000-4000-8000-000000000040',
            'pengguna'         => 'm0000001-0000-4000-8000-000000000041',
            'peran'            => 'm0000001-0000-4000-8000-000000000042',
            'log_error'        => 'm0000001-0000-4000-8000-000000000043',
            'perusahaan_menu'  => 'm0000001-0000-4000-8000-000000000044',
            'menu_admin'       => 'm0000001-0000-4000-8000-000000000046',
            // Data Master
            'data_master'      => 'm0000001-0000-4000-8000-000000000050',
            'jenis_kendaraan'  => 'm0000001-0000-4000-8000-000000000051',
            'lokasi_kantor'    => 'm0000001-0000-4000-8000-000000000052',
            'departemen'       => 'm0000001-0000-4000-8000-000000000053',
            'jabatan'          => 'm0000001-0000-4000-8000-000000000054',
            'lokasi'           => 'm0000001-0000-4000-8000-000000000055',
            'jenis_bbm'        => 'm0000001-0000-4000-8000-000000000056',
            // HR
            'hr'               => 'm0000001-0000-4000-8000-000000000060',
            'karyawan'         => 'm0000001-0000-4000-8000-000000000061',
            // Operasional Vendor
            'operasional_vendor' => 'm0000001-0000-4000-8000-000000000070',
            'armada_vendor'      => 'm0000001-0000-4000-8000-000000000071',
            'supir_vendor'       => 'm0000001-0000-4000-8000-000000000072',
            'penugasan_vendor'   => 'm0000001-0000-4000-8000-000000000073',
        ];

        $now = now();

        // ── Tabel menu ────────────────────────────────────────────────────
        $menus = [
            ['id_menu' => $ids['dashboard'],   'nama_menu' => 'Dashboard',    'path' => '/home',          'id_menu_induk' => null,              'icon' => 'home',       'urutan' => 1],
            ['id_menu' => $ids['sales'],        'nama_menu' => 'Sales',        'path' => null,             'id_menu_induk' => null,              'icon' => 'handshake',  'urutan' => 2],
            ['id_menu' => $ids['klien'],        'nama_menu' => 'Klien',        'path' => '/klien',         'id_menu_induk' => $ids['sales'],      'icon' => 'handshake',  'urutan' => 1],
            ['id_menu' => $ids['project'],      'nama_menu' => 'Project',      'path' => '/project',       'id_menu_induk' => $ids['sales'],      'icon' => 'briefcase',  'urutan' => 2],
            ['id_menu' => $ids['penawaran'],    'nama_menu' => 'Penawaran',    'path' => '/penawaran',     'id_menu_induk' => $ids['sales'],      'icon' => 'notepad',    'urutan' => 3],
            ['id_menu' => $ids['operasional'],  'nama_menu' => 'Operasional',  'path' => null,             'id_menu_induk' => null,              'icon' => 'truck',      'urutan' => 3],
            ['id_menu' => $ids['armada'],       'nama_menu' => 'Armada',       'path' => '/armada',        'id_menu_induk' => $ids['operasional'],'icon' => 'truck',      'urutan' => 1],
            ['id_menu' => $ids['supir'],        'nama_menu' => 'Supir',        'path' => '/supir',         'id_menu_induk' => $ids['operasional'],'icon' => 'users',      'urutan' => 2],
            ['id_menu' => $ids['vendor'],       'nama_menu' => 'Vendor',       'path' => '/vendor',        'id_menu_induk' => $ids['operasional'],'icon' => 'building',   'urutan' => 3],
            ['id_menu' => $ids['trip'],         'nama_menu' => 'Trip Monitor', 'path' => '/trip',          'id_menu_induk' => $ids['operasional'],'icon' => 'mapPin',     'urutan' => 5],
            ['id_menu' => $ids['laporan'],      'nama_menu' => 'Laporan',      'path' => '/laporan',       'id_menu_induk' => $ids['operasional'],'icon' => 'clipboard',  'urutan' => 6],
            ['id_menu' => $ids['rute'],         'nama_menu' => 'Rute',         'path' => '/rute',          'id_menu_induk' => $ids['operasional'],'icon' => 'path',       'urutan' => 7],
            ['id_menu' => $ids['keuangan'],     'nama_menu' => 'Keuangan',     'path' => null,             'id_menu_induk' => null,              'icon' => 'receipt',    'urutan' => 6],
            ['id_menu' => $ids['faktur'],       'nama_menu' => 'Faktur',       'path' => '/faktur',        'id_menu_induk' => $ids['keuangan'],   'icon' => 'receipt',    'urutan' => 1],
            ['id_menu' => $ids['rekonsiliasi'],    'nama_menu' => 'Rekonsiliasi',    'path' => '/rekonsiliasi',    'id_menu_induk' => $ids['keuangan'],     'icon' => 'repeat',           'urutan' => 2],
            // Pengaturan
            ['id_menu' => $ids['pengaturan'],      'nama_menu' => 'Pengaturan',      'path' => null,               'id_menu_induk' => null,                  'icon' => 'settings',         'urutan' => 7],
            ['id_menu' => $ids['pengguna'],         'nama_menu' => 'Pengguna',         'path' => '/pengguna',        'id_menu_induk' => $ids['pengaturan'],    'icon' => 'userCheck',        'urutan' => 1],
            ['id_menu' => $ids['peran'],            'nama_menu' => 'Peran & Akses',    'path' => '/peran',           'id_menu_induk' => $ids['pengaturan'],    'icon' => 'shield',           'urutan' => 2],
            ['id_menu' => $ids['log_error'],        'nama_menu' => 'Log Error',        'path' => '/log-error',       'id_menu_induk' => $ids['pengaturan'],    'icon' => 'bug',              'urutan' => 3],
            ['id_menu' => $ids['perusahaan_menu'],  'nama_menu' => 'Perusahaan',       'path' => '/perusahaan',      'id_menu_induk' => $ids['pengaturan'],    'icon' => 'office',           'urutan' => 4],
            ['id_menu' => $ids['menu_admin'],       'nama_menu' => 'Menu',             'path' => '/menu-admin',      'id_menu_induk' => $ids['pengaturan'],    'icon' => 'treeStructure',    'urutan' => 5],
            // Data Master
            ['id_menu' => $ids['data_master'],     'nama_menu' => 'Data Master',     'path' => null,               'id_menu_induk' => null,                  'icon' => 'database',         'urutan' => 8],
            ['id_menu' => $ids['jenis_kendaraan'], 'nama_menu' => 'Jenis Kendaraan', 'path' => '/jenis-kendaraan', 'id_menu_induk' => $ids['data_master'],   'icon' => 'truck',            'urutan' => 1],
            ['id_menu' => $ids['lokasi_kantor'],   'nama_menu' => 'Lokasi Kantor',   'path' => '/lokasi-kantor',   'id_menu_induk' => $ids['data_master'],   'icon' => 'mapPin',           'urutan' => 2],
            ['id_menu' => $ids['departemen'],      'nama_menu' => 'Departemen',      'path' => '/departemen',      'id_menu_induk' => $ids['data_master'],   'icon' => 'layers',           'urutan' => 3],
            ['id_menu' => $ids['jabatan'],         'nama_menu' => 'Jabatan',         'path' => '/jabatan',         'id_menu_induk' => $ids['data_master'],   'icon' => 'briefcase',        'urutan' => 4],
            ['id_menu' => $ids['lokasi'],          'nama_menu' => 'Lokasi',          'path' => '/lokasi',          'id_menu_induk' => $ids['data_master'],   'icon' => 'mapPin',           'urutan' => 5],
            ['id_menu' => $ids['jenis_bbm'],       'nama_menu' => 'Jenis BBM',       'path' => '/jenis-bbm',       'id_menu_induk' => $ids['data_master'],   'icon' => 'database',         'urutan' => 6],
            // HR
            ['id_menu' => $ids['hr'],              'nama_menu' => 'HR',              'path' => null,               'id_menu_induk' => null,                  'icon' => 'users',            'urutan' => 9],
            ['id_menu' => $ids['karyawan'],        'nama_menu' => 'Karyawan',        'path' => '/karyawan',        'id_menu_induk' => $ids['hr'],            'icon' => 'userCircle',       'urutan' => 1],
            // Operasional Vendor
            ['id_menu' => $ids['operasional_vendor'], 'nama_menu' => 'Operasional Vendor', 'path' => null,                  'id_menu_induk' => null,                          'icon' => 'building',   'urutan' => 4],
            ['id_menu' => $ids['armada_vendor'],      'nama_menu' => 'Armada Vendor',      'path' => '/armada-vendor',      'id_menu_induk' => $ids['operasional_vendor'],    'icon' => 'truck',      'urutan' => 1],
            ['id_menu' => $ids['supir_vendor'],       'nama_menu' => 'Supir Vendor',       'path' => '/supir-vendor',       'id_menu_induk' => $ids['operasional_vendor'],    'icon' => 'users',      'urutan' => 2],
            ['id_menu' => $ids['penugasan_vendor'],   'nama_menu' => 'Penugasan Vendor',   'path' => '/penugasan-vendor',   'id_menu_induk' => $ids['operasional_vendor'],    'icon' => 'clipboard',  'urutan' => 3],
        ];

        foreach ($menus as $menu) {
            DB::table('menu')->upsert(
                array_merge($menu, ['aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null]),
                ['id_menu'],
                ['nama_menu', 'path', 'id_menu_induk', 'icon', 'urutan', 'aktif']
            );
        }

        // ── Tabel menu_peran ──────────────────────────────────────────────
        // Dashboard tidak didaftarkan → tampil untuk semua role
        $menuPeran = [
            // Sales section
            [$ids['sales'],    'SALES'],
            [$ids['sales'],    'MANAGER'],
            [$ids['sales'],    'ADMIN'],
            [$ids['sales'],    'SUPERADMIN'],
            [$ids['klien'],    'SALES'],
            [$ids['klien'],    'MANAGER'],
            [$ids['klien'],    'ADMIN'],
            [$ids['klien'],    'SUPERADMIN'],
            [$ids['project'],   'SALES'],
            [$ids['project'],   'MANAGER'],
            [$ids['project'],   'ADMIN'],
            [$ids['project'],   'SUPERADMIN'],
            [$ids['penawaran'], 'SALES'],
            [$ids['penawaran'], 'MANAGER'],
            [$ids['penawaran'], 'ADMIN'],
            [$ids['penawaran'], 'SUPERADMIN'],

            // Operasional section
            [$ids['operasional'], 'DISPATCHER'],
            [$ids['operasional'], 'MANAGER'],
            [$ids['operasional'], 'ADMIN'],
            [$ids['operasional'], 'SUPERADMIN'],
            [$ids['armada'],   'DISPATCHER'],
            [$ids['armada'],   'MANAGER'],
            [$ids['armada'],   'ADMIN'],
            [$ids['armada'],   'SUPERADMIN'],
            [$ids['supir'],    'DISPATCHER'],
            [$ids['supir'],    'MANAGER'],
            [$ids['supir'],    'ADMIN'],
            [$ids['supir'],    'SUPERADMIN'],
            [$ids['vendor'],   'DISPATCHER'],
            [$ids['vendor'],   'MANAGER'],
            [$ids['vendor'],   'ADMIN'],
            [$ids['vendor'],   'SUPERADMIN'],
            [$ids['trip'],     'DISPATCHER'],
            [$ids['trip'],     'MANAGER'],
            [$ids['trip'],     'ADMIN'],
            [$ids['trip'],     'SUPERADMIN'],
            [$ids['laporan'],  'DISPATCHER'],
            [$ids['laporan'],  'KEUANGAN'],
            [$ids['laporan'],  'MANAGER'],
            [$ids['laporan'],  'ADMIN'],
            [$ids['laporan'],  'SUPERADMIN'],
            [$ids['rute'],     'DISPATCHER'],
            [$ids['rute'],     'MANAGER'],
            [$ids['rute'],     'ADMIN'],
            [$ids['rute'],     'SUPERADMIN'],

            // Keuangan section
            [$ids['keuangan'],     'KEUANGAN'],
            [$ids['keuangan'],     'MANAGER'],
            [$ids['keuangan'],     'ADMIN'],
            [$ids['keuangan'],     'SUPERADMIN'],
            [$ids['faktur'],       'KEUANGAN'],
            [$ids['faktur'],       'MANAGER'],
            [$ids['faktur'],       'ADMIN'],
            [$ids['faktur'],       'SUPERADMIN'],
            [$ids['rekonsiliasi'], 'KEUANGAN'],
            [$ids['rekonsiliasi'], 'MANAGER'],
            [$ids['rekonsiliasi'], 'ADMIN'],
            [$ids['rekonsiliasi'], 'SUPERADMIN'],

            // Pengaturan — hanya ADMIN & SUPERADMIN
            [$ids['pengaturan'], 'ADMIN'],
            [$ids['pengaturan'], 'SUPERADMIN'],
            [$ids['pengguna'],   'ADMIN'],
            [$ids['pengguna'],   'SUPERADMIN'],
            [$ids['peran'],      'ADMIN'],
            [$ids['peran'],      'SUPERADMIN'],
            [$ids['log_error'],  'ADMIN'],
            [$ids['log_error'],  'SUPERADMIN'],
            [$ids['perusahaan_menu'], 'ADMIN'],
            [$ids['perusahaan_menu'], 'SUPERADMIN'],
            [$ids['menu_admin'], 'ADMIN'],
            [$ids['menu_admin'], 'SUPERADMIN'],

            // Data Master — ADMIN, SUPERADMIN, MANAGER
            [$ids['data_master'],     'ADMIN'],
            [$ids['data_master'],     'SUPERADMIN'],
            [$ids['data_master'],     'MANAGER'],
            [$ids['jenis_kendaraan'], 'ADMIN'],
            [$ids['jenis_kendaraan'], 'SUPERADMIN'],
            [$ids['jenis_kendaraan'], 'MANAGER'],
            [$ids['lokasi_kantor'],   'ADMIN'],
            [$ids['lokasi_kantor'],   'SUPERADMIN'],
            [$ids['lokasi_kantor'],   'MANAGER'],
            [$ids['departemen'],      'ADMIN'],
            [$ids['departemen'],      'SUPERADMIN'],
            [$ids['departemen'],      'MANAGER'],
            [$ids['jabatan'],         'ADMIN'],
            [$ids['jabatan'],         'SUPERADMIN'],
            [$ids['jabatan'],         'MANAGER'],
            [$ids['lokasi'],          'ADMIN'],
            [$ids['lokasi'],          'SUPERADMIN'],
            [$ids['lokasi'],          'MANAGER'],
            [$ids['lokasi'],          'DISPATCHER'],
            [$ids['jenis_bbm'],       'ADMIN'],
            [$ids['jenis_bbm'],       'SUPERADMIN'],
            [$ids['jenis_bbm'],       'MANAGER'],
            [$ids['jenis_bbm'],       'DISPATCHER'],

            // HR — ADMIN, SUPERADMIN, MANAGER
            [$ids['hr'],       'ADMIN'],
            [$ids['hr'],       'SUPERADMIN'],
            [$ids['hr'],       'MANAGER'],
            [$ids['karyawan'], 'ADMIN'],
            [$ids['karyawan'], 'SUPERADMIN'],
            [$ids['karyawan'], 'MANAGER'],

            // Operasional Vendor — DISPATCHER, MANAGER, ADMIN, SUPERADMIN
            [$ids['operasional_vendor'], 'DISPATCHER'],
            [$ids['operasional_vendor'], 'MANAGER'],
            [$ids['operasional_vendor'], 'ADMIN'],
            [$ids['operasional_vendor'], 'SUPERADMIN'],
            [$ids['armada_vendor'],      'DISPATCHER'],
            [$ids['armada_vendor'],      'MANAGER'],
            [$ids['armada_vendor'],      'ADMIN'],
            [$ids['armada_vendor'],      'SUPERADMIN'],
            [$ids['supir_vendor'],       'DISPATCHER'],
            [$ids['supir_vendor'],       'MANAGER'],
            [$ids['supir_vendor'],       'ADMIN'],
            [$ids['supir_vendor'],       'SUPERADMIN'],
            [$ids['penugasan_vendor'],   'DISPATCHER'],
            [$ids['penugasan_vendor'],   'MANAGER'],
            [$ids['penugasan_vendor'],   'ADMIN'],
            [$ids['penugasan_vendor'],   'SUPERADMIN'],
        ];

        foreach ($menuPeran as [$idMenu, $kodePeran]) {
            DB::table('menu_peran')->insertOrIgnore([
                'id_menu'    => $idMenu,
                'kode_peran' => $kodePeran,
            ]);
        }
    }
}
