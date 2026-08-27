<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranSkemaApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_menunggu_approval_bisa_disimpan_dan_alasan_ditolak_internal_bisa_diisi(): void
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();

        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-SKEMA-1',
            'judul'           => 'Uji Skema',
            'status'          => 'menunggu_approval',
            'alasan_ditolak_internal' => 'Harga terlalu rendah, revisi dulu',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $id,
            'status'       => 'menunggu_approval',
            'alasan_ditolak_internal' => 'Harga terlalu rendah, revisi dulu',
        ]);
    }

    public function test_penawaran_resource_menyertakan_alasan_ditolak_internal(): void
    {
        $admin = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-SKEMA-2', 'judul' => 'Uji Resource',
            'status' => 'draft', 'alasan_ditolak_internal' => 'Perlu review ulang budget',
            'aktif' => 1, 'dibuat_pada' => now(), 'dibuat_oleh' => $admin->id_pengguna,
        ]);

        $res = $this->getJson("/api/penawaran/{$id}");
        $res->assertStatus(200)->assertJsonPath('data.alasan_ditolak_internal', 'Perlu review ulang budget');
    }
}
