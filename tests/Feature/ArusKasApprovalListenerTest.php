<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasApprovalListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_update_status_disetujui_dan_jalankan_hook(): void
    {
        $this->ensurePerusahaan();
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-LISTENER-1', 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);
        $idPengguna = (string) Str::uuid();

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), $idPengguna, 'pengajuan_pengeluaran', $idPengajuan, 'disetujui', null));

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'   => $idPengajuan,
            'status'         => 'disetujui',
            'disetujui_oleh' => $idPengguna,
        ]);
    }

    public function test_listener_update_status_ditolak_dengan_alasan(): void
    {
        $this->ensurePerusahaan();
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-LISTENER-2', 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'pengajuan_pengeluaran', $idPengajuan, 'ditolak', 'Anggaran tidak cukup'));

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'   => $idPengajuan,
            'status'         => 'ditolak',
            'alasan_ditolak' => 'Anggaran tidak cukup',
        ]);
    }

    public function test_listener_mengabaikan_event_type_lain(): void
    {
        $this->ensurePerusahaan();
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-LISTENER-3', 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'event_type_lain', $idPengajuan, 'disetujui', null));

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan, 'status' => 'menunggu_approval']);
    }

    public function test_listener_mengabaikan_referensi_perusahaan_lain(): void
    {
        $this->ensurePerusahaan();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-LISTENER-4', 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan($idPerusahaanLain, (string) Str::uuid(), (string) Str::uuid(), 'pengajuan_pengeluaran', $idPengajuan, 'disetujui', null));

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan, 'status' => 'menunggu_approval']);
    }
}
