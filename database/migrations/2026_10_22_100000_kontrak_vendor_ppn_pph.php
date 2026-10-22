<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('kontrak_vendor', 'ppn_persen')) {
            Schema::table('kontrak_vendor', function (Blueprint $table) {
                $table->decimal('ppn_persen', 5, 2)->nullable()->after('pajak_persen');
            });
        }

        if (!Schema::hasColumn('kontrak_vendor', 'pph_persen')) {
            Schema::table('kontrak_vendor', function (Blueprint $table) {
                $table->decimal('pph_persen', 5, 2)->nullable()->after('ppn_persen');
            });
        }

        DB::table('kontrak_vendor')
            ->whereNotNull('pajak_persen')
            ->whereNull('ppn_persen')
            ->update(['ppn_persen' => DB::raw('pajak_persen')]);
    }

    public function down(): void
    {
        foreach (['pph_persen', 'ppn_persen'] as $kolom) {
            if (Schema::hasColumn('kontrak_vendor', $kolom)) {
                Schema::table('kontrak_vendor', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }
};
