<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('karyawan_lokasi_kantor', function (Blueprint $table) {
            $table->char('id_karyawan', 36);
            $table->char('id_lokasi', 36);
            $table->primary(['id_karyawan', 'id_lokasi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan_lokasi_kantor');
    }
};
