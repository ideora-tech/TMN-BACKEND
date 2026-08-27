<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranApprovalListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_update_status_disetujui_jadi_terkirim(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-LSN-1', 'judul' => 'Uji Listener', 'status' => 'menunggu_approval',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'penawaran', $id, 'disetujui', null));

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'status' => 'terkirim']);
    }

    public function test_listener_update_status_ditolak_kembali_draft_dengan_alasan(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-LSN-2', 'judul' => 'Uji Listener', 'status' => 'menunggu_approval',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'penawaran', $id, 'ditolak', 'Margin terlalu tipis'));

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $id, 'status' => 'draft', 'alasan_ditolak_internal' => 'Margin terlalu tipis',
        ]);
    }

    public function test_listener_mengabaikan_event_type_lain(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-LSN-3', 'judul' => 'Uji Listener', 'status' => 'menunggu_approval',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'pengajuan_pengeluaran', $id, 'disetujui', null));

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'status' => 'menunggu_approval']);
    }

    public function test_listener_mengabaikan_referensi_perusahaan_lain(): void
    {
        $this->ensurePerusahaan();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-LSN-4', 'judul' => 'Uji Listener', 'status' => 'menunggu_approval',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan($idPerusahaanLain, (string) Str::uuid(), (string) Str::uuid(), 'penawaran', $id, 'disetujui', null));

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'status' => 'menunggu_approval']);
    }
}
