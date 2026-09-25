<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'tipe')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->string('tipe', 20)->default('umum')->after('id_departemen');
                $table->index(['id_perusahaan', 'tipe'], 'permintaan_pembelian_id_perusahaan_tipe_index');
            });
        }

        if (!Schema::hasColumn('permintaan_pembelian_item', 'id_sparepart')) {
            Schema::table('permintaan_pembelian_item', function (Blueprint $table) {
                $table->char('id_sparepart', 36)->nullable()->index()->after('id_barang');
            });
        }

        if (!Schema::hasColumn('pembelian_sparepart', 'id_permintaan_pembelian')) {
            Schema::table('pembelian_sparepart', function (Blueprint $table) {
                $table->char('id_permintaan_pembelian', 36)->nullable()->index()->after('id_perawatan');
            });
        }

        if (!Schema::hasColumn('pembelian_sparepart_item', 'id_item_permintaan')) {
            Schema::table('pembelian_sparepart_item', function (Blueprint $table) {
                $table->char('id_item_permintaan', 36)->nullable()->after('id_sparepart');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pembelian_sparepart_item', 'id_item_permintaan')) {
            Schema::table('pembelian_sparepart_item', function (Blueprint $table) {
                $table->dropColumn('id_item_permintaan');
            });
        }

        if (Schema::hasColumn('pembelian_sparepart', 'id_permintaan_pembelian')) {
            Schema::table('pembelian_sparepart', function (Blueprint $table) {
                $table->dropIndex(['id_permintaan_pembelian']);
                $table->dropColumn('id_permintaan_pembelian');
            });
        }

        if (Schema::hasColumn('permintaan_pembelian_item', 'id_sparepart')) {
            Schema::table('permintaan_pembelian_item', function (Blueprint $table) {
                $table->dropIndex(['id_sparepart']);
                $table->dropColumn('id_sparepart');
            });
        }

        if (Schema::hasColumn('permintaan_pembelian', 'tipe')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropIndex('permintaan_pembelian_id_perusahaan_tipe_index');
                $table->dropColumn('tipe');
            });
        }
    }
};
