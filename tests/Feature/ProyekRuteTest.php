<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CRUD rute milik proyek — lihat
 * docs/superpowers/specs/2026-07-19-relasi-proyek-rute-design.md
 */
class ProyekRuteTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Rute Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeProyek(): string
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Rute Test',
        ]);
        return $proyek->id_proyek;
    }

    private function makeRute(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Jakarta - Semarang',
            'asal'          => 'Jakarta',
            'tujuan'        => 'Semarang',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => 'CDD-' . Str::random(4),
            'nama_jenis'         => 'CDD',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeTarifRute(string $idRute, string $idJenis, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('tarif_rute')->insert(array_merge([
            'id_tarif_rute'       => $id,
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'id_rute'             => $idRute,
            'id_jenis_kendaraan'  => $idJenis,
            'harga'               => 1000000,
            'estimasi_tol'        => 50000,
            'estimasi_bbm'        => 300000,
            'estimasi_uang_jalan' => 150000,
            'estimasi_biaya_lain' => 25000,
            'tanggal_mulai'       => now()->subDays(10)->toDateString(),
            'aktif'               => 1,
            'dibuat_pada'         => now(),
        ], $override));
        return $id;
    }

    public function test_index_mengembalikan_rute_milik_proyek_dengan_estimasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();
        $idTarif  = $this->makeTarifRute($idRute, $idJenis);

        $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_tarif_rute'      => $idTarif,
        ]);

        $res = $this->getJson("/api/v1/proyek/{$idProyek}/rute");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Jakarta - Semarang', $res->json('data.0.nama_rute'));
        $this->assertSame('CDD', $res->json('data.0.nama_jenis'));
        $this->assertEquals(525000, $res->json('data.0.estimasi_biaya'));
    }

    public function test_store_berhasil_menambah_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'keterangan'         => 'Rute utama',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_rute', $idRute)
            ->assertJsonPath('data.keterangan', 'Rute utama')
            ->assertJsonPath('data.estimasi_biaya', null);

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek' => $idProyek,
            'id_rute'   => $idRute,
        ]);
    }

    public function test_store_menyimpan_harga_penawaran(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran'    => 750000,
        ]);

        $res->assertStatus(201);
        $this->assertEquals(750000, $res->json('data.harga_penawaran'));
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'        => $idProyek,
            'id_rute'          => $idRute,
            'harga_penawaran'  => 750000,
        ]);
    }

    public function test_store_tanpa_harga_penawaran_null(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(201)->assertJsonPath('data.harga_penawaran', null);
    }

    public function test_update_mengubah_harga_penawaran(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga_penawaran'    => 500000,
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/v1/proyek/{$idProyek}/rute/{$id}", [
            'harga_penawaran' => 900000,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(900000, $res->json('data.harga_penawaran'));
    }

    public function test_store_dengan_id_rute_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => (string) Str::uuid(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(404);
    }

    public function test_store_dengan_id_jenis_kendaraan_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);
    }

    public function test_estimasi_biaya_null_saat_tarif_terhapus(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();
        $idTarif  = $this->makeTarifRute($idRute, $idJenis);
        DB::table('tarif_rute')->where('id_tarif_rute', $idTarif)->update(['dihapus_pada' => now()]);

        $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_tarif_rute'      => $idTarif,
        ]);

        $res = $this->getJson("/api/v1/proyek/{$idProyek}/rute");

        $res->assertStatus(200)->assertJsonPath('data.0.estimasi_biaya', null);
    }

    public function test_estimasi_biaya_null_saat_komponen_kosong_semua(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();
        $idTarif  = $this->makeTarifRute($idRute, $idJenis, [
            'estimasi_tol' => null, 'estimasi_bbm' => null,
            'estimasi_uang_jalan' => null, 'estimasi_biaya_lain' => null,
        ]);

        $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_tarif_rute'      => $idTarif,
        ]);

        $res = $this->getJson("/api/v1/proyek/{$idProyek}/rute");

        $res->assertStatus(200)->assertJsonPath('data.0.estimasi_biaya', null);
    }

    public function test_estimasi_biaya_terhitung_saat_sebagian_komponen_null(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();
        $idTarif  = $this->makeTarifRute($idRute, $idJenis, [
            'estimasi_tol' => 50000, 'estimasi_bbm' => null,
            'estimasi_uang_jalan' => 150000, 'estimasi_biaya_lain' => null,
        ]);

        $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_tarif_rute'      => $idTarif,
        ]);

        $res = $this->getJson("/api/v1/proyek/{$idProyek}/rute");

        $res->assertStatus(200);
        $this->assertEquals(200000, $res->json('data.0.estimasi_biaya'));
    }

    public function test_update_berhasil_mengubah_keterangan(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/v1/proyek/{$idProyek}/rute/{$id}", [
            'keterangan' => 'Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.keterangan', 'Diperbarui');
    }

    public function test_update_id_rute_ke_yang_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/v1/proyek/{$idProyek}/rute/{$id}", [
            'id_rute' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);
    }

    public function test_destroy_berhasil_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->deleteJson("/api/v1/proyek/{$idProyek}/rute/{$id}");

        $res->assertStatus(200);
        $this->assertDatabaseHas('proyek_rute', ['id_proyek_rute' => $id]);
        $this->assertNotNull(DB::table('proyek_rute')->where('id_proyek_rute', $id)->value('dihapus_pada'));
        $this->getJson("/api/v1/proyek/{$idProyek}/rute")->assertJsonCount(0, 'data');
    }

    public function test_update_rute_milik_proyek_lain_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyekA = $this->makeProyek();
        $idProyekB = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyekA}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/v1/proyek/{$idProyekB}/rute/{$id}", [
            'keterangan' => 'Coba ubah dari proyek lain',
        ]);

        $res->assertStatus(404);
    }

    public function test_destroy_rute_milik_proyek_lain_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idProyekA = $this->makeProyek();
        $idProyekB = $this->makeProyek();
        $id = $this->postJson("/api/v1/proyek/{$idProyekA}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->deleteJson("/api/v1/proyek/{$idProyekB}/rute/{$id}");

        $res->assertStatus(404);
        $this->assertDatabaseHas('proyek_rute', ['id_proyek_rute' => $id, 'dihapus_pada' => null]);
    }
}
