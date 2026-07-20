<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->index('id_armada', 'idx_penugasan_id_armada');
            $table->index('id_supir', 'idx_penugasan_id_supir');
            $table->index('id_armada_vendor', 'idx_penugasan_id_armada_vendor');
            $table->index('id_supir_vendor', 'idx_penugasan_id_supir_vendor');
        });

        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->index('id_penugasan', 'idx_jadwal_keberangkatan_id_penugasan');
        });

        Schema::table('trip', function (Blueprint $table) {
            $table->index('status', 'idx_trip_status');
        });
    }

    public function down(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->dropIndex('idx_penugasan_id_armada');
            $table->dropIndex('idx_penugasan_id_supir');
            $table->dropIndex('idx_penugasan_id_armada_vendor');
            $table->dropIndex('idx_penugasan_id_supir_vendor');
        });

        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->dropIndex('idx_jadwal_keberangkatan_id_penugasan');
        });

        Schema::table('trip', function (Blueprint $table) {
            $table->dropIndex('idx_trip_status');
        });
    }
};
