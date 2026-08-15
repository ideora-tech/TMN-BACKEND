<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->string('proyek', 150)->nullable()->after('id_karyawan');
            $table->string('tipe_truck', 50)->nullable()->after('proyek');
            $table->string('absen_masuk', 10)->nullable()->after('tipe_truck');
            $table->decimal('uang_makan', 15, 2)->default(0)->after('keterangan_tunjangan');
            $table->decimal('uang_makan_mingguan', 15, 2)->default(0)->after('uang_makan');
            $table->decimal('kasbon', 15, 2)->default(0)->after('uang_makan_mingguan');
            $table->decimal('uang_jalan_terpakai', 15, 2)->default(0)->after('kasbon');
            $table->decimal('tilangan', 15, 2)->default(0)->after('uang_jalan_terpakai');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->dropColumn([
                'proyek', 'tipe_truck', 'absen_masuk',
                'uang_makan', 'uang_makan_mingguan', 'kasbon', 'uang_jalan_terpakai', 'tilangan',
            ]);
        });
    }
};
