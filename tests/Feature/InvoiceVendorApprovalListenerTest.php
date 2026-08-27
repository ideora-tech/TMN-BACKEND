<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorApprovalListenerTest extends TestCase
{
    use RefreshDatabase;

    private function buatInvoiceMenunggu(): string
    {
        $this->ensurePerusahaan();
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(8),
            'nama_vendor' => 'Vendor Listener', 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor' => $idVendor, 'nomor_invoice' => 'IV-LSN-' . Str::random(4),
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 100000, 'total' => 100000,
            'status' => 'menunggu_approval', 'status_pembayaran' => 'belum', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_listener_disetujui_jadi_diverifikasi_dengan_stempel_approver(): void
    {
        $id = $this->buatInvoiceMenunggu();
        $idApprover = (string) Str::uuid();

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), $idApprover, 'invoice_vendor', $id, 'disetujui', null));

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'diverifikasi', 'diverifikasi_oleh' => $idApprover,
        ]);
    }

    public function test_listener_ditolak_jadi_ditolak_dengan_catatan(): void
    {
        $id = $this->buatInvoiceMenunggu();

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'invoice_vendor', $id, 'ditolak', 'Dokumen kurang'));

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'ditolak', 'catatan_verifikasi' => 'Dokumen kurang',
        ]);
    }

    public function test_listener_mengabaikan_event_type_lain(): void
    {
        $id = $this->buatInvoiceMenunggu();

        event(new ApprovalDiputuskan(self::PERUSAHAAN_ID, (string) Str::uuid(), (string) Str::uuid(), 'faktur', $id, 'disetujui', null));

        $this->assertDatabaseHas('invoice_vendor', ['id_invoice_vendor' => $id, 'status' => 'menunggu_approval']);
    }

    public function test_listener_mengabaikan_referensi_perusahaan_lain(): void
    {
        $id = $this->buatInvoiceMenunggu();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        event(new ApprovalDiputuskan($idPerusahaanLain, (string) Str::uuid(), (string) Str::uuid(), 'invoice_vendor', $id, 'disetujui', null));

        $this->assertDatabaseHas('invoice_vendor', ['id_invoice_vendor' => $id, 'status' => 'menunggu_approval']);
    }
}
