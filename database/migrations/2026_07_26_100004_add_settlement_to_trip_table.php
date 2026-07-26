<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip', function (Blueprint $table) {
            $table->decimal('uang_jalan_alokasi', 15, 2)->nullable()->after('catatan');
            $table->string('status_settlement', 20)->default('belum')->after('uang_jalan_alokasi'); // belum, lunas
            $table->dateTime('settlement_pada')->nullable()->after('status_settlement');
            $table->char('settlement_oleh', 36)->nullable()->after('settlement_pada');
            $table->text('catatan_settlement')->nullable()->after('settlement_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('trip', function (Blueprint $table) {
            $table->dropColumn([
                'uang_jalan_alokasi', 'status_settlement', 'settlement_pada', 'settlement_oleh', 'catatan_settlement',
            ]);
        });
    }
};
