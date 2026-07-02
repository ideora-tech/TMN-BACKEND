<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaketLanggananSeeder extends Seeder
{
    public function run(): void
    {
        $packets = [
            ['kode_paket' => 'BASIC',      'nama' => 'Basic',      'maks_karyawan' => 10,  'harga' => 0,       'aktif' => 1],
            ['kode_paket' => 'STANDARD',   'nama' => 'Standard',   'maks_karyawan' => 50,  'harga' => 500000,  'aktif' => 1],
            ['kode_paket' => 'PREMIUM',    'nama' => 'Premium',    'maks_karyawan' => 200, 'harga' => 1500000, 'aktif' => 1],
            ['kode_paket' => 'ENTERPRISE', 'nama' => 'Enterprise', 'maks_karyawan' => 0,   'harga' => 5000000, 'aktif' => 1],
        ];

        foreach ($packets as $packet) {
            DB::table('paket_langganan')->insertOrIgnore(
                array_merge(['id_paket' => (string) Str::uuid()], $packet)
            );
        }
    }
}
