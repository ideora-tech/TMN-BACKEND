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
            $table->char('id_pengajuan', 36)->nullable()->after('id_armada_override');
            $table->index('id_pengajuan');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->dropIndex(['id_pengajuan']);
            $table->dropColumn('id_pengajuan');
        });
    }
};
