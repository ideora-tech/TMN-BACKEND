<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jabatan', function (Blueprint $table) {
            $table->char('id_jabatan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_departemen', 36)->nullable();
            $table->char('id_peran', 36)->nullable();
            $table->string('kode_jabatan', 50);
            $table->string('nama_jabatan', 150);
            $table->integer('level')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jabatan');
    }
};
