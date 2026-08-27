<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturApprovalListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_update_status_disetujui_jadi_terkirim(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-LSN-1', 'total' => 100000, 'status' => 'menunggu_approval',
            'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'faktur', $id, 'disetujui', null));

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'status' => 'terkirim']);
    }

    public function test_listener_update_status_ditolak_kembali_draft_dengan_alasan(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-LSN-2', 'total' => 100000, 'status' => 'menunggu_approval',
            'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'faktur', $id, 'ditolak', 'Salah nominal'));

        $this->assertDatabaseHas('faktur', [
            'id_faktur' => $id, 'status' => 'draft', 'alasan_ditolak_internal' => 'Salah nominal',
        ]);
    }

    public function test_listener_mengabaikan_event_type_lain(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-LSN-3', 'total' => 100000, 'status' => 'menunggu_approval',
            'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'penawaran', $id, 'disetujui', null));

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'status' => 'menunggu_approval']);
    }

    public function test_listener_mengabaikan_referensi_perusahaan_lain(): void
    {
        $this->ensurePerusahaan();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-LSN-4', 'total' => 100000, 'status' => 'menunggu_approval',
            'dibuat_pada' => now(),
        ]);

        event(new ApprovalDiputuskan($idPerusahaanLain, (string) Str::uuid(), (string) Str::uuid(), 'faktur', $id, 'disetujui', null));

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'status' => 'menunggu_approval']);
    }
}
