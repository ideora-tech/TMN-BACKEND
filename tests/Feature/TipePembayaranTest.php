<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TipePembayaranTest extends TestCase
{
    use RefreshDatabase;

    private function makeTipePembayaran(string $idPerusahaan, string $kode, string $nama, bool $aktif = true): string
    {
        $id = (string) Str::uuid();
        DB::table('tipe_pembayaran')->insert([
            'id_tipe_pembayaran' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_tipe' => $kode, 'nama_tipe' => $nama, 'aktif' => $aktif ? 1 : 0, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_index_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'full_payment', 'Full Payment');
        $perusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $perusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now(),
        ]);
        $this->makeTipePembayaran($perusahaanLain, 'cicilan', 'Cicilan');

        $res = $this->getJson('/api/tipe-pembayaran');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('full_payment', $res->json('data.0.kode_tipe'));
    }

    public function test_opsi_aktif_hanya_mengembalikan_yang_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'full_payment', 'Full Payment', true);
        $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'nonaktif', 'Nonaktif', false);

        $res = $this->getJson('/api/tipe-pembayaran/opsi-aktif');

        $res->assertStatus(200);
        $kode = collect($res->json('data'))->pluck('kode_tipe')->all();
        $this->assertSame(['full_payment'], $kode);
    }

    public function test_store_tipe_pembayaran_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/tipe-pembayaran', [
            'kode_tipe' => 'termin_3x',
            'nama_tipe' => 'Termin 3x',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode_tipe', 'termin_3x')
            ->assertJsonPath('data.nama_tipe', 'Termin 3x')
            ->assertJsonPath('data.aktif', true);
    }

    public function test_store_kode_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'dp', 'DP');

        $res = $this->postJson('/api/tipe-pembayaran', [
            'kode_tipe' => 'dp',
            'nama_tipe' => 'Down Payment',
        ]);

        $res->assertStatus(409);
    }

    public function test_update_tipe_pembayaran_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'dp', 'DP');

        $res = $this->putJson("/api/tipe-pembayaran/{$id}", [
            'nama_tipe' => 'Down Payment',
            'aktif'     => false,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_tipe', 'Down Payment')
            ->assertJsonPath('data.aktif', false);
    }

    public function test_destroy_tipe_pembayaran_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeTipePembayaran(self::PERUSAHAAN_ID, 'dp', 'DP');

        $this->deleteJson("/api/tipe-pembayaran/{$id}")->assertStatus(200);

        $this->assertNotNull(DB::table('tipe_pembayaran')->where('id_tipe_pembayaran', $id)->value('dihapus_pada'));
        $this->getJson("/api/tipe-pembayaran/{$id}")->assertStatus(404);
    }

    public function test_manager_tidak_bisa_tambah_tipe_pembayaran(): void
    {
        $this->actingAsRole('MANAGER');

        $res = $this->postJson('/api/tipe-pembayaran', [
            'kode_tipe' => 'termin_3x',
            'nama_tipe' => 'Termin 3x',
        ]);

        $res->assertStatus(403);
    }
}
