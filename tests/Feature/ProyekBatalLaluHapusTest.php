<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jalur yang dipakai tombol "Batalkan Proyek" di halaman detail proyek:
 * proyek Aktif tidak bisa dihapus, harus dibatalkan dulu, lalu baru bisa dihapus dari daftar.
 */
class ProyekBatalLaluHapusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('SUPERADMIN');
    }

    private function proyek(string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek Uji', 'status' => $status, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    public function test_proyek_aktif_tidak_bisa_dihapus_langsung(): void
    {
        $id = $this->proyek('aktif');

        $res = $this->deleteJson("/api/proyek/{$id}")->assertStatus(422);

        $this->assertStringContainsString('aktif', (string) $res->json('message'));
        $this->assertNull(DB::table('proyek')->where('id_proyek', $id)->value('dihapus_pada'));
    }

    public function test_proyek_aktif_dibatalkan_lalu_bisa_dihapus(): void
    {
        $id = $this->proyek('aktif');

        $this->patchJson("/api/proyek/{$id}/status", ['status' => 'batal'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'batal');

        $this->deleteJson("/api/proyek/{$id}")->assertStatus(200);

        $this->assertNotNull(DB::table('proyek')->where('id_proyek', $id)->value('dihapus_pada'));
    }

    public function test_proyek_batal_yang_masih_punya_penugasan_tetap_ditolak_dihapus(): void
    {
        $id = $this->proyek('aktif');
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $id,
            'status' => 'selesai', 'sumber' => 'internal', 'dibuat_pada' => now(),
        ]);

        $this->patchJson("/api/proyek/{$id}/status", ['status' => 'batal'])->assertStatus(200);
        $res = $this->deleteJson("/api/proyek/{$id}")->assertStatus(422);

        $this->assertStringContainsString('penugasan', (string) $res->json('message'));
        $this->assertNull(DB::table('proyek')->where('id_proyek', $id)->value('dihapus_pada'));
    }

    public function test_proyek_selesai_tidak_bisa_dihapus(): void
    {
        $id = $this->proyek('selesai');

        $this->deleteJson("/api/proyek/{$id}")->assertStatus(422);
        $this->assertNull(DB::table('proyek')->where('id_proyek', $id)->value('dihapus_pada'));
    }
}
