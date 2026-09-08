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
            $table->string('no_surat_jalan', 100)->nullable()->after('catatan_insiden');
        });
    }

    public function down(): void
    {
        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->dropColumn('no_surat_jalan');
        });
    }
};
