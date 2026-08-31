<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->char('id_supir', 36)->nullable()->change();
        });

        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->char('id_supir_vendor', 36)->nullable()->after('id_supir');
            $table->index(['id_supir_vendor', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->dropIndex(['id_supir_vendor', 'tanggal']);
            $table->dropColumn('id_supir_vendor');
        });

        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->char('id_supir', 36)->nullable(false)->change();
        });
    }
};
