<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->char('id_jenis_bbm', 36)->nullable()->after('id_trip');
            $table->decimal('jumlah_liter', 10, 2)->nullable()->after('biaya_bbm');
        });
    }

    public function down(): void
    {
        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->dropColumn(['id_jenis_bbm', 'jumlah_liter']);
        });
    }
};
