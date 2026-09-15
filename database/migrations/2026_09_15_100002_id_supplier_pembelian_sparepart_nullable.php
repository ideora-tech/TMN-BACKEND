<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembelian_sparepart', function (Blueprint $table) {
            $table->char('id_supplier', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pembelian_sparepart', function (Blueprint $table) {
            $table->char('id_supplier', 36)->nullable(false)->change();
        });
    }
};
