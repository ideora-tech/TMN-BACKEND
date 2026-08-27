<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $idEventTypeByPerusahaan = [];

        $semuaPerusahaan = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');
        foreach ($semuaPerusahaan as $idPerusahaan) {
            $existing = DB::table('approval_event_type')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode', 'pengajuan_pengeluaran')
                ->whereNull('dihapus_pada')
                ->first();

            if ($existing !== null) {
                $idEventTypeByPerusahaan[$idPerusahaan] = $existing->id_event_type;
                continue;
            }

            $idEventType = (string) Str::uuid();
            DB::table('approval_event_type')->insert([
                'id_event_type' => $idEventType,
                'id_perusahaan' => $idPerusahaan,
                'kode'          => 'pengajuan_pengeluaran',
                'nama'          => 'Pengajuan Pengeluaran',
                'mode_resolusi' => 'pinned',
                'aktif'         => 1,
                'dibuat_pada'   => $now,
            ]);
            $idEventTypeByPerusahaan[$idPerusahaan] = $idEventType;
        }

        $perusahaanDenganApprover = DB::table('approver_keuangan')->whereNull('dihapus_pada')->distinct()->pluck('id_perusahaan');

        foreach ($perusahaanDenganApprover as $idPerusahaan) {
            $idEventType = $idEventTypeByPerusahaan[$idPerusahaan] ?? null;
            if ($idEventType === null) {
                continue;
            }

            $approverList = DB::table('approver_keuangan')
                ->where('id_perusahaan', $idPerusahaan)->whereNull('dihapus_pada')->get();
            foreach ($approverList as $approver) {
                DB::table('approval_config_approver')->insert([
                    'id_config'     => (string) Str::uuid(),
                    'id_event_type' => $idEventType,
                    'tipe'          => $approver->tipe,
                    'id_jabatan'    => $approver->id_jabatan,
                    'id_pengguna'   => $approver->id_pengguna,
                    'dibuat_pada'   => $now,
                ]);
            }

            $pengajuanMenunggu = DB::table('pengajuan_pengeluaran')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'menunggu_approval')
                ->whereNull('dihapus_pada')
                ->get();

            foreach ($pengajuanMenunggu as $pengajuan) {
                $idApproval = (string) Str::uuid();
                DB::table('approval_pengajuan')->insert([
                    'id_approval'         => $idApproval,
                    'id_perusahaan'       => $idPerusahaan,
                    'id_event_type'       => $idEventType,
                    'id_referensi'        => $pengajuan->id_pengajuan,
                    'id_pengguna_pengaju' => $pengajuan->dibuat_oleh ?? '00000000-0000-0000-0000-000000000000',
                    'nominal'             => $pengajuan->nominal,
                    'status'              => 'menunggu',
                    'dibuat_pada'         => $now,
                ]);

                $barisApproval = DB::table('pengajuan_approval')
                    ->where('id_pengajuan', $pengajuan->id_pengajuan)->whereNull('dihapus_pada')->get();
                foreach ($barisApproval as $baris) {
                    DB::table('approval_keputusan')->insert([
                        'id_keputusan' => (string) Str::uuid(),
                        'id_approval'  => $idApproval,
                        'id_pengguna'  => $baris->id_pengguna,
                        'status'       => $baris->status,
                        'catatan'      => $baris->catatan,
                        'waktu_aksi'   => $baris->waktu_aksi,
                        'dibuat_pada'  => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Searah — data lama tetap ada di approver_keuangan/pengajuan_approval sampai Task 8 (drop tabel),
        // jadi tidak perlu mengembalikan apa pun di sini.
    }
};
