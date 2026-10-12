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
    private string $kodeApproval = 'pengeluaran_supir';

    public function up(): void
    {
        if (!Schema::hasTable('pengeluaran_supir')) {
            Schema::create('pengeluaran_supir', function (Blueprint $table) {
                $table->char('id_pengeluaran_supir', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nomor', 30);
                $table->char('id_supir', 36)->index();
                $table->string('jenis', 30);
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                $table->char('id_armada', 36)->nullable()->index();
                $table->char('id_armada_vendor', 36)->nullable();
                $table->string('nopol', 20);
                $table->string('keterangan', 500)->nullable();
                $table->string('nama_bank', 100)->nullable();
                $table->string('nomor_rekening', 50)->nullable();
                $table->string('atas_nama_rekening', 150)->nullable();
                $table->char('id_pengajuan', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->unique(['id_perusahaan', 'nomor']);
            });
        }

        if (!Schema::hasTable('pengeluaran_supir_foto')) {
            Schema::create('pengeluaran_supir_foto', function (Blueprint $table) {
                $table->char('id_foto', 36)->primary();
                $table->char('id_pengeluaran_supir', 36)->index();
                $table->string('url_foto', 255);
                $table->unsignedTinyInteger('urutan')->default(1);
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_pengeluaran_supir')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_pengeluaran_supir', 36)->nullable()->unique();
            });
        }

        $this->siapkanApproval();
    }

    public function down(): void
    {
        $idEventType = DB::table('approval_event_type')->where('kode', $this->kodeApproval)->pluck('id_event_type');
        DB::table('approval_config_approver')->whereIn('id_event_type', $idEventType)->delete();
        DB::table('approval_event_type')->whereIn('id_event_type', $idEventType)->delete();

        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_pengeluaran_supir')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropUnique(['id_pengeluaran_supir']);
                $table->dropColumn('id_pengeluaran_supir');
            });
        }

        Schema::dropIfExists('pengeluaran_supir_foto');
        Schema::dropIfExists('pengeluaran_supir');
    }

    private function siapkanApproval(): void
    {
        $umum = DB::table('approval_event_type')
            ->where('kode', 'pengajuan_pengeluaran')
            ->where('mode_resolusi', 'pinned')
            ->whereNull('dihapus_pada')
            ->get(['id_event_type', 'id_perusahaan', 'aktif']);

        foreach ($umum as $sumber) {
            $sudahAda = DB::table('approval_event_type')
                ->where('id_perusahaan', $sumber->id_perusahaan)
                ->where('kode', $this->kodeApproval)
                ->whereNull('dihapus_pada')
                ->exists();
            if ($sudahAda) {
                continue;
            }

            $idEventType = (string) Str::uuid();
            DB::table('approval_event_type')->insert([
                'id_event_type' => $idEventType,
                'id_perusahaan' => $sumber->id_perusahaan,
                'kode'          => $this->kodeApproval,
                'nama'          => 'Pengeluaran Supir',
                'mode_resolusi' => 'pinned',
                'aktif'         => $sumber->aktif,
                'dibuat_pada'   => now(),
            ]);

            $approver = DB::table('approval_config_approver')
                ->where('id_event_type', $sumber->id_event_type)
                ->whereNull('dihapus_pada')
                ->get(['tipe', 'id_jabatan', 'id_pengguna']);

            foreach ($approver as $satu) {
                DB::table('approval_config_approver')->insert([
                    'id_config'     => (string) Str::uuid(),
                    'id_event_type' => $idEventType,
                    'tipe'          => $satu->tipe,
                    'id_jabatan'    => $satu->id_jabatan,
                    'id_pengguna'   => $satu->id_pengguna,
                    'dibuat_pada'   => now(),
                ]);
            }
        }
    }
};
