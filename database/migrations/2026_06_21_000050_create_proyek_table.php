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
        Schema::create('proyek', function (Blueprint $table) {
            $table->char('id_proyek', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_klien', 36);
            $table->string('kode_proyek', 50)->unique();
            $table->string('nama_proyek', 200);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->string('status', 50)->default('draft'); // draft, aktif, selesai, batal
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyek');
    }
};
