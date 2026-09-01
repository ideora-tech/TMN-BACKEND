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
        Schema::create('tipe_pembayaran', function (Blueprint $table) {
            $table->char('id_tipe_pembayaran', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode_tipe', 50);
            $table->string('nama_tipe', 150);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->unique(['id_perusahaan', 'kode_tipe']);
        });

        // Nilai default — sama persis dengan enum lama tipe_pembayaran di
        // invoice_vendor (full_payment/dp/top/advance_payment) supaya data
        // existing tetap cocok dengan kode_tipe di tabel master ini.
        $defaults = [
            ['kode_tipe' => 'full_payment',    'nama_tipe' => 'Full Payment'],
            ['kode_tipe' => 'dp',              'nama_tipe' => 'DP'],
            ['kode_tipe' => 'top',             'nama_tipe' => 'TOP'],
            ['kode_tipe' => 'advance_payment', 'nama_tipe' => 'Advance Payment'],
        ];

        $now = now();
        $perusahaanList = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');
        foreach ($perusahaanList as $idPerusahaan) {
            foreach ($defaults as $item) {
                DB::table('tipe_pembayaran')->insertOrIgnore([
                    'id_tipe_pembayaran' => (string) Str::uuid(),
                    'id_perusahaan'      => $idPerusahaan,
                    'kode_tipe'          => $item['kode_tipe'],
                    'nama_tipe'          => $item['nama_tipe'],
                    'aktif'              => 1,
                    'dibuat_pada'        => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tipe_pembayaran');
    }
};
