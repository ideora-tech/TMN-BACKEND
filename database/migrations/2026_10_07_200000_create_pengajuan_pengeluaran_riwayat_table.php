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
        if (Schema::hasTable('pengajuan_pengeluaran_riwayat')) {
            return;
        }

        Schema::create('pengajuan_pengeluaran_riwayat', function (Blueprint $table) {
            $table->char('id_riwayat', 36)->primary();
            $table->char('id_pengajuan', 36)->index();
            $table->unsignedInteger('urutan');
            $table->string('jenis', 30);
            $table->text('keterangan')->nullable();
            $table->decimal('nominal', 15, 2)->nullable();
            $table->char('oleh', 36)->nullable();
            $table->dateTime('waktu');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pengeluaran_riwayat');
    }
};
