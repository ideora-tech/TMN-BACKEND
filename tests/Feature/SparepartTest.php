<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeSparepart(string $kode = 'SP-001', string $nama = 'Filter Oli', int $stok = 10, ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode'          => $kode,
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => $stok,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    public function test_create_sparepart_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/sparepart', [
            'kode'          => 'SP-100',
            'nama'          => 'Kampas Rem',
            'satuan'        => 'set',
            'harga_standar' => 350000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode', 'SP-100')
            ->assertJsonPath('data.stok', 0)
            ->assertJsonPath('data.aktif', true);
    }

    public function test_kode_duplikat_per_perusahaan_ditolak_409_tapi_beda_perusahaan_boleh(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSparepart('SP-001');

        $resDup = $this->postJson('/api/v1/sparepart', ['kode' => 'SP-001', 'nama' => 'Duplikat']);
        $resDup->assertStatus(409);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeSparepart('SP-001', 'Punya Orang', 5, $idLain);
        // baris di atas insert langsung — membuktikan DB tidak punya unique global; validasi hanya app-level per perusahaan
        $this->assertSame(2, DB::table('sparepart')->where('kode', 'SP-001')->count());
    }

    public function test_tambah_stok_masuk_menambah_dan_mencatat_mutasi(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart('SP-001', 'Filter Oli', 10);

        $res = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis'      => 'masuk',
            'qty'        => 5,
            'harga'      => 45000,
            'keterangan' => 'Pembelian rutin',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.stok', 15);

        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $sp->id_sparepart,
            'jenis'        => 'masuk',
            'qty'          => 5,
        ]);
    }

    public function test_penyesuaian_negatif_boleh_tapi_stok_minus_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart('SP-001', 'Filter Oli', 10);

        $resOk = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis' => 'penyesuaian', 'qty' => -3, 'keterangan' => 'Stok opname',
        ]);
        $resOk->assertStatus(200)->assertJsonPath('data.stok', 7);

        $resMinus = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis' => 'penyesuaian', 'qty' => -100,
        ]);
        $resMinus->assertStatus(422);
        $this->assertSame(7, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_masuk_qty_nol_atau_negatif_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart();

        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => 0])->assertStatus(422);
        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => -2])->assertStatus(422);
    }

    public function test_riwayat_mutasi_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart();

        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => 5]);
        $this->travel(1)->seconds();
        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'penyesuaian', 'qty' => -1]);

        $res = $this->getJson("/api/v1/sparepart/{$sp->id_sparepart}/mutasi");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('penyesuaian', $res->json('data.0.jenis'));
    }

    public function test_list_scoped_dan_search(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSparepart('SP-001', 'Filter Oli');
        $this->makeSparepart('SP-002', 'Kampas Rem');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeSparepart('SP-003', 'Punya Orang', 5, $idLain);

        $resAll = $this->getJson('/api/v1/sparepart');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json('data'));

        $resSearch = $this->getJson('/api/v1/sparepart?search=kampas');
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertSame('SP-002', $resSearch->json('data.0.kode'));
    }
}
