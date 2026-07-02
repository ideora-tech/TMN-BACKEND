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
        Schema::create('penugasan', function (Blueprint $table) {
            $table->char('id_penugasan', 36)->primary();
            $table->char('id_proyek', 36);
            $table->char('id_armada', 36)->nullable();
            $table->char('id_karyawan', 36)->nullable(); // supir
            $table->date('tanggal_tugas')->nullable();
            $table->string('status', 50)->default('pending'); // pending, aktif, selesai, batal
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penugasan');
    }
};
