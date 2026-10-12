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
        if (!Schema::hasTable('rekening_perusahaan')) {
            Schema::create('rekening_perusahaan', function (Blueprint $table) {
                $table->char('id_rekening_perusahaan', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nama_bank', 100);
                $table->string('nomor_rekening', 50);
                $table->string('atas_nama_rekening', 200)->nullable();
                $table->boolean('utama')->default(false);
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('kas_kecil_pengisian', 'id_rekening_perusahaan')) {
            Schema::table('kas_kecil_pengisian', function (Blueprint $table) {
                $table->char('id_rekening_perusahaan', 36)->nullable();
            });
        }

        $perusahaan = DB::table('perusahaan')
            ->whereNull('dihapus_pada')
            ->whereNotNull('nama_bank')
            ->whereNotNull('nomor_rekening')
            ->get(['id_perusahaan', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening']);

        foreach ($perusahaan as $satu) {
            $namaBank = trim((string) $satu->nama_bank);
            $nomor    = trim((string) $satu->nomor_rekening);
            if ($namaBank === '' || $nomor === '') {
                continue;
            }

            $sudahAda = DB::table('rekening_perusahaan')
                ->where('id_perusahaan', $satu->id_perusahaan)
                ->whereNull('dihapus_pada')
                ->exists();
            if ($sudahAda) {
                continue;
            }

            DB::table('rekening_perusahaan')->insert([
                'id_rekening_perusahaan' => (string) Str::uuid(),
                'id_perusahaan'          => $satu->id_perusahaan,
                'nama_bank'              => $namaBank,
                'nomor_rekening'         => $nomor,
                'atas_nama_rekening'     => $satu->atas_nama_rekening,
                'utama'                  => 1,
                'dibuat_pada'            => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kas_kecil_pengisian', 'id_rekening_perusahaan')) {
            Schema::table('kas_kecil_pengisian', function (Blueprint $table) {
                $table->dropColumn('id_rekening_perusahaan');
            });
        }

        Schema::dropIfExists('rekening_perusahaan');
    }
};
