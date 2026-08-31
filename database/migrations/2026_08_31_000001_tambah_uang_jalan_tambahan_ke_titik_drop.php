<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titik_drop_penugasan', function (Blueprint $table) {
            $table->decimal('uang_jalan_tambahan', 15, 2)->default(0)->after('lokasi');
        });

        Schema::table('titik_drop_trip', function (Blueprint $table) {
            $table->decimal('uang_jalan_tambahan', 15, 2)->default(0)->after('lokasi');
        });
    }

    public function down(): void
    {
        Schema::table('titik_drop_penugasan', function (Blueprint $table) {
            $table->dropColumn('uang_jalan_tambahan');
        });

        Schema::table('titik_drop_trip', function (Blueprint $table) {
            $table->dropColumn('uang_jalan_tambahan');
        });
    }
};
