<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penawaran\PenawaranModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranParameterTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Parameter', 'dibuat_pada' => now(),
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

    private function makeJenisKendaraan(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'CDD-' . Str::random(4), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_store_dan_update_parameter_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'judul'               => 'Penawaran Parameter',
            'id_klien'            => $this->makeKlien(),
            'biaya_overnight'     => 350000,
            'biaya_cancellation'  => 500000,
            'biaya_add_drop'      => 150000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.biaya_overnight', 350000)
            ->assertJsonPath('data.biaya_cancellation', 500000)
            ->assertJsonPath('data.biaya_add_drop', 150000)
            ->assertJsonPath('data.biaya_cross_cluster', null);

        $id = $res->json('data.id_penawaran');
        $this->putJson("/api/penawaran/{$id}", ['biaya_cross_cluster' => 250000, 'biaya_add_drop' => null])
            ->assertOk()
            ->assertJsonPath('data.biaya_cross_cluster', 250000)
            ->assertJsonPath('data.biaya_add_drop', null)
            ->assertJsonPath('data.biaya_overnight', 350000);

        $this->postJson('/api/penawaran', [
            'judul' => 'Negatif', 'id_klien' => $this->makeKlien(), 'biaya_overnight' => -1,
        ])->assertStatus(422);
    }

    public function test_pdf_menampilkan_parameter_yang_terisi_saja(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $penawaran = PenawaranModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->makeKlien(),
            'nomor_penawaran' => 'PNW-PARAM', 'judul' => 'PDF Parameter', 'status' => 'draft', 'tipe_harga' => 'per_rit',
            'biaya_overnight' => 350000, 'biaya_add_drop' => 150000, 'aktif' => 1,
        ]);

        $html = view('exports.penawaran', [
            'p' => $penawaran->fresh(), 'klien' => (object) ['nama_klien' => 'PT Klien'],
            'items' => collect(), 'logoBase64' => null, 'perusahaan' => (object) [],
        ])->render();

        $this->assertStringContainsString('PARAMETER PENAWARAN', $html);
        $this->assertStringContainsString('Overnight', $html);
        $this->assertStringContainsString('Rp 350.000', $html);
        $this->assertStringContainsString('per titik tambahan', $html);
        $this->assertStringNotContainsString('Cross Cluster', $html);

        $this->get("/api/penawaran/{$penawaran->id_penawaran}/pdf")->assertOk();
    }

    public function test_proyek_menampilkan_parameter_dari_penawaran_disetujui_dan_revisi_menyalinnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien();
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien, 'kode_proyek' => 'PRJ-' . Str::random(6),
            'nama_proyek' => 'Proyek Parameter', 'status' => 'aktif', 'tipe_harga' => 'per_rit',
        ]);
        DB::table('penawaran')->insert([
            'id_penawaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(6), 'judul' => 'Induk', 'status' => 'disetujui', 'tipe_harga' => 'per_rit',
            'id_proyek' => $proyek->id_proyek, 'aktif' => 1, 'dibuat_pada' => now(),
            'biaya_overnight' => 300000, 'biaya_cross_cluster' => 200000,
        ]);

        $this->getJson("/api/proyek/{$proyek->id_proyek}")
            ->assertOk()
            ->assertJsonCount(2, 'data.parameter_penawaran')
            ->assertJsonPath('data.parameter_penawaran.0.label', 'Overnight')
            ->assertJsonPath('data.parameter_penawaran.0.nilai', 300000)
            ->assertJsonPath('data.parameter_penawaran.1.satuan', 'per trip');

        $this->postJson("/api/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [['id_rute' => $this->makeRute(), 'id_jenis_kendaraan' => $this->makeJenisKendaraan(), 'harga_satuan' => 600000, 'estimasi_ritase' => 1]],
        ])->assertStatus(201)
            ->assertJsonPath('data.biaya_overnight', 300000)
            ->assertJsonPath('data.biaya_cross_cluster', 200000)
            ->assertJsonPath('data.biaya_add_drop', null);
    }
}
