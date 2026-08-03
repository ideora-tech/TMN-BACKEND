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
        Schema::create('alokasi_armada', function (Blueprint $table) {
            $table->char('id_alokasi', 36)->primary();
            $table->date('tanggal');
            $table->char('id_proyek', 36);
            $table->char('id_supir', 36)->index();
            $table->char('id_armada', 36)->nullable()->index();
            $table->char('id_pemilik_asal', 36)->nullable();
            $table->string('sumber', 20)->default('otomatis');
            $table->string('keterangan', 255)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['tanggal', 'id_supir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alokasi_armada');
    }
};
