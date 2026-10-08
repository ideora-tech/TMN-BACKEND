<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'tanggal_po')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->date('tanggal_po')->nullable()->after('nomor_po');
                $table->char('dipesan_oleh', 36)->nullable()->after('tanggal_po');
                $table->dateTime('dipesan_pada')->nullable()->after('dipesan_oleh');
            });
        }

        if (!Schema::hasColumn('pembelian_sparepart', 'diskon')) {
            Schema::table('pembelian_sparepart', function (Blueprint $table) {
                $table->decimal('diskon', 15, 2)->default(0)->after('total_aktual');
                $table->decimal('ppn_persen', 5, 2)->default(0)->after('diskon');
                $table->decimal('ppn', 15, 2)->default(0)->after('ppn_persen');
                $table->decimal('ongkir', 15, 2)->default(0)->after('ppn');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permintaan_pembelian', 'tanggal_po')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropColumn(['tanggal_po', 'dipesan_oleh', 'dipesan_pada']);
            });
        }

        if (Schema::hasColumn('pembelian_sparepart', 'diskon')) {
            Schema::table('pembelian_sparepart', function (Blueprint $table) {
                $table->dropColumn(['diskon', 'ppn_persen', 'ppn', 'ongkir']);
            });
        }
    }
};
