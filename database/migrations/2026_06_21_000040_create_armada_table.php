<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armada', function (Blueprint $table) {
            $table->char('id_armada', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jenis_kendaraan', 36)->nullable();
            $table->char('id_vendor', 36)->nullable();
            $table->string('nopol', 20)->unique();
            $table->string('merk', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->smallInteger('tahun')->nullable();
            $table->enum('kepemilikan', ['internal', 'vendor'])->default('internal');
            $table->string('status', 50)->default('tersedia'); // tersedia, digunakan, perawatan, tidak_aktif
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armada');
    }
};
