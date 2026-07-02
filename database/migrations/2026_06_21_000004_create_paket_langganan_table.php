<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paket_langganan', function (Blueprint $table) {
            $table->char('id_paket', 36)->primary();
            $table->string('kode_paket', 50)->unique();
            $table->string('nama', 100);
            $table->integer('maks_karyawan')->default(0);
            $table->decimal('harga', 15, 2)->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_langganan');
    }
};
