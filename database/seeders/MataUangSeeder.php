<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MataUangSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['kode_mata_uang' => 'IDR', 'nama_mata_uang' => 'Rupiah Indonesia', 'simbol' => 'Rp', 'urutan' => 1],
            ['kode_mata_uang' => 'USD', 'nama_mata_uang' => 'US Dollar',        'simbol' => '$',  'urutan' => 2],
        ];

        foreach ($currencies as $currency) {
            DB::table('mata_uang')->insertOrIgnore(
                array_merge(['id_mata_uang' => (string) Str::uuid()], $currency)
            );
        }
    }
}
