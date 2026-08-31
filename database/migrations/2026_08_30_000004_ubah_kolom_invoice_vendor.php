<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->renameColumn('no_do', 'no_kontrak');
        });

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->string('nopol', 20)->nullable()->after('no_kontrak');
            $table->string('tipe_kendaraan', 100)->nullable()->after('nopol');
            $table->string('tipe_pembayaran', 30)->nullable()->after('tipe_kendaraan');
            $table->unsignedSmallInteger('top_hari')->nullable()->after('tipe_pembayaran');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropColumn(['nopol', 'tipe_kendaraan', 'tipe_pembayaran', 'top_hari']);
        });

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->renameColumn('no_kontrak', 'no_do');
        });
    }
};
