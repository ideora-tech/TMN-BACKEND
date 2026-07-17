<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->char('id_rute', 36)->nullable()->after('id_penugasan');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->dropColumn('id_rute');
        });
    }
};
