<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $maks = DB::table('dokumen_karyawan')
            ->whereRaw("UPPER(jenis_dokumen) = 'SIM'")
            ->whereNull('dihapus_pada')
            ->whereNotNull('berlaku_sampai')
            ->groupBy('id_karyawan')
            ->selectRaw('id_karyawan, MAX(berlaku_sampai) as maks')
            ->pluck('maks', 'id_karyawan');

        foreach ($maks as $idKaryawan => $tanggal) {
            DB::table('supir')
                ->where('id_karyawan', $idKaryawan)
                ->whereNull('dihapus_pada')
                ->update(['tgl_kadaluarsa_sim' => $tanggal, 'diubah_pada' => now()]);
        }
    }

    public function down(): void
    {
    }
};
