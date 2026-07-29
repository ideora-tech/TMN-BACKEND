<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluasi_trip', function (Blueprint $table) {
            $table->unsignedTinyInteger('nilai_ketepatan_waktu')->nullable()->after('nilai_supir');
            $table->unsignedTinyInteger('nilai_kualitas')->nullable()->after('nilai_ketepatan_waktu');
            $table->unsignedTinyInteger('nilai_harga')->nullable()->after('nilai_kualitas');
            $table->unsignedTinyInteger('nilai_responsif')->nullable()->after('nilai_harga');
        });
    }

    public function down(): void
    {
        Schema::table('evaluasi_trip', function (Blueprint $table) {
            $table->dropColumn(['nilai_ketepatan_waktu', 'nilai_kualitas', 'nilai_harga', 'nilai_responsif']);
        });
    }
};
