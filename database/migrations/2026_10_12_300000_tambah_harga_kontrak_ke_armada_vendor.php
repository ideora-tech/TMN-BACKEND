<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('armada_vendor', 'harga_kontrak')) {
            Schema::table('armada_vendor', function (Blueprint $table) {
                $table->decimal('harga_kontrak', 15, 2)->nullable()->after('masa_berlaku_kir');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('armada_vendor', 'harga_kontrak')) {
            Schema::table('armada_vendor', function (Blueprint $table) {
                $table->dropColumn('harga_kontrak');
            });
        }
    }
};
