<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supir', function (Blueprint $table) {
            $table->char('id_karyawan', 36)->nullable()->after('id_pengguna');
            $table->index('id_karyawan', 'supir_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supir', function (Blueprint $table) {
            $table->dropIndex('supir_karyawan_idx');
            $table->dropColumn('id_karyawan');
        });
    }
};
