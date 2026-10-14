<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('proyek_rute', 'fee_supir')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('fee_supir', 15, 2)->nullable()->after('uang_jalan');
            });
        }

        if (!Schema::hasColumn('penugasan', 'fee_supir')) {
            Schema::table('penugasan', function (Blueprint $table) {
                $table->decimal('fee_supir', 15, 2)->nullable()->after('estimasi_biaya');
            });
        }

        if (!Schema::hasTable('fee_supir_trip')) {
            Schema::create('fee_supir_trip', function (Blueprint $table) {
                $table->char('id_fee_supir_trip', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->char('id_trip', 36);
                $table->char('id_penugasan', 36);
                $table->char('id_proyek', 36);
                $table->char('id_rute', 36)->nullable();
                $table->char('id_supir', 36);
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                MigrationHelper::auditColumns($table);

                $table->unique('id_trip', 'uniq_fee_supir_trip_trip');
                $table->index(['id_supir', 'tanggal'], 'idx_fee_supir_trip_supir_tanggal');
                $table->index('id_penugasan', 'idx_fee_supir_trip_penugasan');
                $table->index('id_perusahaan', 'idx_fee_supir_trip_perusahaan');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_supir_trip');

        if (Schema::hasColumn('penugasan', 'fee_supir')) {
            Schema::table('penugasan', function (Blueprint $table) {
                $table->dropColumn('fee_supir');
            });
        }

        if (Schema::hasColumn('proyek_rute', 'fee_supir')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->dropColumn('fee_supir');
            });
        }
    }
};
