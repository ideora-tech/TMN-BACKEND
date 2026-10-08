<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kasbon_riwayat_cicilan')) {
            Schema::create('kasbon_riwayat_cicilan', function (Blueprint $table) {
                $table->char('id_riwayat_cicilan', 36)->primary();
                $table->char('id_kasbon', 36)->index();
                $table->decimal('cicilan_lama', 15, 2);
                $table->decimal('cicilan_baru', 15, 2);
                $table->date('mulai_potong_lama');
                $table->date('mulai_potong_baru');
                $table->string('alasan', 255);
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasIndex('kasbon_pembayaran', ['id_pemasukan'])) {
            Schema::table('kasbon_pembayaran', function (Blueprint $table) {
                $table->index('id_pemasukan');
            });
        }

        if (!Schema::hasColumn('payroll_slip', 'kasbon_manual')) {
            Schema::table('payroll_slip', function (Blueprint $table) {
                $table->tinyInteger('kasbon_manual')->default(0);
            });
        }

        if (!Schema::hasColumn('payroll_slip', 'sisa_kasbon_setelah_potong')) {
            Schema::table('payroll_slip', function (Blueprint $table) {
                $table->decimal('sisa_kasbon_setelah_potong', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_slip', 'sisa_kasbon_setelah_potong')) {
            Schema::table('payroll_slip', function (Blueprint $table) {
                $table->dropColumn('sisa_kasbon_setelah_potong');
            });
        }

        if (Schema::hasColumn('payroll_slip', 'kasbon_manual')) {
            Schema::table('payroll_slip', function (Blueprint $table) {
                $table->dropColumn('kasbon_manual');
            });
        }

        if (Schema::hasIndex('kasbon_pembayaran', ['id_pemasukan'])) {
            Schema::table('kasbon_pembayaran', function (Blueprint $table) {
                $table->dropIndex(['id_pemasukan']);
            });
        }

        Schema::dropIfExists('kasbon_riwayat_cicilan');
    }
};
