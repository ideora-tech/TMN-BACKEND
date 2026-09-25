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
            $table->char('id_permintaan_pembelian', 36)->nullable()->index()->after('id_invoice_vendor');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->dropColumn('id_permintaan_pembelian');
        });
    }
};
