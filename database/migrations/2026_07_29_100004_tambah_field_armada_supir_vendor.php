<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->string('kapasitas', 50)->nullable()->after('jenis');
            $table->date('masa_berlaku_stnk')->nullable()->after('tahun');
            $table->date('masa_berlaku_kir')->nullable()->after('masa_berlaku_stnk');
        });

        Schema::table('supir_vendor', function (Blueprint $table) {
            $table->date('masa_berlaku_sim')->nullable()->after('no_sim');
        });
    }

    public function down(): void
    {
        Schema::table('supir_vendor', function (Blueprint $table) {
            $table->dropColumn('masa_berlaku_sim');
        });

        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->dropColumn(['kapasitas', 'masa_berlaku_stnk', 'masa_berlaku_kir']);
        });
    }
};
