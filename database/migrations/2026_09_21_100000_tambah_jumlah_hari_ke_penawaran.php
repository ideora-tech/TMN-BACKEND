<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('penawaran', 'jumlah_hari')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->unsignedInteger('jumlah_hari')->nullable()->after('tanggal_berlaku');
            });
        }

        if (!Schema::hasColumn('penawaran_item', 'jumlah_hari')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->unsignedInteger('jumlah_hari')->nullable()->after('estimasi_ritase');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('penawaran_item', 'jumlah_hari')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->dropColumn('jumlah_hari');
            });
        }

        if (Schema::hasColumn('penawaran', 'jumlah_hari')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dropColumn('jumlah_hari');
            });
        }
    }
};
