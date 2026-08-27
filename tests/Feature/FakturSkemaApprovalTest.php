<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturSkemaApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_menunggu_approval_bisa_disimpan_dan_alasan_ditolak_internal_bisa_diisi(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();

        DB::table('faktur')->insert([
            'id_faktur'    => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-SKEMA-1',
            'total'        => 1000000,
            'status'       => 'menunggu_approval',
            'alasan_ditolak_internal' => 'Nilai tidak sesuai kontrak',
            'dibuat_pada'  => now(),
        ]);

        $this->assertDatabaseHas('faktur', [
            'id_faktur' => $id,
            'status'    => 'menunggu_approval',
            'alasan_ditolak_internal' => 'Nilai tidak sesuai kontrak',
        ]);
    }

    public function test_faktur_resource_menyertakan_alasan_ditolak_internal(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-SKEMA-2', 'total' => 500000,
            'status' => 'draft', 'alasan_ditolak_internal' => 'Perlu review ulang total',
            'dibuat_pada' => now(), 'dibuat_oleh' => $superadmin->id_pengguna,
        ]);

        $res = $this->getJson("/api/faktur/{$id}");
        $res->assertStatus(200)->assertJsonPath('data.alasan_ditolak_internal', 'Perlu review ulang total');
    }
}
