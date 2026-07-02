<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('peran', function (Blueprint $table) {
            $table->char('id_peran', 36)->primary();
            $table->char('id_perusahaan', 36)->nullable();
            $table->string('kode_peran', 50);
            $table->string('nama_peran', 100);
            $table->tinyInteger('is_platform')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peran');
    }
};
