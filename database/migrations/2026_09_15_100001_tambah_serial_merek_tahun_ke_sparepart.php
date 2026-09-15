<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->after('nama');
            $table->string('merek', 100)->nullable()->after('serial_number');
            $table->unsignedSmallInteger('tahun')->nullable()->after('merek');
            $table->index(['id_perusahaan', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->dropIndex(['id_perusahaan', 'serial_number']);
            $table->dropColumn(['serial_number', 'merek', 'tahun']);
        });
    }
};
