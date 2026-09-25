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
        if (!Schema::hasColumn('permintaan_pembelian_item', 'id_jenis_kendaraan')) {
            Schema::table('permintaan_pembelian_item', function (Blueprint $table) {
                $table->char('id_jenis_kendaraan', 36)->nullable()->index()->after('id_sparepart');
                $table->string('merk', 100)->nullable()->after('id_jenis_kendaraan');
                $table->string('model', 100)->nullable()->after('merk');
                $table->smallInteger('tahun')->nullable()->after('model');
            });
        }

        if (!Schema::hasTable('permintaan_pembelian_termin')) {
            Schema::create('permintaan_pembelian_termin', function (Blueprint $table) {
                $table->char('id_termin', 36)->primary();
                $table->char('id_permintaan', 36)->index();
                $table->smallInteger('urutan');
                $table->string('nama', 100);
                $table->decimal('nominal', 15, 2);
                $table->date('jatuh_tempo')->nullable();
                $table->char('id_pengajuan', 36)->nullable()->index();
                $table->string('status', 20)->default('menunggu');
                $table->date('tanggal_transfer')->nullable();
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_termin_pembelian')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_termin_pembelian', 36)->nullable()->index()->after('id_permintaan_pembelian');
            });
        }

        if (!Schema::hasColumn('armada', 'id_permintaan_pembelian_item')) {
            Schema::table('armada', function (Blueprint $table) {
                $table->char('id_permintaan_pembelian_item', 36)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('armada', 'id_permintaan_pembelian_item')) {
            Schema::table('armada', function (Blueprint $table) {
                $table->dropIndex(['id_permintaan_pembelian_item']);
                $table->dropColumn('id_permintaan_pembelian_item');
            });
        }

        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_termin_pembelian')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropIndex(['id_termin_pembelian']);
                $table->dropColumn('id_termin_pembelian');
            });
        }

        Schema::dropIfExists('permintaan_pembelian_termin');

        if (Schema::hasColumn('permintaan_pembelian_item', 'id_jenis_kendaraan')) {
            Schema::table('permintaan_pembelian_item', function (Blueprint $table) {
                $table->dropIndex(['id_jenis_kendaraan']);
                $table->dropColumn(['id_jenis_kendaraan', 'merk', 'model', 'tahun']);
            });
        }
    }
};
