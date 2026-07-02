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
        Schema::create('jenis_kendaraan', function (Blueprint $table) {
            $table->char('id_jenis_kendaraan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode_jenis', 50);
            $table->string('nama_jenis', 150);
            $table->decimal('kapasitas_muatan', 10, 2)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->unique(['id_perusahaan', 'kode_jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_kendaraan');
    }
};
