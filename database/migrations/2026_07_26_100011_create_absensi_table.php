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
        Schema::create('absensi', function (Blueprint $table) {
            $table->char('id_absensi', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36);
            $table->date('tanggal');
            $table->string('status', 20); // hadir, terlambat, izin, sakit, alpha
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_karyawan', 'tanggal'], 'absensi_karyawan_tanggal_idx');
            $table->index(['id_perusahaan', 'tanggal'], 'absensi_perusahaan_tanggal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
    }
};
