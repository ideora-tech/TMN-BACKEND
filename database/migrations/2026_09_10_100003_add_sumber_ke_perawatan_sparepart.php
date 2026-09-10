<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perusahaan tidak punya gudang sendiri — mayoritas sparepart disediakan
     * BENGKEL (tidak memotong stok), sebagian kecil dari stok sendiri (jalur
     * lama dipertahankan). Semua baris EXISTING (termasuk yang sudah dihapus)
     * di-backfill 'stok_sendiri' karena data lama memang sudah memotong stok —
     * jangan sampai baris lama disangka 'bengkel' lalu koreksi stok jadi salah.
     */
    public function up(): void
    {
        Schema::table('perawatan_sparepart', function (Blueprint $table) {
            $table->string('sumber', 20)->default('bengkel')->after('id_sparepart');
        });

        DB::table('perawatan_sparepart')->update(['sumber' => 'stok_sendiri']);

        Schema::table('perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_sparepart', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_sparepart', function (Blueprint $table) {
            $table->dropColumn('sumber');
        });
    }
};
