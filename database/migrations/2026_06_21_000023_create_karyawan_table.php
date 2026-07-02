<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('karyawan', function (Blueprint $table) {
            $table->char('id_karyawan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jabatan', 36)->nullable();
            $table->char('id_lokasi', 36)->nullable();
            $table->string('nik', 50)->unique();
            $table->string('nama_karyawan', 200);
            $table->string('email', 150)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('jenis_kelamin', 10)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->date('tanggal_masuk')->nullable();
            $table->string('status_kepegawaian', 50)->default('tetap');
            $table->decimal('gaji_pokok', 15, 2)->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan');
    }
};
