<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'nomor_po')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->string('nomor_po', 50)->nullable()->after('tanggal_pembelian');
                $table->decimal('diskon', 15, 2)->default(0)->after('total_aktual');
                $table->decimal('ppn_persen', 5, 2)->default(0)->after('diskon');
                $table->decimal('ppn', 15, 2)->default(0)->after('ppn_persen');
                $table->decimal('ongkir', 15, 2)->default(0)->after('ppn');
                $table->index(['id_perusahaan', 'nomor_po']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permintaan_pembelian', 'nomor_po')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropIndex(['id_perusahaan', 'nomor_po']);
                $table->dropColumn(['nomor_po', 'diskon', 'ppn_persen', 'ppn', 'ongkir']);
            });
        }
    }
};
