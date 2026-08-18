<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->char('id_supir_pengganti', 36)->nullable()->after('id_supir');
            $table->char('id_armada_override', 36)->nullable()->after('id_supir_pengganti');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->dropColumn(['id_supir_pengganti', 'id_armada_override']);
        });
    }
};
