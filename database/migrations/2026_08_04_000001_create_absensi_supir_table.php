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
        Schema::create('absensi_supir', function (Blueprint $table) {
            $table->char('id_absensi', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->char('id_supir', 36);
            $table->date('tanggal');
            $table->string('status', 20)->default('hadir');
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_supir', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi_supir');
    }
};
