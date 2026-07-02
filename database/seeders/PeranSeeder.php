<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PeranSeeder extends Seeder
{
    public function run(): void
    {
        $idPerusahaan = 'b8f3c1a2-0000-4000-8000-000000000001';

        $roles = [
            // Platform role (is_platform=1, id_perusahaan=null)
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000001',
                'id_perusahaan' => null,
                'kode_peran'    => 'SUPERADMIN',
                'nama_peran'    => 'Super Administrator',
                'is_platform'   => 1,
                'aktif'         => 1,
            ],
            // Company roles
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000002',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'ADMIN',
                'nama_peran'    => 'Administrator',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000003',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'MANAGER',
                'nama_peran'    => 'Manager',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000004',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'DISPATCHER',
                'nama_peran'    => 'Dispatcher',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000005',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'SUPIR',
                'nama_peran'    => 'Supir',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000006',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'KEUANGAN',
                'nama_peran'    => 'Keuangan',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
            [
                'id_peran'      => 'a1b2c3d4-0000-4000-8000-000000000007',
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => 'SALES',
                'nama_peran'    => 'Sales',
                'is_platform'   => 0,
                'aktif'         => 1,
            ],
        ];

        foreach ($roles as $role) {
            DB::table('peran')->insertOrIgnore(array_merge($role, [
                'dibuat_pada' => now(),
                'dibuat_oleh' => null,
            ]));
        }
    }
}
