<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranLampiranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeKlien(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenawaran(string $idPerusahaan = self::PERUSAHAAN_ID, string $status = 'draft'): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => $idPerusahaan,
            'id_klien'        => $this->makeKlien($idPerusahaan),
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Test',
            'status'          => $status,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    public function test_upload_banyak_lampiran_tampil_di_show(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran();

        $res = $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('foto.jpg'), UploadedFile::fake()->create('po-klien.pdf', 100, 'application/pdf')],
        ]);

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data.lampiran')
            ->assertJsonPath('data.lampiran.0.nama_asli', 'foto.jpg')
            ->assertJsonPath('data.lampiran.1.nama_asli', 'po-klien.pdf');
        $this->assertStringContainsString('/storage/', (string) $res->json('data.lampiran.0.url_file'));

        $this->getJson("/api/penawaran/{$id}")->assertStatus(200)->assertJsonCount(2, 'data.lampiran');
    }

    public function test_upload_ditolak_bila_kosong_atau_format_tidak_didukung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran();

        $this->postJson("/api/penawaran/{$id}/lampiran", [])->assertStatus(422);
        $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [UploadedFile::fake()->create('skrip.exe', 100, 'application/octet-stream')],
        ])->assertStatus(422);
    }

    public function test_total_lampiran_lebih_dari_sepuluh_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran();

        $delapan = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 8));
        $this->postJson("/api/penawaran/{$id}/lampiran", ['lampiran' => $delapan])->assertStatus(200);

        $tiga = array_map(fn ($i) => UploadedFile::fake()->image("g{$i}.jpg"), range(1, 3));
        $this->postJson("/api/penawaran/{$id}/lampiran", ['lampiran' => $tiga])->assertStatus(422);

        $this->assertSame(8, DB::table('penawaran_lampiran')->where('id_penawaran', $id)->count());
    }

    public function test_hapus_lampiran_soft_delete_dan_tidak_tampil_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran();
        $res = $this->postJson("/api/penawaran/{$id}/lampiran", ['lampiran' => [UploadedFile::fake()->image('a.jpg')]]);
        $idLampiran = $res->json('data.lampiran.0.id_lampiran');

        $this->deleteJson("/api/penawaran/{$id}/lampiran/{$idLampiran}")->assertStatus(200);
        $this->getJson("/api/penawaran/{$id}")->assertJsonCount(0, 'data.lampiran');
        $this->assertNotNull(DB::table('penawaran_lampiran')->where('id_lampiran', $idLampiran)->value('dihapus_pada'));

        $this->deleteJson("/api/penawaran/{$id}/lampiran/{$idLampiran}")->assertStatus(404);
    }

    public function test_penawaran_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idLain = $this->makePenawaran($idPerusahaanLain);
        $idLampiranLain = (string) Str::uuid();
        DB::table('penawaran_lampiran')->insert([
            'id_lampiran' => $idLampiranLain, 'id_penawaran' => $idLain, 'url_file' => 'penawaran/x.jpg', 'nama_asli' => 'x.jpg', 'dibuat_pada' => now(),
        ]);

        $this->postJson("/api/penawaran/{$idLain}/lampiran", ['lampiran' => [UploadedFile::fake()->image('a.jpg')]])->assertStatus(404);
        $this->deleteJson("/api/penawaran/{$idLain}/lampiran/{$idLampiranLain}")->assertStatus(404);

        $idSendiri = $this->makePenawaran();
        $this->deleteJson("/api/penawaran/{$idSendiri}/lampiran/{$idLampiranLain}")->assertStatus(404);
    }

    public function test_upload_ditolak_saat_penawaran_berstatus_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran(status: 'ditolak');

        $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('penawaran_lampiran')->where('id_penawaran', $id)->count());
    }

    public function test_upload_tetap_boleh_saat_penawaran_sudah_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran(status: 'disetujui');

        $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(200)->assertJsonCount(1, 'data.lampiran');
    }

    public function test_hapus_ditolak_saat_penawaran_sudah_disetujui_atau_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idDisetujui = $this->makePenawaran(status: 'draft');
        $idLampiran = $this->postJson("/api/penawaran/{$idDisetujui}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('a.jpg')],
        ])->json('data.lampiran.0.id_lampiran');
        DB::table('penawaran')->where('id_penawaran', $idDisetujui)->update(['status' => 'disetujui']);

        $this->deleteJson("/api/penawaran/{$idDisetujui}/lampiran/{$idLampiran}")->assertStatus(422);
        $this->assertNull(DB::table('penawaran_lampiran')->where('id_lampiran', $idLampiran)->value('dihapus_pada'));

        $idDitolak = $this->makePenawaran(status: 'draft');
        $idLampiranDitolak = $this->postJson("/api/penawaran/{$idDitolak}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('b.jpg')],
        ])->json('data.lampiran.0.id_lampiran');
        DB::table('penawaran')->where('id_penawaran', $idDitolak)->update(['status' => 'ditolak']);

        $this->deleteJson("/api/penawaran/{$idDitolak}/lampiran/{$idLampiranDitolak}")->assertStatus(422);
    }

    public function test_upload_dan_hapus_tetap_boleh_saat_menunggu_approval_terkirim_atau_negosiasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        foreach (['menunggu_approval', 'terkirim', 'negosiasi'] as $status) {
            $id = $this->makePenawaran(status: $status);

            $res = $this->postJson("/api/penawaran/{$id}/lampiran", [
                'lampiran' => [UploadedFile::fake()->image('a.jpg')],
            ]);
            $res->assertStatus(200);
            $idLampiran = $res->json('data.lampiran.0.id_lampiran');

            $this->deleteJson("/api/penawaran/{$id}/lampiran/{$idLampiran}")->assertStatus(200);
        }
    }

    public function test_urutan_lampiran_mengikuti_urutan_unggah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePenawaran();

        $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [
                UploadedFile::fake()->image('satu.jpg'),
                UploadedFile::fake()->image('dua.jpg'),
            ],
        ])->assertStatus(200);

        $this->postJson("/api/penawaran/{$id}/lampiran", [
            'lampiran' => [UploadedFile::fake()->image('tiga.jpg')],
        ])->assertStatus(200);

        $res = $this->getJson("/api/penawaran/{$id}");
        $res->assertStatus(200);

        $urutan = array_column($res->json('data.lampiran'), 'nama_asli');
        $this->assertSame(['satu.jpg', 'dua.jpg', 'tiga.jpg'], $urutan);
    }
}
