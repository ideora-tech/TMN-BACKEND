<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'id_perawatan')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->char('id_perawatan', 36)->nullable()->index()->after('id_supplier');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permintaan_pembelian', 'id_perawatan')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropIndex(['id_perawatan']);
                $table->dropColumn('id_perawatan');
            });
        }
    }
};
