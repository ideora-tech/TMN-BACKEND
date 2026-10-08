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
        if (!Schema::hasTable('pembayaran_faktur')) {
            Schema::create('pembayaran_faktur', function (Blueprint $table) {
                $table->char('id_pembayaran_faktur', 36)->primary();
                $table->char('id_faktur', 36)->index();
                $table->date('tanggal_bayar');
                $table->decimal('nominal', 15, 2)->default(0);
                $table->decimal('potongan', 15, 2)->default(0);
                $table->string('keterangan_potongan', 150)->nullable();
                $table->string('no_referensi', 100)->nullable();
                $table->string('url_bukti', 500)->nullable();
                $table->text('catatan')->nullable();
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('faktur', 'tanggal_lunas')) {
            Schema::table('faktur', function (Blueprint $table) {
                $table->date('tanggal_lunas')->nullable()->after('jatuh_tempo');
            });
        }

        $this->isiPelunasanLama();
    }

    public function down(): void
    {
        if (Schema::hasColumn('faktur', 'tanggal_lunas')) {
            Schema::table('faktur', function (Blueprint $table) {
                $table->dropColumn('tanggal_lunas');
            });
        }
        Schema::dropIfExists('pembayaran_faktur');
    }

    private function isiPelunasanLama(): void
    {
        $lunas = DB::table('faktur')
            ->whereNull('dihapus_pada')
            ->where('status', 'lunas')
            ->get(['id_faktur', 'total', 'tanggal_faktur', 'tanggal_lunas', 'diubah_pada', 'dibuat_pada', 'diubah_oleh']);

        foreach ($lunas as $faktur) {
            if (DB::table('pembayaran_faktur')->where('id_faktur', $faktur->id_faktur)->whereNull('dihapus_pada')->exists()) {
                continue;
            }

            $waktuLunas = DB::table('faktur_status_log')
                ->where('id_faktur', $faktur->id_faktur)
                ->where('status', 'lunas')
                ->orderByDesc('dibuat_pada')
                ->value('dibuat_pada');
            $tanggal = substr((string) ($waktuLunas ?? $faktur->diubah_pada ?? $faktur->tanggal_faktur ?? $faktur->dibuat_pada ?? now()), 0, 10);

            if ($faktur->tanggal_lunas === null) {
                DB::table('faktur')->where('id_faktur', $faktur->id_faktur)->update(['tanggal_lunas' => $tanggal]);
            }
            if ((float) $faktur->total <= 0) {
                continue;
            }

            DB::table('pembayaran_faktur')->insert([
                'id_pembayaran_faktur' => (string) Str::uuid(),
                'id_faktur'            => $faktur->id_faktur,
                'tanggal_bayar'        => $tanggal,
                'nominal'              => (float) $faktur->total,
                'potongan'             => 0,
                'catatan'              => 'Pelunasan tercatat sebelum fitur pembayaran bertahap',
                'dibuat_pada'          => now(),
                'dibuat_oleh'          => $faktur->diubah_oleh,
            ]);
        }
    }
};
