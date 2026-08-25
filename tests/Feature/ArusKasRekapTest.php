<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\Exports\ArusKasExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasRekapTest extends TestCase
{
    use RefreshDatabase;

    private function buatFaktur(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('faktur')->insert(array_merge([
            'id_faktur'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur'  => 'FK-TEST-' . Str::random(6),
            'total'         => 1000000,
            'status'        => 'terkirim',
            'tanggal_faktur' => '2026-08-05',
            'dibuat_pada'   => now(),
        ], $override));
        return $id;
    }

    private function buatPengajuanDitransfer(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert(array_merge([
            'id_pengajuan'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PP-TEST-' . Str::random(6),
            'kategori'          => 'uang_jalan',
            'nominal'           => 400000,
            'tanggal_pengajuan' => '2026-08-01',
            'penerima'          => 'Budi Supir',
            'keterangan'        => 'Uang jalan trip',
            'status'            => 'ditransfer',
            'tanggal_transfer'  => '2026-08-10',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function buatVendor(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor'     => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatInvoiceVendor(string $idVendor, ?string $idPerusahaan = null, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert(array_merge([
            'id_invoice_vendor' => $id,
            'id_perusahaan'     => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_invoice'     => 'INV-' . Str::random(10),
            'tanggal_invoice'   => '2026-08-01',
            'total'             => 5000000,
            'status'            => 'diverifikasi',
            'status_pembayaran' => 'lunas',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function buatPembayaranVendor(string $idInvoice, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pembayaran_vendor')->insert(array_merge([
            'id_pembayaran_vendor' => $id,
            'id_invoice_vendor'    => $idInvoice,
            'tanggal_bayar'        => '2026-08-07',
            'nominal'              => 2000000,
            'metode'               => 'transfer',
            'dibuat_pada'          => now(),
        ], $override));
        return $id;
    }

    private function buatKaryawan(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nik'           => 'NIK-' . Str::random(6),
            'nama_karyawan' => 'Karyawan Test',
            'aktif'         => 1,
            'gaji_pokok'    => 5000000,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPeriodePayroll(?string $idPerusahaan = null, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('payroll_periode')->insert(array_merge([
            'id_periode'        => $id,
            'id_perusahaan'     => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'              => 'Payroll Agustus 2026',
            'tanggal_mulai'     => '2026-08-01',
            'tanggal_selesai'   => '2026-08-31',
            'status'            => 'final',
            'difinalisasi_pada' => '2026-08-15 10:00:00',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function buatSlipPayroll(string $idPeriode, string $idKaryawan, ?string $idPerusahaan, float $gajiBersih): string
    {
        $id = (string) Str::uuid();
        DB::table('payroll_slip')->insert([
            'id_slip'       => $id,
            'id_periode'    => $idPeriode,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'gaji_bersih'   => $gajiBersih,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatArmada(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' TMN',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPerawatan(string $idArmada, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert(array_merge([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => '2026-08-06',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
            'dibuat_pada'     => now(),
        ], $override));
        return $id;
    }

    private function buatSupplier(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'          => 'Toko Test',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPembelianSparepart(string $idSupplier, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert(array_merge([
            'id_pembelian'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-TEST-' . Str::random(6),
            'id_supplier'       => $idSupplier,
            'status'            => 'lunas',
            'total_estimasi'    => 300000,
            'total_aktual'      => 320000,
            'tanggal_pengajuan' => '2026-08-01',
            'tanggal_pembelian' => '2026-08-02',
            'tanggal_pembayaran' => '2026-08-08',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function transaksiSumber($res, string $sumber): array
    {
        return collect($res->json('data.transaksi'))->where('sumber', $sumber)->values()->all();
    }

    public function test_sumber_faktur_masuk_dengan_coalesce_tanggal_dan_eksklusi_batal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $this->buatFaktur(['tanggal_faktur' => null, 'dibuat_pada' => '2026-08-03 09:00:00', 'total' => 750000]);
        $this->buatFaktur(['status' => 'batal', 'tanggal_faktur' => '2026-08-04', 'total' => 999999]);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = $this->transaksiSumber($res, 'faktur');
        $this->assertCount(2, $rows);

        $nominal = collect($rows)->pluck('nominal')->sort()->values()->all();
        $this->assertSame([750000, 1000000], $nominal);

        foreach ($rows as $row) {
            $this->assertSame('masuk', $row['arah']);
        }

        $withCoalesce = collect($rows)->firstWhere('nominal', 750000.0);
        $this->assertSame('2026-08-03', $withCoalesce['tanggal']);
    }

    public function test_sumber_pengajuan_pengeluaran_hanya_status_ditransfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatPengajuanDitransfer(['nominal' => 400000, 'tanggal_transfer' => '2026-08-10']);
        $this->buatPengajuanDitransfer(['status' => 'diajukan', 'tanggal_transfer' => null, 'nominal' => 999999]);
        $this->buatPengajuanDitransfer(['status' => 'disetujui', 'tanggal_transfer' => null, 'nominal' => 888888]);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = $this->transaksiSumber($res, 'pengajuan_pengeluaran');
        $this->assertCount(1, $rows);
        $this->assertSame(400000, $rows[0]['nominal']);
        $this->assertSame('keluar', $rows[0]['arah']);
        $this->assertSame('2026-08-10', $rows[0]['tanggal']);
        $this->assertSame('uang_jalan', $rows[0]['kategori']);
    }

    public function test_sumber_pembayaran_vendor_via_join_invoice(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->buatVendor();
        $invoice = $this->buatInvoiceVendor($vendor);
        $this->buatPembayaranVendor($invoice, ['nominal' => 2000000, 'tanggal_bayar' => '2026-08-07']);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = $this->transaksiSumber($res, 'pembayaran_vendor');
        $this->assertCount(1, $rows);
        $this->assertSame(2000000, $rows[0]['nominal']);
        $this->assertSame('keluar', $rows[0]['arah']);
        $this->assertSame('2026-08-07', $rows[0]['tanggal']);
    }

    public function test_payroll_periode_tidak_lagi_muncul_sebagai_sumber_langsung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $periodeFinal = $this->buatPeriodePayroll(null, ['difinalisasi_pada' => '2026-08-15 10:00:00']);
        $karyawanA = $this->buatKaryawan();
        $karyawanB = $this->buatKaryawan();
        $this->buatSlipPayroll($periodeFinal, $karyawanA, null, 4500000);
        $this->buatSlipPayroll($periodeFinal, $karyawanB, null, 4800000);

        $periodeDraft = $this->buatPeriodePayroll(null, ['status' => 'draft', 'difinalisasi_pada' => null, 'nama' => 'Draft']);
        $karyawanC = $this->buatKaryawan();
        $this->buatSlipPayroll($periodeDraft, $karyawanC, null, 5000000);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = $this->transaksiSumber($res, 'payroll_periode');
        $this->assertCount(0, $rows);
    }

    public function test_pembelian_sparepart_tidak_lagi_muncul_sebagai_sumber_langsung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->buatSupplier();
        $this->buatPembelianSparepart($supplier, [
            'status'         => 'lunas',
            'total_aktual'   => 320000,
            'total_estimasi' => 300000,
            'id_perawatan'   => null,
        ]);

        $armada = $this->buatArmada();
        $perawatan = $this->buatPerawatan($armada, ['biaya' => 100000, 'tanggal' => '2026-08-06']);
        $this->buatPembelianSparepart($supplier, [
            'status'         => 'lunas',
            'total_aktual'   => 500000,
            'total_estimasi' => 500000,
            'id_perawatan'   => $perawatan,
        ]);

        $this->buatPembelianSparepart($supplier, [
            'status'         => 'diajukan',
            'total_aktual'   => null,
            'total_estimasi' => 200000,
            'id_perawatan'   => null,
        ]);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = $this->transaksiSumber($res, 'pembelian_sparepart');
        $this->assertCount(0, $rows);
    }

    public function test_filter_arah_dan_sumber_tidak_mengubah_ringkasan_tapi_filter_periode_ya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $this->buatPengajuanDitransfer(['nominal' => 400000, 'tanggal_transfer' => '2026-08-10']);
        $this->buatFaktur(['tanggal_faktur' => '2026-07-01', 'total' => 500000]);

        $penuh = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $penuh->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 1000000)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 400000)
            ->assertJsonPath('data.ringkasan.netto', 600000);
        $this->assertCount(2, $penuh->json('data.transaksi'));

        $filterArah = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31&arah=masuk');
        $filterArah->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 1000000)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 400000);
        $this->assertCount(1, $filterArah->json('data.transaksi'));
        $this->assertSame('masuk', $filterArah->json('data.transaksi.0.arah'));

        $filterSumber = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31&sumber=pengajuan_pengeluaran');
        $filterSumber->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 1000000)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 400000);
        $this->assertCount(1, $filterSumber->json('data.transaksi'));
        $this->assertSame('pengajuan_pengeluaran', $filterSumber->json('data.transaksi.0.sumber'));

        $periodeSempit = $this->getJson('/api/arus-kas?dari=2026-07-01&sampai=2026-07-31');
        $periodeSempit->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 500000)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 0);
        $this->assertCount(1, $periodeSempit->json('data.transaksi'));
    }

    public function test_default_periode_bulan_berjalan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $tanggalBulanIni = now()->startOfMonth()->addDays(2)->toDateString();
        $this->buatFaktur(['tanggal_faktur' => $tanggalBulanIni, 'total' => 1234000]);
        $this->buatFaktur(['tanggal_faktur' => now()->subMonths(2)->toDateString(), 'total' => 999000]);

        $res = $this->getJson('/api/arus-kas');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.transaksi'));
        $this->assertSame(1234000, $res->json('data.transaksi.0.nominal'));
    }

    public function test_validasi_rentang_maksimal_366_hari(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/arus-kas?dari=2026-01-01&sampai=2027-01-05')->assertStatus(422);
        $this->getJson('/api/arus-kas?dari=2026-01-01&sampai=2026-12-31')->assertStatus(200);
    }

    public function test_isolasi_tenant_faktur_dan_pembayaran_vendor_via_join(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $this->buatFaktur(['id_perusahaan' => $idLain, 'tanggal_faktur' => '2026-08-05', 'total' => 111111]);

        $vendorLain = $this->buatVendor($idLain);
        $invoiceLain = $this->buatInvoiceVendor($vendorLain, $idLain);
        $this->buatPembayaranVendor($invoiceLain, ['nominal' => 222222, 'tanggal_bayar' => '2026-08-06']);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $this->assertCount(0, $this->transaksiSumber($res, 'faktur'));
        $this->assertCount(0, $this->transaksiSumber($res, 'pembayaran_vendor'));
        $this->assertSame(0, $res->json('data.ringkasan.total_pemasukan'));
        $this->assertSame(0, $res->json('data.ringkasan.total_pengeluaran'));
    }

    public function test_export_excel_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $this->buatPengajuanDitransfer(['nominal' => 400000, 'tanggal_transfer' => '2026-08-10']);

        $res = $this->get('/api/arus-kas/export/excel?dari=2026-08-01&sampai=2026-08-31');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('arus-kas-', (string) $res->headers->get('content-disposition'));
    }

    public function test_export_excel_default_periode_saat_dari_sampai_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $tanggalBulanIni = now()->startOfMonth()->addDays(2)->toDateString();
        $this->buatFaktur(['tanggal_faktur' => $tanggalBulanIni, 'total' => 1234000]);

        $res = $this->get('/api/arus-kas/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
    }

    public function test_label_periode_export_menangani_rentang_satu_sisi(): void
    {
        $this->assertSame('Periode: 01/08/2026 — 31/08/2026', ArusKasExport::labelPeriode('2026-08-01', '2026-08-31'));
        $this->assertSame('Periode: sejak 01/08/2026', ArusKasExport::labelPeriode('2026-08-01', null));
        $this->assertSame('Periode: s.d. 31/08/2026', ArusKasExport::labelPeriode(null, '2026-08-31'));
        $this->assertSame('Semua Periode', ArusKasExport::labelPeriode(null, null));
    }
}
