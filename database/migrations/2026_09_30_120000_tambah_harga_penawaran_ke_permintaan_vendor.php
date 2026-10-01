<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_vendor', 'harga_penawaran')) {
            Schema::table('permintaan_vendor', function (Blueprint $table) {
                $table->decimal('harga_penawaran', 15, 2)->nullable()->after('catatan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permintaan_vendor', 'harga_penawaran')) {
            Schema::table('permintaan_vendor', function (Blueprint $table) {
                $table->dropColumn('harga_penawaran');
            });
        }
    }
};
