<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('karyawan_exit', function (Blueprint $table) {
            $table->char('id_exit', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36);
            $table->string('jenis_exit', 50);
            $table->date('tanggal_efektif');
            $table->text('alasan')->nullable();
            $table->tinyInteger('dapat_direkrut_kembali')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan_exit');
    }
};
