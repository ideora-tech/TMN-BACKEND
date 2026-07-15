<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rute', function (Blueprint $table) {
            $table->char('id_lokasi_asal', 36)->nullable()->after('asal');
            $table->char('id_lokasi_tujuan', 36)->nullable()->after('tujuan');
        });
    }

    public function down(): void
    {
        Schema::table('rute', function (Blueprint $table) {
            $table->dropColumn(['id_lokasi_asal', 'id_lokasi_tujuan']);
        });
    }
};
