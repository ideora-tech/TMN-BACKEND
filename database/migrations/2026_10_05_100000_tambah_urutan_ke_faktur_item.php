<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('faktur_item', 'urutan')) {
            return;
        }

        Schema::table('faktur_item', function (Blueprint $table) {
            $table->unsignedSmallInteger('urutan')->default(0);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('faktur_item', 'urutan')) {
            return;
        }

        Schema::table('faktur_item', function (Blueprint $table) {
            $table->dropColumn('urutan');
        });
    }
};
