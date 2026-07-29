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
        Schema::create('riwayat_jabatan', function (Blueprint $table) {
            $table->char('id_riwayat', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36);
            $table->char('id_jabatan_lama', 36)->nullable();
            $table->char('id_jabatan_baru', 36)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_karyawan', 'riwayat_jabatan_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_jabatan');
    }
};
