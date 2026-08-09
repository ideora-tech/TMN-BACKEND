<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sparepart_mutasi', function (Blueprint $table) {
            $table->char('id_pembelian', 36)->nullable()->index()->after('id_perawatan');
        });

        $concat = DB::connection()->getDriverName() === 'sqlite'
            ? "'Pembelian ' || p.nomor_pengajuan"
            : "CONCAT('Pembelian ', p.nomor_pengajuan)";
        DB::statement("
            UPDATE sparepart_mutasi SET id_pembelian = (
                SELECT p.id_pembelian
                FROM pembelian_sparepart p
                JOIN sparepart s ON s.id_sparepart = sparepart_mutasi.id_sparepart
                WHERE p.id_perusahaan = s.id_perusahaan
                  AND sparepart_mutasi.keterangan = {$concat}
                LIMIT 1
            )
            WHERE jenis = 'masuk' AND id_pembelian IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('sparepart_mutasi', function (Blueprint $table) {
            $table->dropColumn('id_pembelian');
        });
    }
};
