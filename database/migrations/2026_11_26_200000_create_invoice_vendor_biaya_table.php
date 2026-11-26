<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya tambahan invoice vendor (nama + nominal bebas, mis. tol, parkir, kuli).
 * Tidak dikenai PPN/PPh: total = DPP + PPN − PPh + total_biaya_tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_vendor_biaya', function (Blueprint $table) {
            $table->char('id_invoice_vendor_biaya', 36)->primary();
            $table->char('id_invoice_vendor', 36);
            $table->string('nama', 150);
            $table->decimal('nominal', 15, 2)->default(0);
            $table->integer('urutan')->default(0);
            MigrationHelper::auditColumns($table);
            $table->index('id_invoice_vendor');
        });

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->decimal('total_biaya_tambahan', 15, 2)->default(0)->after('pph');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropColumn('total_biaya_tambahan');
        });
        Schema::dropIfExists('invoice_vendor_biaya');
    }
};
