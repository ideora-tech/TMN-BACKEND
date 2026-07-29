<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Unique komposit di DB menghitung juga baris soft-delete, sehingga nomor
// invoice bekas invoice terhapus tidak pernah bisa dipakai ulang (500 dari
// duplicate key). Keunikan nomor per perusahaan tetap dijaga di level
// aplikasi (InvoiceVendorRepository::nomorSudahDipakai, scope active).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropUnique('invoice_vendor_perusahaan_nomor_unique');
            $table->index(['id_perusahaan', 'nomor_invoice'], 'invoice_vendor_perusahaan_nomor_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropIndex('invoice_vendor_perusahaan_nomor_index');
            $table->unique(['id_perusahaan', 'nomor_invoice'], 'invoice_vendor_perusahaan_nomor_unique');
        });
    }
};
