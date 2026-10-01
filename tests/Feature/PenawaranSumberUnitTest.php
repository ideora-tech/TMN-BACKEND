<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranSumberUnitTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Sumber Unit', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_rute' => 'RT-' . Str::random(6),
            'nama_rute' => 'Jakarta - Bandung', 'asal' => 'Jakarta', 'tujuan' => 'Bandung', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenis(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'CDD-' . Str::random(4), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenawaran(string $status = 'draft', ?string $idProyek = null, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => $idPerusahaan, 'id_klien' => $this->makeKlien($idPerusahaan),
            'nomor_penawaran' => 'PNW-' . Str::random(6), 'judul' => 'Penawaran Sumber Unit', 'status' => $status,
            'tipe_harga' => 'per_rit', 'id_proyek' => $idProyek, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyek(): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->makeKlien(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Sumber', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_item_penawaran_menyimpan_pembagian_unit_aset_dan_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'judul'    => 'Penawaran Campuran',
            'id_klien' => $this->makeKlien(),
            'items'    => [[
                'id_rute' => $this->makeRute(), 'id_jenis_kendaraan' => $this->makeJenis(),
                'harga_satuan' => 1000000, 'estimasi_ritase' => 2, 'unit_aset' => 3, 'unit_vendor' => 2,
            ]],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.items.0.unit_aset', 3)
            ->assertJsonPath('data.items.0.unit_vendor', 2);

        $this->postJson('/api/penawaran', [
            'judul' => 'Negatif', 'id_klien' => $this->makeKlien(),
            'items' => [['id_rute' => $this->makeRute(), 'id_jenis_kendaraan' => $this->makeJenis(), 'harga_satuan' => 1, 'unit_vendor' => -1]],
        ])->assertStatus(422);
    }

    public function test_permintaan_vendor_dari_penawaran_dan_filter(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenawaran = $this->makePenawaran('negosiasi');

        $res = $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $idPenawaran,
            'mekanisme'    => 'unit_only',
            'unit'         => [['id_jenis_kendaraan' => $this->makeJenis(), 'jumlah_unit' => 2]],
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.id_penawaran', $idPenawaran)
            ->assertJsonPath('data.id_proyek', null);
        $this->assertNotNull($res->json('data.nomor_penawaran'));

        $this->postJson('/api/permintaan-vendor', ['mekanisme' => 'unit_only', 'jumlah_unit' => 1])->assertStatus(201);

        $this->getJson("/api/permintaan-vendor?id_penawaran={$idPenawaran}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_permintaan_vendor_menolak_penawaran_ditolak_dan_milik_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $this->makePenawaran('ditolak'), 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $this->makePenawaran('draft', null, $idPerusahaanLain), 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(404);
    }

    public function test_penawaran_revisi_yang_sudah_berproyek_mengisi_proyek_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idPenawaran = $this->makePenawaran('draft', $idProyek);

        $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $idPenawaran, 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(201)->assertJsonPath('data.id_proyek', $idProyek);

        $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $idPenawaran, 'id_proyek' => $this->makeProyek(), 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(422);
    }

    public function test_update_tidak_bisa_melepas_proyek_dari_penawaran_dan_penawaran_tanpa_proyek_menolak_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idPermintaan = $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $this->makePenawaran('draft', $idProyek), 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(201)->json('data.id_permintaan');

        $this->putJson("/api/permintaan-vendor/{$idPermintaan}", ['id_proyek' => $this->makeProyek()])->assertStatus(422);
        $this->putJson("/api/permintaan-vendor/{$idPermintaan}", ['id_proyek' => null])
            ->assertOk()
            ->assertJsonPath('data.id_proyek', $idProyek);

        $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $this->makePenawaran('draft'), 'id_proyek' => $this->makeProyek(), 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(422);
    }

    public function test_penawaran_yang_dirujuk_permintaan_vendor_tidak_bisa_dihapus_dan_ajukan_ditolak_bila_penawaran_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenawaran = $this->makePenawaran('draft');
        $idPermintaan = $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $idPenawaran, 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(201)->json('data.id_permintaan');

        $this->deleteJson("/api/penawaran/{$idPenawaran}")->assertStatus(422);

        DB::table('penawaran')->where('id_penawaran', $idPenawaran)->update(['status' => 'ditolak']);
        $this->postJson("/api/permintaan-vendor/{$idPermintaan}/ajukan-approval")->assertStatus(422);
        $this->putJson("/api/permintaan-vendor/{$idPermintaan}", ['catatan' => 'tetap bisa diedit'])->assertOk();
    }

    public function test_penawaran_jadi_proyek_memindahkan_permintaan_dan_kontrak_vendor_ke_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenawaran = $this->makePenawaran('disetujui');

        $idPermintaan = $this->postJson('/api/permintaan-vendor', [
            'id_penawaran' => $idPenawaran, 'mekanisme' => 'unit_only', 'jumlah_unit' => 1,
        ])->assertStatus(201)->json('data.id_permintaan');

        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_vendor' => 'VDR-' . Str::random(6),
            'nama_vendor' => 'Vendor Sumber', 'dibuat_pada' => now(),
        ]);
        $idKontrak = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $idKontrak, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_kontrak' => 'KV-' . Str::random(6), 'mekanisme' => 'unit_only', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        DB::table('permintaan_vendor')->where('id_permintaan', $idPermintaan)->update([
            'id_kontrak_vendor' => $idKontrak, 'status' => 'dikontrakkan',
        ]);

        $idProyek = $this->postJson('/api/proyek', [
            'nama_proyek' => 'Proyek Dari Penawaran', 'id_penawaran' => $idPenawaran,
        ])->assertStatus(201)->json('data.id_proyek');

        $this->assertSame($idProyek, DB::table('permintaan_vendor')->where('id_permintaan', $idPermintaan)->value('id_proyek'));
        $this->assertSame($idProyek, DB::table('kontrak_vendor')->where('id_kontrak_vendor', $idKontrak)->value('id_proyek'));
    }
}
