<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->string('sumber', 20)->default('internal')->after('estimasi_biaya');
            $table->char('id_kontrak_vendor', 36)->nullable()->after('sumber');
            $table->char('id_armada_vendor', 36)->nullable()->after('id_kontrak_vendor');
            $table->char('id_supir_vendor', 36)->nullable()->after('id_armada_vendor');
        });
    }

    public function down(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->dropColumn(['sumber', 'id_kontrak_vendor', 'id_armada_vendor', 'id_supir_vendor']);
        });
    }
};
