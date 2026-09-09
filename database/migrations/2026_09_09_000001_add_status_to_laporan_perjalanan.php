<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laporan baru dari supir lahir 'draft' (masih bisa diedit walau trip
     * sudah selesai) dan terkunci setelah supir menekan "Selesaikan Laporan".
     * Laporan lama di-backfill 'final' agar perilaku kunci lamanya tidak
     * berubah mundur.
     */
    public function up(): void
    {
        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('no_surat_jalan');
        });

        DB::table('laporan_perjalanan')->update(['status' => 'final']);
    }

    public function down(): void
    {
        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
