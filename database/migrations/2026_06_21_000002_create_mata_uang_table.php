<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mata_uang', function (Blueprint $table) {
            $table->char('id_mata_uang', 36)->primary();
            $table->string('kode_mata_uang', 10)->unique();
            $table->string('nama_mata_uang', 100);
            $table->string('simbol', 10);
            $table->integer('urutan')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mata_uang');
    }
};
