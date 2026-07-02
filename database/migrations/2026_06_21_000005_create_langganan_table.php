<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('langganan', function (Blueprint $table) {
            $table->char('id_langganan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode_paket', 50);
            $table->integer('maks_karyawan')->default(0);
            $table->dateTime('mulai_pada')->nullable();
            $table->dateTime('kedaluwarsa_pada')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('langganan');
    }
};
