<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pengguna', function (Blueprint $table) {
            $table->char('id_pengguna', 36)->primary();
            $table->char('id_perusahaan', 36)->nullable();
            $table->char('id_karyawan', 36)->nullable();
            $table->string('username', 100)->unique();
            $table->string('email', 150)->unique();
            $table->string('kata_sandi', 255);
            $table->tinyInteger('aktif')->default(1);
            $table->tinyInteger('harus_ganti_password')->default(0);
            $table->dateTime('login_terakhir')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengguna');
    }
};
