<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek', function (Blueprint $table) {
            $table->decimal('harga_penawaran', 15, 2)->nullable()->after('tanggal_selesai');
            $table->decimal('harga_proyek', 15, 2)->nullable()->after('harga_penawaran');
        });
    }

    public function down(): void
    {
        Schema::table('proyek', function (Blueprint $table) {
            $table->dropColumn(['harga_penawaran', 'harga_proyek']);
        });
    }
};
