<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PerusahaanSeeder extends Seeder
{
    public function run(): void
    {
        $idZona     = DB::table('zona_waktu')->where('kode_zona', 'WIB')->value('id_zona');
        $idMataUang = DB::table('mata_uang')->where('kode_mata_uang', 'IDR')->value('id_mata_uang');

        DB::table('perusahaan')->insertOrIgnore([
            'id_perusahaan' => 'b8f3c1a2-0000-4000-8000-000000000001',
            'nama'          => 'TMN Transport Demo',
            'email'         => 'demo@tmntransport.id',
            'telepon'       => '021-12345678',
            'alamat'        => 'Jl. Transport No. 1, Jakarta',
            'id_zona'       => $idZona,
            'id_mata_uang'  => $idMataUang,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
            'dibuat_oleh'   => null,
        ]);
    }
}
