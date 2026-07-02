<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('izin_peran', function (Blueprint $table) {
            $table->char('id_izin', 36)->primary();
            $table->char('id_perusahaan', 36)->nullable();
            $table->string('kode_peran', 50);
            $table->char('id_menu', 36);
            $table->string('aksi', 50);
            $table->tinyInteger('diizinkan')->default(0);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('izin_peran');
    }
};
