<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenawaranPerusahaanLain(string $status = 'draft'): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insertOrIgnore(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => $idPerusahaanLain,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Perusahaan Lain',
            'status'          => $status,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    public function test_show_penawaran_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePenawaranPerusahaanLain();

        $res = $this->getJson("/api/penawaran/{$idLain}");

        $res->assertStatus(404);
    }

    public function test_update_penawaran_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePenawaranPerusahaanLain();

        $res = $this->putJson("/api/penawaran/{$idLain}", [
            'judul' => 'Coba Ubah Punya Perusahaan Lain',
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idLain,
            'judul'        => 'Penawaran Perusahaan Lain',
        ]);
    }

    public function test_destroy_penawaran_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePenawaranPerusahaanLain();

        $res = $this->deleteJson("/api/penawaran/{$idLain}");

        $res->assertStatus(404);
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idLain,
            'dihapus_pada' => null,
        ]);
    }

    public function test_store_penawaran_tanpa_klien_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'judul' => 'Penawaran Tanpa Klien',
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('penawaran', ['judul' => 'Penawaran Tanpa Klien']);
    }

    public function test_store_penawaran_nomor_dari_request_diabaikan_pakai_nomor_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'id_klien' => $this->makeKlien(),
            'nomor_penawaran' => 'PNW-INPUT-BEBAS',
            'judul'           => 'Penawaran Nomor Otomatis',
        ]);

        $res->assertStatus(201);
        $this->assertNotSame('PNW-INPUT-BEBAS', $res->json('data.nomor_penawaran'));
        $this->assertMatchesRegularExpression('/^PNW-\d{6}-\d{4}$/', $res->json('data.nomor_penawaran'));
    }

    public function test_store_penawaran_tanpa_nomor_input_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'id_klien' => $this->makeKlien(),
            'judul' => 'Penawaran Tanpa Nomor Input',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.judul', 'Penawaran Tanpa Nomor Input');
        $this->assertMatchesRegularExpression('/^PNW-\d{6}-\d{4}$/', $res->json('data.nomor_penawaran'));
    }

    public function test_store_penawaran_nomor_otomatis_bertambah_urut(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $pertama = $this->postJson('/api/penawaran', [
            'id_klien' => $this->makeKlien(),
            'judul' => 'Penawaran Urut Satu',
        ])->json('data.nomor_penawaran');

        $kedua = $this->postJson('/api/penawaran', [
            'id_klien' => $this->makeKlien(),
            'judul' => 'Penawaran Urut Dua',
        ])->json('data.nomor_penawaran');

        $this->assertNotSame($pertama, $kedua);
    }
}
