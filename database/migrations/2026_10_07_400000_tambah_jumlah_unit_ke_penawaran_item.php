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
        if (!Schema::hasColumn('penawaran_item', 'jumlah_unit')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->unsignedSmallInteger('jumlah_unit')->nullable();
            });
        }

        if (Schema::hasColumn('penawaran_item', 'unit_aset') && Schema::hasColumn('penawaran_item', 'unit_vendor')) {
            DB::table('penawaran_item')
                ->whereNull('jumlah_unit')
                ->where(fn ($q) => $q->whereNotNull('unit_aset')->orWhereNotNull('unit_vendor'))
                ->update(['jumlah_unit' => DB::raw('NULLIF(COALESCE(unit_aset, 0) + COALESCE(unit_vendor, 0), 0)')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('penawaran_item', 'jumlah_unit')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->dropColumn('jumlah_unit');
            });
        }
    }
};
