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
            $table->date('periode_dari')->nullable()->after('no_do');
            $table->date('periode_sampai')->nullable()->after('periode_dari');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropColumn(['periode_dari', 'periode_sampai']);
        });
    }
};
