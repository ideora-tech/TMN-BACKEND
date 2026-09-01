<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Export Test',
        ]);
    }

    private function insertInvoice(string $idVendor, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert(array_merge([
            'id_invoice_vendor' => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_invoice'     => 'INV-' . Str::random(10),
            'tanggal_invoice'   => now()->toDateString(),
            'dpp'               => 1000000,
            'ppn'               => 0,
            'pph'               => 0,
            'total'             => 1000000,
            'status'            => 'draft',
            'status_pembayaran' => 'belum',
            'dibuat_pada'       => now(),
        ], $overrides));
        return $id;
    }

    private function insertPembayaran(string $idInvoice, float $nominal): string
    {
        $id = (string) Str::uuid();
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => $id,
            'id_invoice_vendor'    => $idInvoice,
            'tanggal_bayar'        => now()->toDateString(),
            'nominal'              => $nominal,
            'metode'               => 'transfer',
            'bank_pengirim'        => 'BCA',
            'no_referensi'         => 'REF-001',
            'dibuat_pada'          => now(),
        ]);
        return $id;
    }

    public function test_export_pdf_invoice_vendor_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['total' => 5000000]);
        $this->insertPembayaran($id, 2000000);

        $res = $this->get("/api/invoice-vendor/{$id}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_pdf_invoice_vendor_tanpa_pembayaran_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $res = $this->get("/api/invoice-vendor/{$id}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_pdf_invoice_vendor_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $id = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $idPerusahaanLain]);

        $this->get("/api/invoice-vendor/{$id}/export/pdf")->assertStatus(404);
    }

    public function test_export_pdf_pembayaran_termin_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor, ['total' => 5000000]);
        $idPembayaran = $this->insertPembayaran($idInvoice, 2000000);

        $res = $this->get("/api/invoice-vendor/{$idInvoice}/pembayaran/{$idPembayaran}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_pdf_pembayaran_termin_bukan_milik_invoice_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoiceA = $this->insertInvoice($vendor->id_vendor);
        $idInvoiceB = $this->insertInvoice($vendor->id_vendor);
        $idPembayaranA = $this->insertPembayaran($idInvoiceA, 500000);

        $this->get("/api/invoice-vendor/{$idInvoiceB}/pembayaran/{$idPembayaranA}/export/pdf")->assertStatus(404);
    }

    public function test_export_pdf_pembayaran_termin_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $idInvoice = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $idPerusahaanLain]);
        $idPembayaran = $this->insertPembayaran($idInvoice, 500000);

        $this->get("/api/invoice-vendor/{$idInvoice}/pembayaran/{$idPembayaran}/export/pdf")->assertStatus(404);
    }
}
