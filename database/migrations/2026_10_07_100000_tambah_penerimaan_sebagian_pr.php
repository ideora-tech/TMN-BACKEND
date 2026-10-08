<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permintaan_pembelian_penerimaan')) {
            Schema::create('permintaan_pembelian_penerimaan', function (Blueprint $table) {
                $table->char('id_penerimaan', 36)->primary();
                $table->char('id_permintaan', 36)->index();
                $table->date('tanggal_diterima');
                $table->text('keterangan')->nullable();
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasTable('permintaan_pembelian_penerimaan_item')) {
            Schema::create('permintaan_pembelian_penerimaan_item', function (Blueprint $table) {
                $table->char('id_penerimaan_item', 36)->primary();
                $table->char('id_penerimaan', 36)->index();
                $table->char('id_item', 36)->index();
                $table->integer('qty');
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('permintaan_pembelian', 'sisa_ditutup_pada')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dateTime('sisa_ditutup_pada')->nullable()->after('keterangan_penerimaan');
                $table->char('sisa_ditutup_oleh', 36)->nullable()->after('sisa_ditutup_pada');
                $table->text('alasan_tutup_sisa')->nullable()->after('sisa_ditutup_oleh');
            });
        }

        $this->isiRiwayatDariDataLama();
        $this->bukaKembaliPenerimaanSebagian();
    }

    public function down(): void
    {
        DB::table('permintaan_pembelian')->where('status', 'diterima_sebagian')->update(['status' => 'diterima']);

        if (Schema::hasColumn('permintaan_pembelian', 'sisa_ditutup_pada')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropColumn(['sisa_ditutup_pada', 'sisa_ditutup_oleh', 'alasan_tutup_sisa']);
            });
        }
        Schema::dropIfExists('permintaan_pembelian_penerimaan_item');
        Schema::dropIfExists('permintaan_pembelian_penerimaan');
    }

    private function isiRiwayatDariDataLama(): void
    {
        $daftar = DB::table('permintaan_pembelian')
            ->whereNull('dihapus_pada')
            ->where('tipe', 'umum')
            ->whereNotNull('tanggal_diterima')
            ->whereIn('status', ['diterima', 'selesai'])
            ->get(['id_permintaan', 'tanggal_diterima', 'keterangan_penerimaan', 'diterima_oleh', 'diterima_pada']);

        foreach ($daftar as $pr) {
            if (DB::table('permintaan_pembelian_penerimaan')->where('id_permintaan', $pr->id_permintaan)->exists()) {
                continue;
            }
            $items = DB::table('permintaan_pembelian_item')
                ->whereNull('dihapus_pada')
                ->where('id_permintaan', $pr->id_permintaan)
                ->where('qty_diterima', '>', 0)
                ->get(['id_item', 'qty_diterima']);
            if ($items->isEmpty()) {
                continue;
            }

            $idPenerimaan = (string) Str::uuid();
            $waktu = $pr->diterima_pada ?? now();
            DB::table('permintaan_pembelian_penerimaan')->insert([
                'id_penerimaan'    => $idPenerimaan,
                'id_permintaan'    => $pr->id_permintaan,
                'tanggal_diterima' => $pr->tanggal_diterima,
                'keterangan'       => $pr->keterangan_penerimaan,
                'dibuat_pada'      => $waktu,
                'dibuat_oleh'      => $pr->diterima_oleh,
            ]);
            foreach ($items as $item) {
                DB::table('permintaan_pembelian_penerimaan_item')->insert([
                    'id_penerimaan_item' => (string) Str::uuid(),
                    'id_penerimaan'      => $idPenerimaan,
                    'id_item'            => $item->id_item,
                    'qty'                => (int) $item->qty_diterima,
                    'dibuat_pada'        => $waktu,
                    'dibuat_oleh'        => $pr->diterima_oleh,
                ]);
            }
        }
    }

    private function bukaKembaliPenerimaanSebagian(): void
    {
        $kandidat = DB::table('permintaan_pembelian as p')
            ->whereNull('p.dihapus_pada')
            ->where('p.tipe', 'umum')
            ->where('p.status', 'diterima')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('permintaan_pembelian_item as i')
                    ->whereColumn('i.id_permintaan', 'p.id_permintaan')
                    ->whereNull('i.dihapus_pada')
                    ->whereRaw('COALESCE(i.qty_diterima, 0) < i.qty');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('pengajuan_pengeluaran as pp')
                    ->whereColumn('pp.id_permintaan_pembelian', 'p.id_permintaan')
                    ->whereNull('pp.dihapus_pada')
                    ->where('pp.status', 'ditransfer');
            })
            ->pluck('p.id_permintaan');

        if ($kandidat->isNotEmpty()) {
            DB::table('permintaan_pembelian')
                ->whereIn('id_permintaan', $kandidat->all())
                ->update(['status' => 'diterima_sebagian', 'diubah_pada' => now()]);
        }
    }
};
