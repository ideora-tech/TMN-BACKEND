<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoice_vendor MODIFY status ENUM('draft','menunggu_approval','diverifikasi','ditolak','dibatalkan') NOT NULL DEFAULT 'draft'");
        } else {
            Schema::table('invoice_vendor', function (Blueprint $table) {
                $table->enum('status', ['draft', 'menunggu_approval', 'diverifikasi', 'ditolak', 'dibatalkan'])
                    ->default('draft')->change();
            });
        }

        if (!Schema::hasColumn('invoice_vendor', 'alasan_batal')) {
            Schema::table('invoice_vendor', function (Blueprint $table) {
                $table->text('alasan_batal')->nullable();
                $table->char('dibatalkan_oleh', 36)->nullable();
                $table->dateTime('dibatalkan_pada')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoice_vendor', 'alasan_batal')) {
            Schema::table('invoice_vendor', function (Blueprint $table) {
                $table->dropColumn(['alasan_batal', 'dibatalkan_oleh', 'dibatalkan_pada']);
            });
        }

        DB::table('invoice_vendor')->where('status', 'dibatalkan')->update(['status' => 'draft']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoice_vendor MODIFY status ENUM('draft','menunggu_approval','diverifikasi','ditolak') NOT NULL DEFAULT 'draft'");
            return;
        }

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->enum('status', ['draft', 'menunggu_approval', 'diverifikasi', 'ditolak'])
                ->default('draft')->change();
        });
    }
};
