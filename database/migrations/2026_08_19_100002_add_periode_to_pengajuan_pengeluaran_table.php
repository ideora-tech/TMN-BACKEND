<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->char('id_supir', 36)->nullable()->after('id_periode');
            $table->char('id_proyek', 36)->nullable()->after('id_supir');
            $table->date('periode_dari')->nullable()->after('id_proyek');
            $table->date('periode_sampai')->nullable()->after('periode_dari');
            $table->decimal('tarif_per_hari', 15, 2)->nullable()->after('periode_sampai');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->dropColumn(['id_supir', 'id_proyek', 'periode_dari', 'periode_sampai', 'tarif_per_hari']);
        });
    }
};
