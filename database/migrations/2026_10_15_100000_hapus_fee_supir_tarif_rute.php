<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['fee_supir_jenjang', 'fee_supir'] as $kolom) {
            if (Schema::hasColumn('proyek_rute', $kolom)) {
                Schema::table('proyek_rute', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('proyek_rute', 'fee_supir_jenjang')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->json('fee_supir_jenjang')->nullable();
            });
        }
    }
};
