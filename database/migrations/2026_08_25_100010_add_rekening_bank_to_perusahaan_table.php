<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->string('nama_bank', 100)->nullable()->after('alamat');
            $table->string('atas_nama_rekening', 200)->nullable()->after('nama_bank');
            $table->string('nomor_rekening', 50)->nullable()->after('atas_nama_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('perusahaan', function (Blueprint $table) {
            $table->dropColumn(['nama_bank', 'atas_nama_rekening', 'nomor_rekening']);
        });
    }
};
