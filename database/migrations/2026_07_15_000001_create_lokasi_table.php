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
        Schema::create('lokasi', function (Blueprint $table) {
            $table->char('id_lokasi', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama_lokasi', 150);
            $table->text('alamat')->nullable();
            $table->string('kota', 100)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lokasi');
    }
};
