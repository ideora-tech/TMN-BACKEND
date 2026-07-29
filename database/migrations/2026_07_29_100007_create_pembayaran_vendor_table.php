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
        Schema::create('pembayaran_vendor', function (Blueprint $table) {
            $table->char('id_pembayaran_vendor', 36)->primary();
            $table->char('id_invoice_vendor', 36);
            $table->date('tanggal_bayar');
            $table->decimal('nominal', 15, 2);
            $table->string('metode', 30)->default('transfer');
            $table->string('bank_pengirim', 100)->nullable();
            $table->string('no_referensi', 100)->nullable();
            $table->string('url_bukti', 500)->nullable();
            $table->text('catatan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_invoice_vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_vendor');
    }
};
