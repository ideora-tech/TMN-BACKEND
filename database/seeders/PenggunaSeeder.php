<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PenggunaSeeder extends Seeder
{
    public function run(): void
    {
        $idPerusahaan = 'b8f3c1a2-0000-4000-8000-000000000001';

        $users = [
            [
                'id_pengguna'          => 'c1d2e3f4-0000-4000-8000-000000000001',
                'id_perusahaan'        => null,
                'kode_peran'           => 'SUPERADMIN',
                'username'             => 'superadmin',
                'email'                => 'superadmin@tmntransport.id',
                'kata_sandi'           => Hash::make('Password123!'),
                'aktif'                => 1,
                'harus_ganti_password' => 0,
                'dibuat_pada'          => now(),
                'dibuat_oleh'          => null,
            ],
            [
                'id_pengguna'          => 'c1d2e3f4-0000-4000-8000-000000000002',
                'id_perusahaan'        => $idPerusahaan,
                'kode_peran'           => 'ADMIN',
                'username'             => 'admin',
                'email'                => 'admin@tmn.id',
                'kata_sandi'           => Hash::make('Password123!'),
                'aktif'                => 1,
                'harus_ganti_password' => 0,
                'dibuat_pada'          => now(),
                'dibuat_oleh'          => null,
            ],
        ];

        foreach ($users as $user) {
            DB::table('pengguna')->insertOrIgnore($user);
        }
    }
}
