<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->char('id_jenis_perawatan', 36)->nullable()->after('id_armada');
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropColumn('id_jenis_perawatan');
        });
    }
};
