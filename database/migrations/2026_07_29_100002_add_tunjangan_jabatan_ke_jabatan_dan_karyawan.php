<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->decimal('tunjangan_jabatan', 15, 2)->default(0)->after('level');
        });

        Schema::table('karyawan', function (Blueprint $table) {
            $table->decimal('override_tunjangan_jabatan', 15, 2)->nullable()->after('override_plafon_bpjs_kesehatan');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropColumn('tunjangan_jabatan');
        });

        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropColumn('override_tunjangan_jabatan');
        });
    }
};
