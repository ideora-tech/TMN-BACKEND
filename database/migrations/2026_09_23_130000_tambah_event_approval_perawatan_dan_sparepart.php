<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $event = [
        'perawatan' => 'Perawatan Armada',
        'sparepart' => 'Pembelian Sparepart',
    ];

    public function up(): void
    {
        $perusahaanIds = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');

        foreach ($perusahaanIds as $idPerusahaan) {
            $acuan = DB::table('approval_event_type')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode', 'pengajuan_pengeluaran')
                ->whereNull('dihapus_pada')
                ->first();

            $approvers = $acuan === null ? collect() : DB::table('approval_config_approver')
                ->where('id_event_type', $acuan->id_event_type)
                ->whereNull('dihapus_pada')
                ->get();

            foreach ($this->event as $kode => $nama) {
                $sudahAda = DB::table('approval_event_type')
                    ->where('id_perusahaan', $idPerusahaan)
                    ->where('kode', $kode)
                    ->whereNull('dihapus_pada')
                    ->exists();
                if ($sudahAda) {
                    continue;
                }

                $idEventType = (string) Str::uuid();
                DB::table('approval_event_type')->insert([
                    'id_event_type' => $idEventType,
                    'id_perusahaan' => $idPerusahaan,
                    'kode'          => $kode,
                    'nama'          => $nama,
                    'mode_resolusi' => $acuan->mode_resolusi ?? 'pinned',
                    'aktif'         => 1,
                    'dibuat_pada'   => now(),
                ]);

                foreach ($approvers as $approver) {
                    DB::table('approval_config_approver')->insert([
                        'id_config'     => (string) Str::uuid(),
                        'id_event_type' => $idEventType,
                        'tipe'          => $approver->tipe,
                        'id_jabatan'    => $approver->id_jabatan,
                        'id_pengguna'   => $approver->id_pengguna,
                        'dibuat_pada'   => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('approval_event_type')->whereIn('kode', array_keys($this->event))->pluck('id_event_type');
        DB::table('approval_config_approver')->whereIn('id_event_type', $ids)->delete();
        DB::table('approval_event_type')->whereIn('id_event_type', $ids)->delete();
    }
};
