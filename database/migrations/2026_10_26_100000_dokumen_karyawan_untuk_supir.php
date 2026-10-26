<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('dokumen_karyawan', 'id_supir')) {
            Schema::table('dokumen_karyawan', function (Blueprint $table) {
                $table->char('id_supir', 36)->nullable()->after('id_karyawan');
                $table->index('id_supir', 'dokumen_karyawan_supir_idx');
            });
        }

        Schema::table('dokumen_karyawan', function (Blueprint $table) {
            $table->char('id_karyawan', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('dokumen_karyawan')->whereNull('id_karyawan')->delete();

        Schema::table('dokumen_karyawan', function (Blueprint $table) {
            $table->char('id_karyawan', 36)->nullable(false)->change();
        });

        if (Schema::hasColumn('dokumen_karyawan', 'id_supir')) {
            Schema::table('dokumen_karyawan', function (Blueprint $table) {
                $table->dropIndex('dokumen_karyawan_supir_idx');
                $table->dropColumn('id_supir');
            });
        }
    }
};
