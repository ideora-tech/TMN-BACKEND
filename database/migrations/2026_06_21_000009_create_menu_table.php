<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('menu', function (Blueprint $table) {
            $table->char('id_menu', 36)->primary();
            $table->string('nama_menu', 100);
            $table->string('path', 200)->nullable();
            $table->char('id_menu_induk', 36)->nullable();
            $table->integer('urutan')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu');
    }
};
