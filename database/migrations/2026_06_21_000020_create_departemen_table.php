<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departemen', function (Blueprint $table) {
            $table->char('id_departemen', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_departemen_induk', 36)->nullable();
            $table->string('kode_departemen', 50);
            $table->string('nama_departemen', 150);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departemen');
    }
};
