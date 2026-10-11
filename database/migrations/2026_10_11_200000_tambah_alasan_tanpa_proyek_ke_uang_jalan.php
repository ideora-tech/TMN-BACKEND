<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('uang_jalan', 'alasan_tanpa_proyek')) {
            Schema::table('uang_jalan', function (Blueprint $table) {
                $table->string('alasan_tanpa_proyek', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('uang_jalan', 'alasan_tanpa_proyek')) {
            Schema::table('uang_jalan', function (Blueprint $table) {
                $table->dropColumn('alasan_tanpa_proyek');
            });
        }
    }
};
