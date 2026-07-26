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
        Schema::create('kontrak_karyawan', function (Blueprint $table) {
            $table->char('id_kontrak', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36);
            $table->string('jenis_kontrak', 20); // pkwt, pkwtt, harian, magang, probation
            $table->string('nomor_kontrak', 100)->nullable();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai')->nullable(); // pkwtt tanpa tanggal selesai
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_karyawan', 'kontrak_karyawan_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kontrak_karyawan');
    }
};
