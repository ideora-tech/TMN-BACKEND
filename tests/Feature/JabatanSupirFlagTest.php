<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JabatanSupirFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_supir_tersimpan_dan_keluar_sebagai_boolean(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan' => 'SPR', 'nama_jabatan' => 'Supir Truk', 'is_supir' => true,
        ]);

        $res->assertStatus(201);
        $this->assertTrue($res->json('data.is_supir'));

        $id = $res->json('data.id_jabatan');
        $this->assertSame(1, (int) DB::table('jabatan')->where('id_jabatan', $id)->value('is_supir'));

        $resUpdate = $this->putJson("/api/v1/jabatan/{$id}", ['is_supir' => false]);
        $resUpdate->assertStatus(200);
        $this->assertFalse($resUpdate->json('data.is_supir'));
    }

    public function test_is_supir_default_nol_bila_tidak_dikirim(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan' => 'ADM', 'nama_jabatan' => 'Staf Admin',
        ]);

        $res->assertStatus(201);
        $this->assertFalse($res->json('data.is_supir'));
    }

    public function test_no_sim_supir_boleh_null(): void
    {
        DB::table('supir')->insert([
            'id_supir' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Tanpa SIM', 'no_sim' => null, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->assertSame(1, DB::table('supir')->whereNull('no_sim')->count());
    }

    public function test_form_manual_supir_tetap_wajib_no_sim(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/supir', ['nama' => 'Supir Manual']);

        $res->assertStatus(422);
        $this->assertArrayHasKey('no_sim', $res->json('errors'));
    }
}
