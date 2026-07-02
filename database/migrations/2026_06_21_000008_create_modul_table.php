<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('modul', function (Blueprint $table) {
            $table->char('id_modul', 36)->primary();
            $table->string('kode_modul', 50)->unique();
            $table->string('nama_modul', 100);
            $table->integer('urutan')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modul');
    }
};
