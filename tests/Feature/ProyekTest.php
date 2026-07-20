<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeProyek(string $idKlien, string $kodeProyek): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => $kodeProyek,
            'nama_proyek'   => 'Proyek Existing',
        ]);
    }

    public function test_menolak_kode_proyek_duplikat_saat_membuat(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, '123');

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => '123',
            'nama_proyek' => 'Proyek Baru',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['kode_proyek']);
    }

    public function test_membuat_proyek_dengan_kode_unik_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-UNIK-1',
            'nama_proyek' => 'Proyek Baru',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode_proyek', 'PRJ-UNIK-1');
    }

    public function test_menolak_kode_proyek_duplikat_saat_update(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, 'KODE-A');
        $proyekB = $this->makeProyek($klien->id_klien, 'KODE-B');

        $res = $this->putJson("/api/v1/proyek/{$proyekB->id_proyek}", [
            'kode_proyek' => 'KODE-A',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['kode_proyek']);
    }

    public function test_update_proyek_dengan_kode_sendiri_tidak_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $proyek = $this->makeProyek($klien->id_klien, 'KODE-SENDIRI');

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}", [
            'kode_proyek' => 'KODE-SENDIRI',
            'nama_proyek' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_proyek', 'Nama Diperbarui');
    }

    private function makePenawaranDisetujui(string $idKlien): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Test Link Proyek',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    /**
     * Bug nyata: tombol "Buat Proyek" di halaman penawaran mengirim id_penawaran,
     * tapi backend tidak pernah menulis balik id_proyek ke penawaran — akibatnya
     * fitur estimasi otomatis (yang membaca penawaran.id_proyek) tidak pernah dapat data.
     */
    public function test_store_proyek_dengan_id_penawaran_menautkan_balik_ke_penawaran(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDisetujui($klien->id_klien);

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-DARI-PNW',
            'nama_proyek'  => 'Proyek Dari Penawaran',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idPenawaran,
            'id_proyek'    => $idProyek,
        ]);
    }

    public function test_store_proyek_tanpa_id_penawaran_tetap_berhasil_seperti_biasa(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-TANPA-PNW',
            'nama_proyek' => 'Proyek Manual',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode_proyek', 'PRJ-TANPA-PNW');
    }

    public function test_store_proyek_dengan_id_penawaran_tidak_ada_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-PNW-NGAWUR',
            'nama_proyek'  => 'Proyek Test',
            'id_penawaran' => (string) Str::uuid(),
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_penawaran']);
    }

    private function makeRuteUntukProyekTest(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Bandung - Cirebon',
            'asal'          => 'Bandung',
            'tujuan'        => 'Cirebon',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraanUntukProyekTest(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'CDD-' . Str::random(4),
            'nama_jenis'         => 'CDD',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_store_proyek_dari_penawaran_menyalin_item_ke_proyek_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = DB::table('penawaran')->insertGetId([
            'id_penawaran'    => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $klien->id_klien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Auto Copy Test',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ], 'id_penawaran');
        // insertGetId tidak cocok untuk PK string — ambil ulang id yang benar-benar dipakai:
        $idPenawaran = DB::table('penawaran')->where('nomor_penawaran', 'like', 'PNW-%')
            ->where('judul', 'Penawaran Auto Copy Test')->value('id_penawaran');

        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_penawaran'      => $idPenawaran,
            'id_rute'           => $idRute,
            'id_jenis_kendaraan'=> $idJenis,
            'id_tarif_rute'     => null,
            'harga_satuan'      => 500000,
            'estimasi_ritase'   => 1,
            'subtotal'          => 500000,
            'dibuat_pada'       => now(),
        ]);

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-AUTOCOPY',
            'nama_proyek'  => 'Proyek Auto Copy Test',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'          => $idProyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ]);
        $this->assertSame(1, DB::table('proyek_rute')->where('id_proyek', $idProyek)->count());
    }

    public function test_store_proyek_tanpa_id_penawaran_tidak_membuat_proyek_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-NOCOPY',
            'nama_proyek' => 'Proyek Tanpa Penawaran',
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertSame(0, DB::table('proyek_rute')->where('id_proyek', $idProyek)->count());
    }

    public function test_store_proyek_dengan_id_penawaran_milik_perusahaan_lain_tidak_menautkan(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $idPenawaranLain = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idPenawaranLain,
            'id_perusahaan'   => $idPerusahaanLain,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Perusahaan Lain',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-TENANT-LAIN',
            'nama_proyek'  => 'Proyek Test Tenant Lain',
            'id_penawaran' => $idPenawaranLain,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idPenawaranLain,
            'id_proyek'    => null,
        ]);
        $this->assertSame(0, DB::table('proyek_rute')->where('id_proyek', $idProyek)->count());
    }

    public function test_store_proyek_dengan_rute_manual_membuat_baris_proyek_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RUTE-MANUAL',
            'nama_proyek' => 'Proyek Rute Manual',
            'rute' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_penawaran' => 600000],
            ],
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'       => $idProyek,
            'id_rute'         => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran' => 600000,
        ]);
    }

    public function test_store_proyek_dengan_rute_invalid_gagal_total_tidak_ada_yang_tersimpan(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RUTE-INVALID',
            'nama_proyek' => 'Proyek Rute Invalid',
            'rute' => [
                ['id_rute' => $this->makeRuteUntukProyekTest(), 'id_jenis_kendaraan' => $this->makeJenisKendaraanUntukProyekTest()],
                ['id_rute' => (string) Str::uuid(), 'id_jenis_kendaraan' => $this->makeJenisKendaraanUntukProyekTest()],
            ],
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('proyek', ['kode_proyek' => 'PRJ-RUTE-INVALID']);
        $this->assertSame(0, DB::table('proyek_rute')->count());
    }

    public function test_store_proyek_dari_penawaran_menyalin_harga_penawaran_dari_harga_satuan(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idPenawaran,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $klien->id_klien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Harga Penawaran Test',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_penawaran'      => $idPenawaran,
            'id_rute'           => $idRute,
            'id_jenis_kendaraan'=> $idJenis,
            'id_tarif_rute'     => null,
            'harga_satuan'      => 725000,
            'estimasi_ritase'   => 1,
            'subtotal'          => 725000,
            'dibuat_pada'       => now(),
        ]);

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-HARGA-PNW',
            'nama_proyek'  => 'Proyek Harga Dari Penawaran',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'        => $idProyek,
            'id_rute'          => $idRute,
            'harga_penawaran'  => 725000,
        ]);
    }
}
