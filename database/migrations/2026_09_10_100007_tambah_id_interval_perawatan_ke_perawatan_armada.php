<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan Perawatan bertaut ke paket (id_interval_perawatan), bukan lagi ke
     * Jenis Perawatan. Kolom id_jenis_perawatan & jenis_perawatan DIBIARKAN ada
     * (nullable) untuk data lama — modul & data lama tidak diutak-atik, hanya
     * tidak dipakai lagi oleh alur baru ini. Tabel perawatan_jenis (multi-jenis)
     * juga dibiarkan ada tapi tidak dipakai lagi.
     */
    public function up(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->char('id_interval_perawatan', 36)->nullable()->after('id_jenis_perawatan');
            $table->index('id_interval_perawatan', 'perawatan_armada_id_interval_perawatan_idx');
            $table->string('jenis_perawatan', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropIndex('perawatan_armada_id_interval_perawatan_idx');
            $table->dropColumn('id_interval_perawatan');
        });
    }
};
