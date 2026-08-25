<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembelianLaporanTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => 'Toko Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama = 'Oli Mesin', int $stok = 0, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => 50000, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadPengajuan(array $override = []): array
    {
        return array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepart('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
                ['id_sparepart' => $this->makeSparepart('Filter Udara'), 'qty' => 1, 'harga_estimasi' => 80000],
            ],
        ], $override);
    }

    private function pembelianDibeli(float $totalAktual): string
    {
        $id = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-TEST-' . Str::random(6),
            'id_supplier'       => $this->makeSupplier(),
            'status'            => 'dibeli',
            'total_estimasi'    => $totalAktual,
            'total_aktual'      => $totalAktual,
            'tanggal_pengajuan' => now()->toDateString(),
            'tanggal_pembelian' => now()->toDateString(),
            'dibuat_pada'       => now(),
        ]);
        DB::table('pembelian_sparepart_item')->insert([
            'id_item'        => (string) Str::uuid(),
            'id_pembelian'   => $id,
            'id_sparepart'   => $this->makeSparepart(),
            'nama_sparepart' => 'Oli Mesin',
            'qty'            => 1,
            'harga_estimasi' => $totalAktual,
            'harga_aktual'   => $totalAktual,
            'dibuat_pada'    => now(),
        ]);
        return $id;
    }

    public function test_route_lunas_lama_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->pembelianDibeli(100000);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/pembelian-sparepart/{$id}/lunas", ['tanggal_pembayaran' => now()->toDateString()])
            ->assertStatus(404);
    }

    public function test_laporan_hanya_hitung_dibeli_dan_lunas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pembelianDibeli(100000);
        $this->pembelianDibeli(50000);
        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());

        $res = $this->getJson('/api/pembelian-sparepart/laporan');
        $res->assertStatus(200);
        $this->assertEquals(150000.0, $res->json('data.ringkasan.total_aktual'));
        $this->assertSame(2, $res->json('data.ringkasan.jumlah'));
        $this->assertNotEmpty($res->json('data.per_bulan'));
    }

    public function test_laporan_filter_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pembelianDibeli(100000);
        $res = $this->getJson('/api/pembelian-sparepart/laporan?dari=2020-01-01&sampai=2020-01-31');
        $this->assertSame(0, $res->json('data.ringkasan.jumlah'));
    }

    public function test_export_laporan_excel_mengembalikan_200_dan_xlsx(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pembelianDibeli(100000);

        $res = $this->get('/api/pembelian-sparepart/laporan/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));
    }

    public function test_export_laporan_pdf_mengembalikan_200_dan_pdf(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pembelianDibeli(100000);

        $res = $this->get('/api/pembelian-sparepart/laporan/export/pdf?dari=2020-01-01&sampai=' . now()->toDateString());

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }
}
