<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('perusahaan', function (Blueprint $table) {
            $table->char('id_perusahaan', 36)->primary();
            $table->string('nama', 200);
            $table->string('email', 150)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('alamat', 500)->nullable();
            $table->char('id_zona', 36)->nullable();
            $table->char('id_mata_uang', 36)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perusahaan');
    }
};
