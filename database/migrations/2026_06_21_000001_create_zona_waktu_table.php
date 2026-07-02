<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zona_waktu', function (Blueprint $table) {
            $table->char('id_zona', 36)->primary();
            $table->string('kode_zona', 50)->unique();
            $table->string('nama_zona', 100);
            $table->string('offset_utc', 10);
            $table->integer('urutan')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zona_waktu');
    }
};
