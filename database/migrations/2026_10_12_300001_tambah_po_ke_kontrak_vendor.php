<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            if (!Schema::hasColumn('kontrak_vendor', 'nomor_po')) {
                $table->string('nomor_po', 50)->nullable();
            }
            if (!Schema::hasColumn('kontrak_vendor', 'tanggal_po')) {
                $table->date('tanggal_po')->nullable();
            }
            if (!Schema::hasColumn('kontrak_vendor', 'po_dibuat_oleh')) {
                $table->char('po_dibuat_oleh', 36)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            foreach (['nomor_po', 'tanggal_po', 'po_dibuat_oleh'] as $kolom) {
                if (Schema::hasColumn('kontrak_vendor', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
