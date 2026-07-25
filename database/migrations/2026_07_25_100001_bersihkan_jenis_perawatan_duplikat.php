<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data cleanup: "GANTI AKI" (duplikat "Cek & Ganti Aki") dan "SERVICE" (nama generik,
// tanpa interval_perawatan) dibuat manual sebelum PerawatanMasterDataSeeder ada.
// Catatan perawatan yang masih pakai "SERVICE" dialihkan dulu ke "Cek & Ganti Aki"
// (per perusahaan) sebelum kedua jenis lama ini di-soft-delete, mengikuti guard
// JenisPerawatanService::delete() yang menolak hapus jenis yang masih dipakai.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $serviceRows = DB::table('jenis_perawatan')
            ->where('nama', 'SERVICE')
            ->whereNull('dihapus_pada')
            ->get(['id_jenis_perawatan', 'id_perusahaan']);

        foreach ($serviceRows as $service) {
            $target = DB::table('jenis_perawatan')
                ->where('id_perusahaan', $service->id_perusahaan)
                ->where('nama', 'Cek & Ganti Aki')
                ->whereNull('dihapus_pada')
                ->value('id_jenis_perawatan');

            if ($target === null) {
                continue;
            }

            DB::table('perawatan_armada')
                ->where('id_jenis_perawatan', $service->id_jenis_perawatan)
                ->whereNull('dihapus_pada')
                ->update(['id_jenis_perawatan' => $target, 'diubah_pada' => $now]);
        }

        $idsToDelete = DB::table('jenis_perawatan')
            ->whereIn('nama', ['GANTI AKI', 'SERVICE'])
            ->whereNull('dihapus_pada')
            ->pluck('id_jenis_perawatan');

        DB::table('jenis_perawatan')
            ->whereIn('id_jenis_perawatan', $idsToDelete)
            ->update(['dihapus_pada' => $now]);
    }

    public function down(): void
    {
        // no-op: reassignment id_jenis_perawatan pada perawatan_armada dan soft-delete
        // jenis_perawatan tidak bisa direkonstruksi otomatis (data asal tidak disimpan).
    }
};
