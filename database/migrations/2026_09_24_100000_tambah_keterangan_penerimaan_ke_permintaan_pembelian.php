<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'keterangan_penerimaan')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->text('keterangan_penerimaan')->nullable()->after('tanggal_diterima');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permintaan_pembelian', 'keterangan_penerimaan')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropColumn('keterangan_penerimaan');
            });
        }
    }
};
