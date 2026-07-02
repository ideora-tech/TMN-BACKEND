<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ZonaWaktuSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['kode_zona' => 'WIB',  'nama_zona' => 'Waktu Indonesia Barat',  'offset_utc' => '+07:00', 'urutan' => 1],
            ['kode_zona' => 'WITA', 'nama_zona' => 'Waktu Indonesia Tengah', 'offset_utc' => '+08:00', 'urutan' => 2],
            ['kode_zona' => 'WIT',  'nama_zona' => 'Waktu Indonesia Timur',  'offset_utc' => '+09:00', 'urutan' => 3],
        ];

        foreach ($zones as $zone) {
            DB::table('zona_waktu')->insertOrIgnore(
                array_merge(['id_zona' => (string) Str::uuid()], $zone)
            );
        }
    }
}
