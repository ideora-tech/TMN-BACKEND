<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Lokasi\LokasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RuteLokasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLokasi(string $idPerusahaan, string $nama): LokasiModel
    {
        return LokasiModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nama_lokasi'   => $nama,
        ]);
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    public function test_membuat_rute_dengan_id_lokasi_mengisi_asal_tujuan_otomatis(): void
    {
        $this->actingAsRole('ADMIN');
        $asal = $this->makeLokasi(self::PERUSAHAAN_ID, 'Pelabuhan Tanjung Priok');
        $tujuan = $this->makeLokasi(self::PERUSAHAAN_ID, 'Gudang Cikarang');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute'         => 'RUT-LOK-1',
            'nama_rute'         => 'Rute Priok-Cikarang',
            'id_lokasi_asal'    => $asal->id_lokasi,
            'id_lokasi_tujuan'  => $tujuan->id_lokasi,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.asal', 'Pelabuhan Tanjung Priok')
            ->assertJsonPath('data.tujuan', 'Gudang Cikarang')
            ->assertJsonPath('data.id_lokasi_asal', $asal->id_lokasi)
            ->assertJsonPath('data.id_lokasi_tujuan', $tujuan->id_lokasi);

        $this->assertDatabaseHas('rute', [
            'kode_rute'        => 'RUT-LOK-1',
            'asal'             => 'Pelabuhan Tanjung Priok',
            'tujuan'           => 'Gudang Cikarang',
            'id_lokasi_asal'   => $asal->id_lokasi,
            'id_lokasi_tujuan' => $tujuan->id_lokasi,
        ]);
    }

    public function test_membuat_rute_dengan_id_lokasi_asal_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $lokasiLain = $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute'      => 'RUT-LOK-2',
            'nama_rute'      => 'Rute Gagal',
            'id_lokasi_asal' => $lokasiLain->id_lokasi,
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('rute', ['kode_rute' => 'RUT-LOK-2']);
    }

    public function test_membuat_rute_dengan_id_lokasi_tujuan_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $lokasiLain = $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute'        => 'RUT-LOK-3',
            'nama_rute'        => 'Rute Gagal Tujuan',
            'id_lokasi_tujuan' => $lokasiLain->id_lokasi,
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('rute', ['kode_rute' => 'RUT-LOK-3']);
    }

    public function test_membuat_rute_tanpa_id_lokasi_tetap_berhasil_seperti_lama(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'RUT-LAMA-1',
            'nama_rute' => 'Rute Lama',
            'asal'      => 'Jakarta',
            'tujuan'    => 'Bandung',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.asal', 'Jakarta')
            ->assertJsonPath('data.tujuan', 'Bandung')
            ->assertJsonPath('data.id_lokasi_asal', null)
            ->assertJsonPath('data.id_lokasi_tujuan', null);
    }

    public function test_update_rute_dengan_id_lokasi_mengisi_asal_tujuan_otomatis(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'RUT-UPD-1',
            'nama_rute' => 'Rute Update',
            'asal'      => 'Lama Asal',
            'tujuan'    => 'Lama Tujuan',
        ])->json('data');

        $lokasiBaru = $this->makeLokasi(self::PERUSAHAAN_ID, 'Terminal Baru');

        $res = $this->putJson("/api/v1/rute/{$rute['id_rute']}", [
            'id_lokasi_asal' => $lokasiBaru->id_lokasi,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.asal', 'Terminal Baru')
            ->assertJsonPath('data.id_lokasi_asal', $lokasiBaru->id_lokasi)
            ->assertJsonPath('data.tujuan', 'Lama Tujuan');
    }

    public function test_update_rute_dengan_id_lokasi_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'RUT-UPD-2',
            'nama_rute' => 'Rute Update Gagal',
            'asal'      => 'Lama Asal',
            'tujuan'    => 'Lama Tujuan',
        ])->json('data');

        $idPerusahaanLain = $this->makePerusahaanLain();
        $lokasiLain = $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->putJson("/api/v1/rute/{$rute['id_rute']}", [
            'id_lokasi_tujuan' => $lokasiLain->id_lokasi,
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseHas('rute', [
            'id_rute' => $rute['id_rute'],
            'tujuan'  => 'Lama Tujuan',
        ]);
    }

    public function test_update_rute_dengan_id_lokasi_null_tidak_menimpa_teks_asal(): void
    {
        $this->actingAsRole('ADMIN');
        $asal = $this->makeLokasi(self::PERUSAHAAN_ID, 'Terminal Utama');

        $rute = $this->postJson('/api/v1/rute', [
            'kode_rute'      => 'RUT-NULL-1',
            'nama_rute'      => 'Rute Null Test',
            'id_lokasi_asal' => $asal->id_lokasi,
            'tujuan'         => 'Tujuan Manual',
        ])->json('data');

        $res = $this->putJson("/api/v1/rute/{$rute['id_rute']}", [
            'id_lokasi_asal'   => null,
            'estimasi_jarak_km' => 99,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.asal', 'Terminal Utama')
            ->assertJsonPath('data.estimasi_jarak_km', 99);

        $this->assertDatabaseHas('rute', [
            'id_rute'        => $rute['id_rute'],
            'asal'           => 'Terminal Utama',
            'estimasi_jarak_km' => 99,
        ]);
    }
}
