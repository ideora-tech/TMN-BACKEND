<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permintaan_vendor', function (Blueprint $table) {
            if (!Schema::hasColumn('permintaan_vendor', 'disetujui_pada')) {
                $table->dateTime('disetujui_pada')->nullable()->after('alasan_batal');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'dikontrakkan_pada')) {
                $table->dateTime('dikontrakkan_pada')->nullable()->after('disetujui_pada');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'alasan_tolak_pengadaan')) {
                $table->text('alasan_tolak_pengadaan')->nullable()->after('dikontrakkan_pada');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'ditolak_pengadaan_oleh')) {
                $table->char('ditolak_pengadaan_oleh', 36)->nullable()->after('alasan_tolak_pengadaan');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'ditolak_pengadaan_pada')) {
                $table->dateTime('ditolak_pengadaan_pada')->nullable()->after('ditolak_pengadaan_oleh');
            }
        });

        DB::table('permintaan_vendor')
            ->where('status', 'disetujui')
            ->whereNull('disetujui_pada')
            ->update(['disetujui_pada' => DB::raw('diubah_pada')]);
    }

    public function down(): void
    {
        Schema::table('permintaan_vendor', function (Blueprint $table) {
            foreach (['ditolak_pengadaan_pada', 'ditolak_pengadaan_oleh', 'alasan_tolak_pengadaan', 'dikontrakkan_pada', 'disetujui_pada'] as $kolom) {
                if (Schema::hasColumn('permintaan_vendor', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
