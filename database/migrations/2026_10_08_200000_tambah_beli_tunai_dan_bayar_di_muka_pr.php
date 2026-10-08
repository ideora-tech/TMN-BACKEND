<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KOLOM = ['metode_pembelian', 'nama_toko', 'nama_penalang', 'syarat_pembayaran', 'kelebihan_bayar'];

    public function up(): void
    {
        Schema::table('permintaan_pembelian', function (Blueprint $table) {
            if (!Schema::hasColumn('permintaan_pembelian', 'metode_pembelian')) {
                $table->string('metode_pembelian', 10)->nullable()->after('id_supplier');
            }
            if (!Schema::hasColumn('permintaan_pembelian', 'nama_toko')) {
                $table->string('nama_toko', 150)->nullable()->after('metode_pembelian');
            }
            if (!Schema::hasColumn('permintaan_pembelian', 'nama_penalang')) {
                $table->string('nama_penalang', 150)->nullable()->after('nama_toko');
            }
            if (!Schema::hasColumn('permintaan_pembelian', 'syarat_pembayaran')) {
                $table->string('syarat_pembayaran', 20)->default('setelah_terima')->after('nama_penalang');
            }
            if (!Schema::hasColumn('permintaan_pembelian', 'kelebihan_bayar')) {
                $table->decimal('kelebihan_bayar', 15, 2)->nullable()->after('total_aktual');
            }
        });
    }

    public function down(): void
    {
        foreach (self::KOLOM as $kolom) {
            if (Schema::hasColumn('permintaan_pembelian', $kolom)) {
                Schema::table('permintaan_pembelian', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }
};
