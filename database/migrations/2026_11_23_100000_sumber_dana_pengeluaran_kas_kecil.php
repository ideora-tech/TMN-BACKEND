<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan sumber dana: Arus Kas = kas bank (rekening). Sejak migrasi ini, pengajuan
 * biaya & pembayaran vendor dibayar dari Kas Kecil. Data lama tetap 'rekening'
 * (default kolom) sehingga histori saldo bank tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pengajuan_pengeluaran', 'sumber_dana')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->string('sumber_dana', 20)->default('rekening')->after('status');
            });
        }

        if (!Schema::hasColumn('pembayaran_vendor', 'sumber_dana')) {
            Schema::table('pembayaran_vendor', function (Blueprint $table) {
                $table->string('sumber_dana', 20)->default('rekening')->after('metode');
            });
        }

        Schema::table('kas_kecil_transaksi', function (Blueprint $table) {
            if (!Schema::hasColumn('kas_kecil_transaksi', 'id_pengajuan')) {
                $table->char('id_pengajuan', 36)->nullable()->unique()->after('id_pengisian');
            }
            if (!Schema::hasColumn('kas_kecil_transaksi', 'id_pembayaran_vendor')) {
                $table->char('id_pembayaran_vendor', 36)->nullable()->unique()->after('id_pengajuan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kas_kecil_transaksi', function (Blueprint $table) {
            if (Schema::hasColumn('kas_kecil_transaksi', 'id_pembayaran_vendor')) {
                $table->dropUnique(['id_pembayaran_vendor']);
                $table->dropColumn('id_pembayaran_vendor');
            }
            if (Schema::hasColumn('kas_kecil_transaksi', 'id_pengajuan')) {
                $table->dropUnique(['id_pengajuan']);
                $table->dropColumn('id_pengajuan');
            }
        });

        if (Schema::hasColumn('pembayaran_vendor', 'sumber_dana')) {
            Schema::table('pembayaran_vendor', fn (Blueprint $table) => $table->dropColumn('sumber_dana'));
        }
        if (Schema::hasColumn('pengajuan_pengeluaran', 'sumber_dana')) {
            Schema::table('pengajuan_pengeluaran', fn (Blueprint $table) => $table->dropColumn('sumber_dana'));
        }
    }
};
