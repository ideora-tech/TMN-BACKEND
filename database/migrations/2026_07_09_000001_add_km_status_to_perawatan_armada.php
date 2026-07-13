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
            $table->unsignedInteger('km_odometer')->nullable()->after('biaya');
            $table->enum('status', ['terjadwal', 'dalam_proses', 'selesai'])->default('selesai')->after('km_odometer');
            $table->date('jadwal_servis_berikutnya')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropColumn(['km_odometer', 'status', 'jadwal_servis_berikutnya']);
        });
    }
};
