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
        Schema::create('pengaturan_absensi', function (Blueprint $table) {
            $table->char('id_pengaturan', 36)->primary();
            $table->char('id_perusahaan', 36)->unique();
            $table->time('jam_masuk')->default('08:00');
            $table->time('jam_pulang')->default('17:00');
            $table->integer('toleransi_terlambat_menit')->default(15);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_absensi');
    }
};
