<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Kontrak vendor masuk alur Approval Generik: kontrak baru lahir 'draft',
     * diajukan lalu disetujui menjadi 'aktif'. Kontrak lama yang sudah 'aktif'
     * dibiarkan (grandfathered) supaya board tidak kosong saat deploy.
     */
    public function up(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->string('status', 50)->default('draft')->change();
            $table->text('alasan_ditolak_internal')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->string('status', 50)->default('aktif')->change();
            $table->dropColumn('alasan_ditolak_internal');
        });
    }
};
