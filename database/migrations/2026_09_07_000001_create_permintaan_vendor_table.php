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
        Schema::create('permintaan_vendor', function (Blueprint $table) {
            $table->char('id_permintaan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nomor_permintaan', 100);
            $table->char('id_proyek', 36)->nullable();
            $table->char('id_jenis_kendaraan', 36)->nullable();
            $table->unsignedSmallInteger('jumlah_unit')->default(1);
            $table->enum('mekanisme', ['unit_only', 'unit_driver', 'full']);
            $table->date('periode_dari')->nullable();
            $table->date('periode_sampai')->nullable();
            $table->text('catatan')->nullable();
            $table->string('status', 50)->default('draft');
            $table->text('alasan_ditolak')->nullable();
            $table->char('id_kontrak_vendor', 36)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_perusahaan');
            $table->index('id_proyek');
            $table->index('id_kontrak_vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_vendor');
    }
};
