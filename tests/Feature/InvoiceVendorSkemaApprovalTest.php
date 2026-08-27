<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorSkemaApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Skema',
        ]);
    }

    public function test_status_menunggu_approval_bisa_disimpan(): void
    {
        $this->ensurePerusahaan();
        $vendor = $this->makeVendor();
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $vendor->id_vendor,
            'nomor_invoice'     => 'IV-SKEMA-1',
            'tanggal_invoice'   => now()->toDateString(),
            'dpp'               => 1000000,
            'total'             => 1000000,
            'status'            => 'menunggu_approval',
            'status_pembayaran' => 'belum',
            'dibuat_pada'       => now(),
        ]);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id,
            'status'            => 'menunggu_approval',
        ]);
    }
}
