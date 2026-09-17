<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SparepartFotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeSparepart(string $idPerusahaan = self::PERUSAHAAN_ID, string $kode = 'SP-001'): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode'          => $kode,
            'nama'          => 'Filter Oli',
            'serial_number' => 'FO-1',
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_upload_banyak_foto_tampil_di_show_dan_list(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeSparepart();

        $res = $this->postJson("/api/sparepart/{$id}/foto", [
            'foto' => [UploadedFile::fake()->image('depan.jpg'), UploadedFile::fake()->image('samping.png')],
        ]);

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data.foto')
            ->assertJsonPath('data.foto.0.nama_asli', 'depan.jpg');
        $this->assertStringContainsString('/storage/', (string) $res->json('data.foto.0.url_file'));

        $this->getJson("/api/sparepart/{$id}")->assertStatus(200)->assertJsonCount(2, 'data.foto');
        $this->getJson('/api/sparepart')->assertStatus(200)->assertJsonCount(2, 'data.0.foto');
    }

    public function test_upload_ditolak_bila_bukan_gambar_atau_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeSparepart();

        $this->postJson("/api/sparepart/{$id}/foto", [])->assertStatus(422);
        $this->postJson("/api/sparepart/{$id}/foto", [
            'foto' => [UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')],
        ])->assertStatus(422);
    }

    public function test_total_foto_lebih_dari_sepuluh_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeSparepart();

        $delapan = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 8));
        $this->postJson("/api/sparepart/{$id}/foto", ['foto' => $delapan])->assertStatus(200);

        $tiga = array_map(fn ($i) => UploadedFile::fake()->image("g{$i}.jpg"), range(1, 3));
        $this->postJson("/api/sparepart/{$id}/foto", ['foto' => $tiga])->assertStatus(422);

        $this->assertSame(8, DB::table('sparepart_foto')->where('id_sparepart', $id)->count());
    }

    public function test_hapus_foto_soft_delete_dan_tidak_tampil_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeSparepart();
        $res = $this->postJson("/api/sparepart/{$id}/foto", ['foto' => [UploadedFile::fake()->image('a.jpg')]]);
        $idFoto = $res->json('data.foto.0.id_foto');

        $this->deleteJson("/api/sparepart/{$id}/foto/{$idFoto}")->assertStatus(200);
        $this->getJson("/api/sparepart/{$id}")->assertJsonCount(0, 'data.foto');
        $this->assertNotNull(DB::table('sparepart_foto')->where('id_foto', $idFoto)->value('dihapus_pada'));

        $this->deleteJson("/api/sparepart/{$id}/foto/{$idFoto}")->assertStatus(404);
    }

    public function test_sparepart_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idLain = $this->makeSparepart($idPerusahaanLain, 'SP-LAIN');
        $idFotoLain = (string) Str::uuid();
        DB::table('sparepart_foto')->insert([
            'id_foto' => $idFotoLain, 'id_sparepart' => $idLain, 'url_file' => 'sparepart/x.jpg', 'nama_asli' => 'x.jpg', 'dibuat_pada' => now(),
        ]);

        $this->postJson("/api/sparepart/{$idLain}/foto", ['foto' => [UploadedFile::fake()->image('a.jpg')]])->assertStatus(404);
        $this->deleteJson("/api/sparepart/{$idLain}/foto/{$idFotoLain}")->assertStatus(404);

        $idSendiri = $this->makeSparepart();
        $this->deleteJson("/api/sparepart/{$idSendiri}/foto/{$idFotoLain}")->assertStatus(404);
    }
    public function test_urutan_foto_mengikuti_urutan_unggah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makeSparepart();

        $this->postJson("/api/sparepart/{$id}/foto", [
            'foto' => [
                UploadedFile::fake()->image('satu.jpg'),
                UploadedFile::fake()->image('dua.jpg'),
                UploadedFile::fake()->image('tiga.jpg'),
            ],
        ])->assertStatus(200);

        $this->postJson("/api/sparepart/{$id}/foto", [
            'foto' => [UploadedFile::fake()->image('empat.jpg')],
        ])->assertStatus(200);

        $res = $this->getJson("/api/sparepart/{$id}");
        $res->assertStatus(200);

        $urutan = array_column($res->json('data.foto'), 'nama_asli');
        $this->assertSame(['satu.jpg', 'dua.jpg', 'tiga.jpg', 'empat.jpg'], $urutan);
    }
}
