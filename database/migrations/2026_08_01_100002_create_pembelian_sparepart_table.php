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
        Schema::create('pembelian_sparepart', function (Blueprint $table) {
            $table->char('id_pembelian', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('nomor_pengajuan', 30);
            $table->char('id_supplier', 36)->index();
            $table->char('id_perawatan', 36)->nullable()->index();
            $table->enum('status', ['diajukan', 'disetujui_manager', 'disetujui_finance', 'ditolak', 'dibeli', 'lunas'])->default('diajukan');
            $table->text('alasan_ditolak')->nullable();
            $table->char('disetujui_manager_oleh', 36)->nullable();
            $table->dateTime('disetujui_manager_pada')->nullable();
            $table->char('disetujui_finance_oleh', 36)->nullable();
            $table->dateTime('disetujui_finance_pada')->nullable();
            $table->decimal('total_estimasi', 15, 2)->default(0);
            $table->decimal('total_aktual', 15, 2)->nullable();
            $table->date('tanggal_pengajuan');
            $table->date('tanggal_pembelian')->nullable();
            $table->date('tanggal_pembayaran')->nullable();
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'nomor_pengajuan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembelian_sparepart');
    }
};
