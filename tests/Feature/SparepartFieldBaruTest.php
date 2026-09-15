<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SparepartFieldBaruTest extends TestCase
{
    use RefreshDatabase;

    private function makeSparepart(array $override = []): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert(array_merge([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-001',
            'nama'          => 'Filter Oli',
            'serial_number' => 'FO-12345',
            'merek'         => 'Sakura',
            'tahun'         => 2024,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ], $override));
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    public function test_create_tanpa_serial_number_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/sparepart', [
            'kode' => 'SP-100',
            'nama' => 'Kampas Rem',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['serial_number']);
        $this->assertSame('Serial number wajib diisi', $res->json('errors.serial_number.0'));
    }

    public function test_create_dengan_serial_merek_tahun_dan_satuan_set_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/sparepart', [
            'kode'          => 'SP-100',
            'nama'          => 'Kampas Rem',
            'serial_number' => 'KR-9001',
            'merek'         => 'Bendix',
            'tahun'         => 2024,
            'satuan'        => 'set',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.serial_number', 'KR-9001')
            ->assertJsonPath('data.merek', 'Bendix')
            ->assertJsonPath('data.tahun', 2024)
            ->assertJsonPath('data.satuan', 'set');

        $this->assertDatabaseHas('sparepart', [
            'kode'          => 'SP-100',
            'serial_number' => 'KR-9001',
            'merek'         => 'Bendix',
            'tahun'         => 2024,
            'satuan'        => 'set',
        ]);
    }

    public function test_satuan_di_luar_daftar_dan_tahun_di_luar_rentang_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resSatuan = $this->postJson('/api/sparepart', [
            'kode' => 'SP-101', 'nama' => 'Oli', 'serial_number' => 'OL-1', 'satuan' => 'botol',
        ]);
        $resSatuan->assertStatus(422)->assertJsonValidationErrors(['satuan']);
        $this->assertSame('Satuan harus pcs, set, atau liter', $resSatuan->json('errors.satuan.0'));

        $resTahun = $this->postJson('/api/sparepart', [
            'kode' => 'SP-102', 'nama' => 'Oli', 'serial_number' => 'OL-2', 'tahun' => 1800,
        ]);
        $resTahun->assertStatus(422)->assertJsonValidationErrors(['tahun']);
        $this->assertSame('Tahun tidak valid', $resTahun->json('errors.tahun.0'));

        $resTahunDepan = $this->postJson('/api/sparepart', [
            'kode' => 'SP-103', 'nama' => 'Oli', 'serial_number' => 'OL-3', 'tahun' => now()->year + 2,
        ]);
        $resTahunDepan->assertStatus(422)->assertJsonValidationErrors(['tahun']);
    }

    public function test_update_serial_number_kosong_ditolak_dan_update_merek_saja_tidak_mengubah_serial(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart();

        $resKosong = $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['serial_number' => '']);
        $resKosong->assertStatus(422)->assertJsonValidationErrors(['serial_number']);

        $resMerek = $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['merek' => 'Denso']);
        $resMerek->assertStatus(200)
            ->assertJsonPath('data.merek', 'Denso')
            ->assertJsonPath('data.serial_number', 'FO-12345')
            ->assertJsonPath('data.tahun', 2024);

        $this->assertDatabaseHas('sparepart', [
            'id_sparepart'  => $sp->id_sparepart,
            'serial_number' => 'FO-12345',
            'merek'         => 'Denso',
        ]);
    }

    public function test_search_menemukan_berdasarkan_serial_number_dan_merek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeSparepart(['kode' => 'SP-001', 'nama' => 'Filter Oli', 'serial_number' => 'FO-12345', 'merek' => 'Sakura']);
        $this->makeSparepart(['kode' => 'SP-002', 'nama' => 'Kampas Rem', 'serial_number' => 'KR-777', 'merek' => 'Bendix']);
        $this->makeSparepart(['kode' => 'SP-003', 'nama' => 'Busi', 'serial_number' => null, 'merek' => null, 'tahun' => null]);

        $resSerial = $this->getJson('/api/sparepart?search=KR-777');
        $resSerial->assertStatus(200);
        $this->assertCount(1, $resSerial->json('data'));
        $this->assertSame('SP-002', $resSerial->json('data.0.kode'));

        $resMerek = $this->getJson('/api/sparepart?search=sakura');
        $resMerek->assertStatus(200);
        $this->assertCount(1, $resMerek->json('data'));
        $this->assertSame('SP-001', $resMerek->json('data.0.kode'));

        $resSemua = $this->getJson('/api/sparepart');
        $this->assertCount(3, $resSemua->json('data'));
        $busi = collect($resSemua->json('data'))->firstWhere('kode', 'SP-003');
        $this->assertNull($busi['serial_number']);
        $this->assertNull($busi['merek']);
        $this->assertNull($busi['tahun']);
    }

    public function test_kategori_milik_perusahaan_lain_ditolak_saat_create_dan_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idKategoriLain = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $idKategoriLain, 'id_perusahaan' => $idPerusahaanLain,
            'nama' => 'Filter', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/sparepart', [
            'kode' => 'SP-900', 'nama' => 'Filter Oli', 'serial_number' => 'FO-1', 'id_kategori_sparepart' => $idKategoriLain,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-900']);

        $sp = $this->makeSparepart();
        $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['id_kategori_sparepart' => $idKategoriLain])->assertStatus(422);
        $this->assertNull(DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('id_kategori_sparepart'));
    }
}
