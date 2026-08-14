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
        Schema::create('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->char('id_pengajuan', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('nomor_pengajuan', 30);
            $table->string('kategori', 30);
            $table->decimal('nominal', 15, 2);
            $table->date('tanggal_pengajuan');
            $table->string('penerima', 150);
            $table->string('keterangan', 255)->nullable();
            $table->string('status', 20)->default('diajukan');
            $table->text('alasan_ditolak')->nullable();
            $table->char('dicek_oleh', 36)->nullable();
            $table->dateTime('dicek_pada')->nullable();
            $table->char('disetujui_oleh', 36)->nullable();
            $table->dateTime('disetujui_pada')->nullable();
            $table->char('ditransfer_oleh', 36)->nullable();
            $table->dateTime('ditransfer_pada')->nullable();
            $table->date('tanggal_transfer')->nullable();
            $table->string('url_bukti', 500)->nullable();
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'status']);
            $table->index(['id_perusahaan', 'tanggal_transfer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pengeluaran');
    }
};
