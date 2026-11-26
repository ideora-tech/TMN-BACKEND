<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\InvoiceVendor\InvoiceVendorService;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorBiayaTambahanTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
        ]);
    }

    private function buat(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/invoice-vendor', array_merge([
            'id_vendor'       => $this->makeVendor()->id_vendor,
            'nomor_invoice'   => 'INV-' . Str::random(6),
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 10000000,
            'ppn'             => 1100000,
            'pph'             => 200000,
        ], $override));
    }

    public function test_biaya_tambahan_masuk_total_tanpa_pajak(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->buat(['biaya_tambahan' => [
            ['nama' => 'Tol', 'nominal' => 150000],
            ['nama' => '  Parkir & kuli  ', 'nominal' => 50000],
            ['nama' => '', 'nominal' => 0],
        ]])->assertStatus(422);

        // Baris kosong tidak lolos validasi; kirim yang valid saja.
        $res = $this->buat(['biaya_tambahan' => [
            ['nama' => 'Tol', 'nominal' => 150000],
            ['nama' => '  Parkir & kuli  ', 'nominal' => 50000],
        ]])->assertStatus(201);

        // 10.000.000 + 1.100.000 − 200.000 + 200.000
        $this->assertSame(11100000.0, (float) $res->json('data.total'));
        $this->assertSame(200000.0, (float) $res->json('data.total_biaya_tambahan'));

        $id = (string) $res->json('data.id_invoice_vendor');
        $detail = $this->getJson("/api/invoice-vendor/{$id}")->assertStatus(200);
        $this->assertSame(['Tol', 'Parkir & kuli'], collect($detail->json('data.biaya_tambahan'))->pluck('nama')->all());
        $this->assertSame(11100000.0, (float) $detail->json('data.sisa'));
    }

    public function test_edit_mengganti_biaya_tambahan_dan_tanpa_kunci_tidak_mengubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = (string) $this->buat(['biaya_tambahan' => [['nama' => 'Tol', 'nominal' => 150000]]])->json('data.id_invoice_vendor');

        // Edit field lain tanpa mengirim biaya_tambahan → biaya tetap.
        $this->putJson("/api/invoice-vendor/{$id}", ['keterangan' => 'revisi'])
            ->assertStatus(200)->assertJsonPath('data.total_biaya_tambahan', 150000);

        $this->putJson("/api/invoice-vendor/{$id}", ['biaya_tambahan' => [
            ['nama' => 'Kuli bongkar', 'nominal' => 300000],
            ['nama' => 'Parkir', 'nominal' => 20000],
        ]])->assertStatus(200);

        $detail = $this->getJson("/api/invoice-vendor/{$id}")->json('data');
        $this->assertSame(['Kuli bongkar', 'Parkir'], array_column($detail['biaya_tambahan'], 'nama'));
        $this->assertSame(11220000.0, (float) $detail['total']);
        $this->assertSame(2, DB::table('invoice_vendor_biaya')->where('id_invoice_vendor', $id)->whereNull('dihapus_pada')->count());

        // Kirim array kosong → semua biaya dihapus.
        $this->putJson("/api/invoice-vendor/{$id}", ['biaya_tambahan' => []])
            ->assertStatus(200)->assertJsonPath('data.total', 10900000);
    }

    public function test_rekap_hutang_memuat_biaya_tambahan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = (string) $this->buat(['biaya_tambahan' => [['nama' => 'Tol', 'nominal' => 150000]]])->json('data.id_invoice_vendor');
        DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->update(['status' => 'diverifikasi']);

        $rekap = app(InvoiceVendorService::class)->rekapHutangBulanan(self::PERUSAHAAN_ID, '2026-07');
        $this->assertEquals(150000, $rekap['baris'][0]['biaya_tambahan']);
        $this->assertEquals(11050000, $rekap['baris'][0]['total']);
        $this->assertEquals(150000, $rekap['jumlah']['biaya_tambahan']);

        $export = new \App\Modules\InvoiceVendor\Exports\RekapHutangVendorExport($rekap);
        $baris  = $export->array();
        $this->assertSame(['DPP', 'PPN', 'PPH', 'BIAYA TAMBAHAN', 'TOTAL'], array_slice($baris[2], 5));
        $this->assertEquals([10000000, 1100000, 200000, 150000, 11050000], array_slice($baris[3], 5));
    }
}
