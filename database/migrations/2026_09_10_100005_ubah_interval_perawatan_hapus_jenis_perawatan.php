<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konsep Jenis Perawatan dihilangkan dari Interval Perawatan — interval jadi
     * paket servis rutin per jenis kendaraan (km + bulan), bukan lagi per jenis
     * perawatan. interval_bulan menggantikan interval_hari sebagai basis waktu;
     * id_jenis_perawatan dibiarkan ada (nullable, tidak dipakai lagi) supaya data
     * lama tidak hilang. Dedup kombinasi + migrasi sparepart dikerjakan di
     * migration terpisah (butuh tabel interval_perawatan_sparepart lebih dulu).
     */
    public function up(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_bulan')->nullable()->after('interval_km');
            $table->char('id_jenis_perawatan', 36)->nullable()->change();
        });

        DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->whereNotNull('interval_hari')
            ->orderBy('id_interval_perawatan')
            ->each(function (object $row) {
                $bulan = max(1, (int) round(((int) $row->interval_hari) / 30));
                DB::table('interval_perawatan')
                    ->where('id_interval_perawatan', $row->id_interval_perawatan)
                    ->update(['interval_bulan' => $bulan]);
            });
    }

    public function down(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->dropColumn('interval_bulan');
        });
    }
};
