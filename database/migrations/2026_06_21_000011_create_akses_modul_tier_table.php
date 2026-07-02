<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('akses_modul_tier', function (Blueprint $table) {
            $table->char('id_akses_modul', 36)->primary();
            $table->string('kode_modul', 50);
            $table->string('kode_paket', 50);
            $table->json('batasan')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akses_modul_tier');
    }
};
