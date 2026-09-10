<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->char('id_supplier', 36)->nullable()->after('id_armada');
            $table->index('id_supplier', 'perawatan_armada_id_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropIndex('perawatan_armada_id_supplier_idx');
            $table->dropColumn('id_supplier');
        });
    }
};
