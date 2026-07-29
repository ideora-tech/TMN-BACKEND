<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->decimal('override_persen_bpjs_kesehatan', 5, 2)->nullable()->after('no_bpjs_ketenagakerjaan');
            $table->decimal('override_persen_bpjs_jht', 5, 2)->nullable()->after('override_persen_bpjs_kesehatan');
            $table->decimal('override_persen_bpjs_jp', 5, 2)->nullable()->after('override_persen_bpjs_jht');
            $table->decimal('override_plafon_bpjs_kesehatan', 15, 2)->nullable()->after('override_persen_bpjs_jp');
        });

        // Rekam persentase yang BENAR-BENAR dipakai saat generate (bukan setting
        // company-wide saat ini) supaya slip tetap akurat walau setting berubah belakangan.
        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->decimal('persen_bpjs_kesehatan', 5, 2)->default(0)->after('potongan_bpjs_tk');
            $table->decimal('persen_bpjs_jht', 5, 2)->default(0)->after('persen_bpjs_kesehatan');
            $table->decimal('persen_bpjs_jp', 5, 2)->default(0)->after('persen_bpjs_jht');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->dropColumn(['persen_bpjs_kesehatan', 'persen_bpjs_jht', 'persen_bpjs_jp']);
        });

        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropColumn([
                'override_persen_bpjs_kesehatan',
                'override_persen_bpjs_jht',
                'override_persen_bpjs_jp',
                'override_plafon_bpjs_kesehatan',
            ]);
        });
    }
};
