<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Event approval 'proyek' menggerbang proyek yang dibuat manual (tanpa
     * penawaran). Approver awal disalin dari event 'penawaran' perusahaan
     * yang sama supaya gerbang langsung berfungsi tanpa konfigurasi ulang;
     * bisa diubah kapan saja lewat halaman Konfigurasi Approval.
     */
    public function up(): void
    {
        $perusahaanIds = DB::table('perusahaan')->pluck('id_perusahaan');

        foreach ($perusahaanIds as $idPerusahaan) {
            $sudahAda = DB::table('approval_event_type')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode', 'proyek')
                ->whereNull('dihapus_pada')
                ->exists();
            if ($sudahAda) {
                continue;
            }

            $acuan = DB::table('approval_event_type')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode', 'penawaran')
                ->whereNull('dihapus_pada')
                ->first();

            $idEventType = (string) Str::uuid();
            DB::table('approval_event_type')->insert([
                'id_event_type' => $idEventType,
                'id_perusahaan' => $idPerusahaan,
                'kode'          => 'proyek',
                'nama'          => 'Proyek',
                'mode_resolusi' => $acuan->mode_resolusi ?? 'pinned',
                'aktif'         => 1,
                'dibuat_pada'   => now(),
            ]);

            if ($acuan === null) {
                continue;
            }

            $approvers = DB::table('approval_config_approver')
                ->where('id_event_type', $acuan->id_event_type)
                ->whereNull('dihapus_pada')
                ->get();

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

    public function down(): void
    {
        $ids = DB::table('approval_event_type')->where('kode', 'proyek')->pluck('id_event_type');
        DB::table('approval_config_approver')->whereIn('id_event_type', $ids)->delete();
        DB::table('approval_event_type')->whereIn('id_event_type', $ids)->delete();
    }
};
