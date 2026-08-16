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
            $table->decimal('ptkp_dasar', 15, 2)->default(54000000)->after('plafon_gaji_bpjs_kesehatan');
            $table->decimal('ptkp_tambahan', 15, 2)->default(4500000)->after('ptkp_dasar');
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_payroll', function (Blueprint $table) {
            $table->dropColumn(['ptkp_dasar', 'ptkp_tambahan']);
        });
    }
};
