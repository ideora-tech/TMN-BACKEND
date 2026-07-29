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
        Schema::create('invoice_vendor', function (Blueprint $table) {
            $table->char('id_invoice_vendor', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_vendor', 36);
            $table->char('id_kontrak_vendor', 36)->nullable();
            $table->string('nomor_invoice', 100);
            $table->date('tanggal_invoice');
            $table->date('jatuh_tempo')->nullable();
            $table->string('no_po', 100)->nullable();
            $table->string('no_do', 100)->nullable();
            $table->decimal('dpp', 15, 2)->default(0);
            $table->decimal('ppn', 15, 2)->default(0);
            $table->decimal('pph', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('status', ['draft', 'diverifikasi', 'ditolak'])->default('draft');
            $table->text('catatan_verifikasi')->nullable();
            $table->char('diverifikasi_oleh', 36)->nullable();
            $table->dateTime('diverifikasi_pada')->nullable();
            $table->enum('status_pembayaran', ['belum', 'sebagian', 'lunas'])->default('belum');
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_vendor');
            $table->unique(['id_perusahaan', 'nomor_invoice'], 'invoice_vendor_perusahaan_nomor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_vendor');
    }
};
