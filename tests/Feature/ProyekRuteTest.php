<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CRUD rute milik proyek (rate card) — lihat
 * docs/superpowers/specs/2026-08-17-rute-proyek-design.md §3.2
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

    private function makeProyek(string $tipeHarga = 'per_rit'): string
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Rute Test',
            'tipe_harga'    => $tipeHarga,
        ]);
        return $proyek->id_proyek;
    }

    private function makePenawaranDisetujui(string $idProyek): void
    {
        DB::table('penawaran')->insert([
            'id_penawaran'    => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => DB::table('proyek')->where('id_proyek', $idProyek)->value('id_klien'),
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Disetujui',
            'status'          => 'disetujui',
            'id_proyek'       => $idProyek,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
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

    public function test_index_mengembalikan_rute_milik_proyek_dengan_estimasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'uang_jalan'         => 150000,
            'estimasi_tol'       => 50000,
            'estimasi_bbm'       => 300000,
            'estimasi_biaya_lain'=> 25000,
        ]);

        $res = $this->getJson("/api/proyek/{$idProyek}/rute");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Jakarta - Semarang', $res->json('data.0.nama_rute'));
        $this->assertSame('CDD', $res->json('data.0.nama_jenis'));
        $this->assertEquals(525000, $res->json('data.0.estimasi_biaya'));
    }

    public function test_store_berhasil_menambah_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
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
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
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
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(201)->assertJsonPath('data.harga_penawaran', null);
    }

    public function test_store_menyimpan_uang_jalan_dan_estimasi_ops(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'             => $this->makeRute(),
            'id_jenis_kendaraan'  => $this->makeJenisKendaraan(),
            'uang_jalan'          => 175000,
            'estimasi_tol'        => 60000,
            'estimasi_bbm'        => 350000,
            'estimasi_biaya_lain' => 30000,
        ]);

        $res->assertStatus(201);
        $this->assertEquals(175000, $res->json('data.uang_jalan'));
        $this->assertEquals(60000, $res->json('data.estimasi_tol'));
        $this->assertEquals(350000, $res->json('data.estimasi_bbm'));
        $this->assertEquals(30000, $res->json('data.estimasi_biaya_lain'));
        $this->assertEquals(615000, $res->json('data.estimasi_biaya'));
    }

    public function test_update_mengubah_harga_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga_penawaran'    => 500000,
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'harga_penawaran' => 900000,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(900000, $res->json('data.harga_penawaran'));
    }

    public function test_update_mengubah_uang_jalan_dan_estimasi_ops(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'uang_jalan'   => 200000,
            'estimasi_tol' => 70000,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(200000, $res->json('data.uang_jalan'));
        $this->assertEquals(70000, $res->json('data.estimasi_tol'));
    }

    public function test_store_dengan_id_rute_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => (string) Str::uuid(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(404);
    }

    public function test_store_dengan_id_jenis_kendaraan_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);
    }

    public function test_estimasi_biaya_null_saat_komponen_kosong_semua(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res = $this->getJson("/api/proyek/{$idProyek}/rute");

        $res->assertStatus(200)->assertJsonPath('data.0.estimasi_biaya', null);
    }

    public function test_estimasi_biaya_terhitung_saat_sebagian_komponen_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'estimasi_tol'       => 50000,
            'uang_jalan'         => 150000,
        ]);

        $res = $this->getJson("/api/proyek/{$idProyek}/rute");

        $res->assertStatus(200);
        $this->assertEquals(200000, $res->json('data.0.estimasi_biaya'));
    }

    public function test_update_berhasil_mengubah_keterangan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'keterangan' => 'Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.keterangan', 'Diperbarui');
    }

    public function test_update_id_rute_ke_yang_tidak_ada_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'id_rute' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);
    }

    public function test_destroy_berhasil_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->deleteJson("/api/proyek/{$idProyek}/rute/{$id}");

        $res->assertStatus(200);
        $this->assertDatabaseHas('proyek_rute', ['id_proyek_rute' => $id]);
        $this->assertNotNull(DB::table('proyek_rute')->where('id_proyek_rute', $id)->value('dihapus_pada'));
        $this->getJson("/api/proyek/{$idProyek}/rute")->assertJsonCount(0, 'data');
    }

    public function test_update_rute_milik_proyek_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyekA = $this->makeProyek();
        $idProyekB = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyekA}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyekB}/rute/{$id}", [
            'keterangan' => 'Coba ubah dari proyek lain',
        ]);

        $res->assertStatus(404);
    }

    public function test_destroy_rute_milik_proyek_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyekA = $this->makeProyek();
        $idProyekB = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyekA}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->deleteJson("/api/proyek/{$idProyekB}/rute/{$id}");

        $res->assertStatus(404);
        $this->assertDatabaseHas('proyek_rute', ['id_proyek_rute' => $id, 'dihapus_pada' => null]);
    }

    public function test_store_duplikat_rute_jenis_kendaraan_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ])->assertStatus(201);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('message', 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
        $this->assertSame(1, DB::table('proyek_rute')->where('id_proyek', $idProyek)->whereNull('dihapus_pada')->count());
    }

    public function test_store_rute_sama_jenis_kendaraan_berbeda_bukan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->assertStatus(201);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(201);
    }

    public function test_store_duplikat_dengan_jenis_kendaraan_null_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute' => $idRute,
        ])->assertStatus(201);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute' => $idRute,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('message', 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
    }

    public function test_store_jenis_kendaraan_null_dan_terisi_bukan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRute   = $this->makeRute();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute' => $idRute,
        ])->assertStatus(201);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(201);
    }

    public function test_store_rute_sama_di_proyek_berbeda_bukan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyekA = $this->makeProyek();
        $idProyekB = $this->makeProyek();
        $idRute    = $this->makeRute();
        $idJenis   = $this->makeJenisKendaraan();

        $this->postJson("/api/proyek/{$idProyekA}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ])->assertStatus(201);

        $res = $this->postJson("/api/proyek/{$idProyekB}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ]);

        $res->assertStatus(201);
    }

    public function test_update_ke_kombinasi_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idRuteA  = $this->makeRute();
        $idRuteB  = $this->makeRute();
        $idJenis  = $this->makeJenisKendaraan();

        $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute' => $idRuteA, 'id_jenis_kendaraan' => $idJenis,
        ])->assertStatus(201);
        $idKedua = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute' => $idRuteB, 'id_jenis_kendaraan' => $idJenis,
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$idKedua}", [
            'id_rute' => $idRuteA,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('message', 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
    }

    public function test_update_tanpa_ubah_kombinasi_tidak_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ])->json('data.id_proyek_rute');

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'harga_penawaran' => 850000,
        ]);

        $res->assertStatus(200);
    }

    public function test_store_rute_proyek_borongan_dengan_penawaran_disetujui_tetap_boleh_201(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek('borongan');
        $this->makePenawaranDisetujui($idProyek);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga_penawaran'    => 500000,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', ['id_proyek' => $idProyek]);
    }

    public function test_store_rute_proyek_per_rit_dengan_penawaran_disetujui_tetap_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek('per_rit');
        $this->makePenawaranDisetujui($idProyek);

        $res = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga_penawaran'    => 500000,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Harga terkunci — ubah lewat penawaran revisi');
    }

    public function test_update_harga_rute_proyek_borongan_dengan_penawaran_disetujui_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek('borongan');
        $id = $this->postJson("/api/proyek/{$idProyek}/rute", [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga_penawaran'    => 500000,
        ])->json('data.id_proyek_rute');
        $this->makePenawaranDisetujui($idProyek);

        $res = $this->putJson("/api/proyek/{$idProyek}/rute/{$id}", [
            'harga_penawaran' => 900000,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.harga_penawaran', 900000);
    }
}
