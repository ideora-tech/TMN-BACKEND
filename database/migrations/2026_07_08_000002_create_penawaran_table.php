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
        Schema::create('penawaran', function (Blueprint $table) {
            $table->char('id_penawaran', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_klien', 36)->nullable();
            $table->string('nomor_penawaran', 50);
            $table->string('judul', 200);
            $table->decimal('nilai_penawaran', 15, 2)->nullable();
            $table->enum('status', ['draft', 'terkirim', 'negosiasi', 'disetujui', 'ditolak'])->default('draft');
            $table->date('tanggal_penawaran')->nullable();
            $table->date('tanggal_berlaku')->nullable();
            $table->text('catatan')->nullable();
            $table->char('id_proyek', 36)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->unique(['id_perusahaan', 'nomor_penawaran'], 'penawaran_nomor_perusahaan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penawaran');
    }
};