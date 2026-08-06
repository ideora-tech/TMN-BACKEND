<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Buatkan record karyawan untuk setiap supir yang belum tertaut
     * (supir.id_karyawan NULL) lalu tautkan. NIK dibuat deterministik dari
     * id_supir sehingga migration ini idempoten — aman dijalankan berulang
     * di environment manapun tanpa membuat duplikat.
     */
    public function up(): void
    {
        $supirs = DB::table('supir')
            ->whereNull('dihapus_pada')
            ->whereNull('id_karyawan')
            ->get();

        foreach ($supirs as $supir) {
            // NIK dari UUID id_supir PENUH — banyak supir di data seed/import
            // berbagi prefix UUID yang sama, jadi cuma ambil 8 karakter awal
            // akan bentrok dan bikin banyak supir numpang ke 1 karyawan.
            $nik = 'SPR-' . strtoupper(str_replace('-', '', $supir->id_supir));

            $idKaryawan = DB::table('karyawan')->where('nik', $nik)->value('id_karyawan');

            if ($idKaryawan === null) {
                $idKaryawan = (string) Str::uuid();
                DB::table('karyawan')->insert([
                    'id_karyawan'   => $idKaryawan,
                    'id_perusahaan' => $supir->id_perusahaan,
                    'nik'           => $nik,
                    'nama_karyawan' => $supir->nama,
                    'telepon'       => $supir->telepon,
                    'aktif'         => $supir->status === 'aktif' ? 1 : 0,
                    'dibuat_pada'   => now(),
                ]);
            }

            DB::table('supir')->where('id_supir', $supir->id_supir)->update([
                'id_karyawan' => $idKaryawan,
                'diubah_pada' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('karyawan')->where('nik', 'like', 'SPR-%')->pluck('id_karyawan');
        DB::table('supir')->whereIn('id_karyawan', $ids)->update(['id_karyawan' => null]);
        DB::table('karyawan')->whereIn('id_karyawan', $ids)->delete();
    }
};
