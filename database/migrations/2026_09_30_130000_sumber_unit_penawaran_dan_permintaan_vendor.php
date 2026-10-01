<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penawaran_item', function (Blueprint $table) {
            if (!Schema::hasColumn('penawaran_item', 'unit_aset')) {
                $table->unsignedSmallInteger('unit_aset')->nullable();
            }
            if (!Schema::hasColumn('penawaran_item', 'unit_vendor')) {
                $table->unsignedSmallInteger('unit_vendor')->nullable();
            }
        });

        if (!Schema::hasColumn('permintaan_vendor', 'id_penawaran')) {
            Schema::table('permintaan_vendor', function (Blueprint $table) {
                $table->char('id_penawaran', 36)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('permintaan_vendor', function (Blueprint $table) {
            $table->dropIndex(['id_penawaran']);
            $table->dropColumn('id_penawaran');
        });
        Schema::table('penawaran_item', function (Blueprint $table) {
            $table->dropColumn(['unit_aset', 'unit_vendor']);
        });
    }
};
