<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->decimal('latitude_masuk', 10, 7)->nullable()->after('jam_masuk');
            $table->decimal('longitude_masuk', 10, 7)->nullable()->after('latitude_masuk');
            $table->string('alamat_masuk', 500)->nullable()->after('longitude_masuk');
            $table->decimal('latitude_pulang', 10, 7)->nullable()->after('jam_pulang');
            $table->decimal('longitude_pulang', 10, 7)->nullable()->after('latitude_pulang');
            $table->string('alamat_pulang', 500)->nullable()->after('longitude_pulang');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn(['latitude_masuk', 'longitude_masuk', 'alamat_masuk', 'latitude_pulang', 'longitude_pulang', 'alamat_pulang']);
        });
    }
};
