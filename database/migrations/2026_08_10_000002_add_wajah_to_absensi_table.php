<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->string('foto_masuk', 255)->nullable()->after('alamat_masuk');
            $table->decimal('skor_wajah_masuk', 5, 4)->nullable()->after('foto_masuk');
            $table->tinyInteger('wajah_cocok_masuk')->nullable()->after('skor_wajah_masuk');
            $table->string('foto_pulang', 255)->nullable()->after('alamat_pulang');
            $table->decimal('skor_wajah_pulang', 5, 4)->nullable()->after('foto_pulang');
            $table->tinyInteger('wajah_cocok_pulang')->nullable()->after('skor_wajah_pulang');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn(['foto_masuk', 'skor_wajah_masuk', 'wajah_cocok_masuk', 'foto_pulang', 'skor_wajah_pulang', 'wajah_cocok_pulang']);
        });
    }
};
