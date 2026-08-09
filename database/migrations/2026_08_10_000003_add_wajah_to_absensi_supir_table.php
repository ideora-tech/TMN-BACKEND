<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('absensi_supir', function (Blueprint $table) {
            $table->string('foto', 255)->nullable()->after('keterangan');
            $table->decimal('skor_wajah', 5, 4)->nullable()->after('foto');
            $table->tinyInteger('wajah_cocok')->nullable()->after('skor_wajah');
        });
    }

    public function down(): void
    {
        Schema::table('absensi_supir', function (Blueprint $table) {
            $table->dropColumn(['foto', 'skor_wajah', 'wajah_cocok']);
        });
    }
};
