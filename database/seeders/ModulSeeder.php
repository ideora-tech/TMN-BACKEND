<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModulSeeder extends Seeder
{
    public function run(): void
    {
        $moduls = [
            ['kode_modul' => 'AUTH',        'nama_modul' => 'Authentication',  'urutan' => 1, 'aktif' => 1],
            ['kode_modul' => 'PERUSAHAAN',  'nama_modul' => 'Perusahaan',      'urutan' => 2, 'aktif' => 1],
            ['kode_modul' => 'PENGGUNA',    'nama_modul' => 'Pengguna',        'urutan' => 3, 'aktif' => 1],
            ['kode_modul' => 'PERAN',       'nama_modul' => 'Peran & Izin',    'urutan' => 4, 'aktif' => 1],
            ['kode_modul' => 'HR',          'nama_modul' => 'Human Resources', 'urutan' => 5, 'aktif' => 1],
            ['kode_modul' => 'ARMADA',      'nama_modul' => 'Armada',          'urutan' => 6, 'aktif' => 1],
            ['kode_modul' => 'OPERASIONAL', 'nama_modul' => 'Operasional',     'urutan' => 7, 'aktif' => 1],
            ['kode_modul' => 'KEUANGAN',    'nama_modul' => 'Keuangan',        'urutan' => 8, 'aktif' => 1],
            ['kode_modul' => 'LAPORAN',     'nama_modul' => 'Laporan',         'urutan' => 9, 'aktif' => 1],
        ];

        foreach ($moduls as $modul) {
            DB::table('modul')->insertOrIgnore(
                array_merge(['id_modul' => (string) Str::uuid()], $modul)
            );
        }
    }
}
