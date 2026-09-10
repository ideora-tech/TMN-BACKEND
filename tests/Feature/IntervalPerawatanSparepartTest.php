<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntervalPerawatanSparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => strtoupper($nama) . '-' . Str::random(4), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'satuan' => 'liter', 'harga_standar' => 60000, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_simpan_interval_dengan_sparepart_tersimpan_di_paket_dan_muncul_di_resource(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin Diesel 15W-40');
        $idFilter = $this->makeSparepart('Filter Oli');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan,
            'interval_km'        => 10000,
            'interval_bulan'     => 6,
            'sparepart'          => [
                ['id_sparepart' => $idOli, 'qty_standar' => 6],
                ['id_sparepart' => $idFilter, 'qty_standar' => 1],
            ],
        ]);

        $res->assertStatus(201);
        $sparepart = $res->json('data.sparepart');
        $this->assertCount(2, $sparepart);
        $this->assertSame('Filter Oli', $sparepart[0]['nama_sparepart']);
        $this->assertSame(1, $sparepart[0]['qty_standar']);
        $this->assertSame('Oli Mesin Diesel 15W-40', $sparepart[1]['nama_sparepart']);
        $this->assertSame(6, $sparepart[1]['qty_standar']);

        $id = $res->json('data.id_interval_perawatan');
        $this->assertDatabaseHas('interval_perawatan_sparepart', [
            'id_interval_perawatan' => $id,
            'id_sparepart'          => $idOli,
            'qty_standar'           => 6,
        ]);

        $detail = $this->getJson("/api/interval-perawatan/{$id}");
        $detail->assertStatus(200);
        $this->assertCount(2, $detail->json('data.sparepart'));
    }

    public function test_interval_tanpa_sparepart_mengembalikan_array_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_km'        => 10000,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.sparepart', []);
    }

    public function test_update_mengganti_daftar_part(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin');
        $idFilter = $this->makeSparepart('Filter Oli');
        $idBan = $this->makeSparepart('Ban Serep');

        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_km' => 10000,
            'sparepart' => [
                ['id_sparepart' => $idOli, 'qty_standar' => 6],
                ['id_sparepart' => $idFilter, 'qty_standar' => 1],
            ],
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$id}", [
            'sparepart' => [
                ['id_sparepart' => $idOli, 'qty_standar' => 8],
                ['id_sparepart' => $idBan, 'qty_standar' => 2],
            ],
        ]);

        $res->assertStatus(200);
        $sparepart = collect($res->json('data.sparepart'));
        $this->assertCount(2, $sparepart);
        $this->assertNull($sparepart->firstWhere('id_sparepart', $idFilter));
        $this->assertSame(8, $sparepart->firstWhere('id_sparepart', $idOli)['qty_standar']);
        $this->assertSame(2, $sparepart->firstWhere('id_sparepart', $idBan)['qty_standar']);

        $this->assertSoftDeleted('interval_perawatan_sparepart', [
            'id_interval_perawatan' => $id, 'id_sparepart' => $idFilter,
        ]);
    }

    public function test_update_dengan_sparepart_kosong_menghapus_semua_part(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin');

        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_km' => 10000,
            'sparepart' => [['id_sparepart' => $idOli, 'qty_standar' => 6]],
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$id}", ['sparepart' => []]);

        $res->assertStatus(200)->assertJsonPath('data.sparepart', []);
        $this->assertSoftDeleted('interval_perawatan_sparepart', [
            'id_interval_perawatan' => $id, 'id_sparepart' => $idOli,
        ]);
    }

    public function test_update_tanpa_kirim_sparepart_tidak_mengubah_paket(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin');

        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_km' => 10000,
            'sparepart' => [['id_sparepart' => $idOli, 'qty_standar' => 6]],
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$id}", ['interval_km' => 12000]);

        $res->assertStatus(200)->assertJsonCount(1, 'data.sparepart');
        $this->assertSame(6, $res->json('data.sparepart.0.qty_standar'));
    }

    public function test_menolak_duplikat_sparepart_dalam_satu_paket(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSparepart = $this->makeSparepart('Oli Mesin');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_km'        => 10000,
            'sparepart'          => [
                ['id_sparepart' => $idSparepart, 'qty_standar' => 6],
                ['id_sparepart' => $idSparepart, 'qty_standar' => 2],
            ],
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Sparepart duplikat dalam satu paket');
    }

    public function test_menolak_sparepart_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_km'        => 10000,
            'sparepart'          => [
                ['id_sparepart' => $this->makeSparepart('Oli Mesin', $lain), 'qty_standar' => 6],
            ],
        ]);

        $res->assertStatus(404);
    }

    public function test_hapus_interval_ikut_menghapus_paketnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin');

        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_km' => 10000,
            'sparepart' => [['id_sparepart' => $idOli, 'qty_standar' => 6]],
        ])->json('data.id_interval_perawatan');

        $this->deleteJson("/api/interval-perawatan/{$id}")->assertStatus(200);

        $this->assertSoftDeleted('interval_perawatan_sparepart', [
            'id_interval_perawatan' => $id, 'id_sparepart' => $idOli,
        ]);
    }
}
