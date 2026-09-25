<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('kontrak_vendor', 'jumlah_trip')) {
            Schema::table('kontrak_vendor', function (Blueprint $table) {
                $table->unsignedInteger('jumlah_trip')->nullable()->after('termin_pembayaran_hari');
            });
        }

        if (!Schema::hasColumn('kontrak_vendor', 'jumlah_hari')) {
            Schema::table('kontrak_vendor', function (Blueprint $table) {
                $table->unsignedInteger('jumlah_hari')->nullable()->after('jumlah_trip');
            });
        }
    }

    public function down(): void
    {
        foreach (['jumlah_hari', 'jumlah_trip'] as $kolom) {
            if (Schema::hasColumn('kontrak_vendor', $kolom)) {
                Schema::table('kontrak_vendor', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }
};
