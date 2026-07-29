<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_payroll', function (Blueprint $table) {
            $table->decimal('persen_bpjs_kesehatan', 5, 2)->default(1.00)->after('hari_kerja_per_bulan');
            $table->decimal('persen_bpjs_jht', 5, 2)->default(2.00)->after('persen_bpjs_kesehatan');
            $table->decimal('persen_bpjs_jp', 5, 2)->default(1.00)->after('persen_bpjs_jht');
            $table->decimal('plafon_gaji_bpjs_kesehatan', 15, 2)->default(12000000)->after('persen_bpjs_jp');
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_payroll', function (Blueprint $table) {
            $table->dropColumn(['persen_bpjs_kesehatan', 'persen_bpjs_jht', 'persen_bpjs_jp', 'plafon_gaji_bpjs_kesehatan']);
        });
    }
};
