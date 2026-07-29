<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->string('nomor_kontrak', 100)->nullable()->after('id_proyek');
            $table->string('jenis_layanan', 150)->nullable()->after('mekanisme');
            $table->decimal('rate', 15, 2)->nullable()->after('nilai_kontrak');
            $table->string('satuan', 50)->nullable()->after('rate');
            $table->decimal('pajak_persen', 5, 2)->nullable()->after('satuan');
            $table->smallInteger('termin_pembayaran_hari')->nullable()->after('pajak_persen');
        });
    }

    public function down(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->dropColumn([
                'nomor_kontrak', 'jenis_layanan', 'rate', 'satuan',
                'pajak_persen', 'termin_pembayaran_hari',
            ]);
        });
    }
};
