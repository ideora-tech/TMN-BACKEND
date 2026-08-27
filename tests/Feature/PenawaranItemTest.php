<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranItemTest extends TestCase
{
    use RefreshDatabase;

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

    private function payloadPenawaran(array $items): array
    {
        return [
            'nomor_penawaran' => 'PNW-' . Str::random(6),
            'id_klien'        => $this->makeKlien(),
            'judul'           => 'Rate Card Test',
            'items'           => $items,
        ];
    }

    public function test_store_dengan_items_menghitung_nilai_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 750000,
                'estimasi_ritase'    => 2,
            ],
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 500000,
            ],
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.nilai_penawaran', 2000000); // 750rb*2 + 500rb*1

        $this->assertCount(2, $res->json('data.items'));
        $this->assertSame('Jakarta - Semarang', $res->json('data.items.0.nama_rute'));
        $this->assertSame(1500000, $res->json('data.items.0.subtotal'));
        $this->assertDatabaseCount('penawaran_item', 2);
    }

    public function test_show_memuat_items(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 750000,
            ],
        ]))->json('data.id_penawaran');

        $res = $this->getJson("/api/penawaran/{$id}");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.items'));
        $this->assertSame('CDD', $res->json('data.items.0.nama_jenis'));
    }

    public function test_update_mengganti_semua_items(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 750000,
            ],
        ]))->json('data.id_penawaran');

        $res = $this->putJson("/api/penawaran/{$id}", [
            'items' => [
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                    'harga_satuan'       => 900000,
                    'estimasi_ritase'    => 3,
                ],
            ],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nilai_penawaran', 2700000);
        $this->assertCount(1, $res->json('data.items'));
        // item lama di-soft-delete, item baru hidup
        $this->assertSame(1, DB::table('penawaran_item')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('penawaran_item')->whereNotNull('dihapus_pada')->count());
    }

    public function test_store_tanpa_items_perilaku_lama_tetap(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', [
            'nomor_penawaran' => 'PNW-MANUAL',
            'id_klien'        => $this->makeKlien(),
            'judul'           => 'Tanpa Item',
            'nilai_penawaran' => 12345678,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.nilai_penawaran', 12345678);
        $this->assertDatabaseCount('penawaran_item', 0);
    }

    public function test_item_dengan_rute_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $res = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute($lain),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 750000,
            ],
        ]));

        $res->assertStatus(404);
    }

    public function test_item_tanpa_harga_ditolak_validasi(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            ],
        ]));

        $res->assertStatus(422)->assertJsonValidationErrors(['items.0.harga_satuan']);
    }

    public function test_store_borongan_tanpa_harga_satuan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', array_merge(
            $this->payloadPenawaran([
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                ],
            ]),
            ['tipe_harga' => 'borongan']
        ));

        $res->assertStatus(201)
            ->assertJsonPath('data.tipe_harga', 'borongan')
            ->assertJsonPath('data.items.0.harga_satuan', null);
        $this->assertEquals(0, $res->json('data.items.0.subtotal'));
    }

    public function test_store_borongan_dengan_nilai_penawaran_tidak_tertimpa(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', array_merge(
            $this->payloadPenawaran([
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                ],
            ]),
            ['tipe_harga' => 'borongan', 'nilai_penawaran' => 50000000]
        ));

        $res->assertStatus(201)->assertJsonPath('data.nilai_penawaran', 50000000);
    }

    public function test_store_per_rit_dengan_tipe_harga_eksplisit_tetap_wajib_harga_satuan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran', array_merge(
            $this->payloadPenawaran([
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                ],
            ]),
            ['tipe_harga' => 'per_rit']
        ));

        $res->assertStatus(422)->assertJsonValidationErrors(['items.0.harga_satuan']);
    }

    public function test_store_item_duplikat_rute_dan_jenis_sama_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 500000],
            ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000],
        ]));

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Terdapat rute duplikat dalam item penawaran');
        $this->assertDatabaseCount('penawaran_item', 0);
    }

    public function test_store_item_rute_sama_jenis_kendaraan_berbeda_bukan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idRute = $this->makeRute();

        $res = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            ['id_rute' => $idRute, 'id_jenis_kendaraan' => $this->makeJenisKendaraan(), 'harga_satuan' => 500000],
            ['id_rute' => $idRute, 'id_jenis_kendaraan' => $this->makeJenisKendaraan(), 'harga_satuan' => 600000],
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('penawaran_item', 2);
    }

    public function test_update_item_duplikat_rute_dan_jenis_sama_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/penawaran', $this->payloadPenawaran([
            [
                'id_rute'            => $this->makeRute(),
                'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'harga_satuan'       => 750000,
            ],
        ]))->json('data.id_penawaran');

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->putJson("/api/penawaran/{$id}", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 500000],
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Terdapat rute duplikat dalam item penawaran');
        $this->assertSame(1, DB::table('penawaran_item')->whereNull('dihapus_pada')->count());
    }

    public function test_update_borongan_kirim_items_tanpa_tipe_harga_tetap_diterima(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $id = $this->postJson('/api/penawaran', array_merge(
            $this->payloadPenawaran([
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                ],
            ]),
            ['tipe_harga' => 'borongan']
        ))->json('data.id_penawaran');

        $res = $this->putJson("/api/penawaran/{$id}", [
            'items' => [
                [
                    'id_rute'            => $this->makeRute(),
                    'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                ],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.tipe_harga', 'borongan')
            ->assertJsonPath('data.items.0.harga_satuan', null);
    }
}
