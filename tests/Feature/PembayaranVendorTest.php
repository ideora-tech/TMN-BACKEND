<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembayaranVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
        ]);
    }

    private function makeVendorPerusahaanLain(): VendorModel
    {
        $idPerusahaanLain = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        return $this->makeVendor($idPerusahaanLain);
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
            'dpp'               => 10000000,
            'ppn'               => 0,
            'pph'               => 0,
            'total'             => 10000000,
            'status'            => 'diverifikasi',
            'status_pembayaran' => 'belum',
            'dibuat_pada'       => now(),
        ], $overrides));
        return $id;
    }

    private function bayar(string $idInvoice, float $nominal, array $extra = [])
    {
        return $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran", array_merge([
            'tanggal_bayar' => now()->toDateString(),
            'nominal'       => $nominal,
            'metode'        => 'transfer',
        ], $extra));
    }

    private ?string $idApproverInvoiceVendor = null;

    private function pastikanApproverInvoiceVendor(): string
    {
        if ($this->idApproverInvoiceVendor !== null) {
            return $this->idApproverInvoiceVendor;
        }

        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'APMGR-T', 'nama_jabatan' => 'AP Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-AP-' . Str::random(6), 'nama_karyawan' => 'Approver IV', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idPengguna = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idPengguna, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'kode_peran' => 'KEUANGAN', 'username' => 'approver_iv_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $idEventType = DB::table('approval_event_type')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('kode', 'invoice_vendor')->value('id_event_type');
        if ($idEventType === null) {
            $idEventType = (string) Str::uuid();
            DB::table('approval_event_type')->insert([
                'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
                'kode' => 'invoice_vendor', 'nama' => 'Invoice Vendor', 'mode_resolusi' => 'pinned',
                'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        return $this->idApproverInvoiceVendor = $idPengguna;
    }

    private function verifikasiViaApproval(string $idInvoice, string $keputusan = 'setuju', ?string $catatan = null): void
    {
        $idApprover = $this->pastikanApproverInvoiceVendor();
        $this->postJson("/api/invoice-vendor/{$idInvoice}/ajukan-approval")->assertStatus(200);
        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $idInvoice, $idApprover, $keputusan, $catatan, self::PERUSAHAAN_ID
        );
    }

    public function test_alur_lengkap_draft_verifikasi_bayar_cicilan_sampai_lunas(): void
    {
        $this->actingAsRole('KEUANGAN');
        $vendor = $this->makeVendor();

        $create = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-ALUR-001',
            'tanggal_invoice' => now()->toDateString(),
            'dpp'             => 10000000,
        ]);
        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $idInvoice = $create->json('data.id_invoice_vendor');

        $this->bayar($idInvoice, 4000000)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice belum diverifikasi');

        $this->verifikasiViaApproval($idInvoice);
        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status'            => 'diverifikasi',
        ]);

        $this->bayar($idInvoice, 4000000)->assertStatus(201);
        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'sebagian',
        ]);

        $this->bayar($idInvoice, 6000000)->assertStatus(201);
        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'lunas',
        ]);

        $detail = $this->getJson("/api/invoice-vendor/{$idInvoice}");
        $this->assertSame(10000000.0, (float) $detail->json('data.total_dibayar'));
        $this->assertSame(0.0, (float) $detail->json('data.sisa'));
        $this->assertCount(2, $detail->json('data.pembayaran'));
    }

    public function test_nominal_melebihi_sisa_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 4000000)->assertStatus(201);

        $this->bayar($idInvoice, 7000000)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Nominal melebihi sisa tagihan');

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'sebagian',
        ]);
    }

    public function test_bayar_pas_sisa_tagihan_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 10000000)->assertStatus(201);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'lunas',
        ]);
    }

    public function test_upload_bukti_pembayaran(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $res = $this->bayar($idInvoice, 5000000, [
            'bank_pengirim' => 'BCA',
            'no_referensi'  => 'TRX-001',
            'bukti'         => UploadedFile::fake()->create('bukti.pdf', 100),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.bank_pengirim', 'BCA')
            ->assertJsonPath('data.no_referensi', 'TRX-001');

        $urlBukti = $res->json('data.url_bukti');
        $this->assertIsString($urlBukti);
        $this->assertStringContainsString('bukti-pembayaran', $urlBukti);

        $tersimpan = (string) DB::table('pembayaran_vendor')->orderByDesc('dibuat_pada')->value('url_bukti');
        $this->assertStringStartsNotWith('http', $tersimpan);
        $this->assertStringStartsWith('bukti-pembayaran/', $tersimpan);
    }

    public function test_validasi_metode_tidak_dikenal_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 1000000, ['metode' => 'cek'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metode']);
    }

    public function test_list_pembayaran_invoice(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);
        $idInvoiceLain = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 1000000)->assertStatus(201);
        $this->bayar($idInvoice, 2000000)->assertStatus(201);
        $this->bayar($idInvoiceLain, 3000000)->assertStatus(201);

        $res = $this->getJson("/api/invoice-vendor/{$idInvoice}/pembayaran");

        $res->assertStatus(200)->assertJsonPath('success', true);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_hapus_pembayaran_soft_delete_dan_recalc(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 4000000)->assertStatus(201);
        $bayar2 = $this->bayar($idInvoice, 6000000);
        $bayar2->assertStatus(201);
        $idPembayaran = $bayar2->json('data.id_pembayaran_vendor');

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'lunas',
        ]);

        $this->deleteJson("/api/invoice-vendor/{$idInvoice}/pembayaran/{$idPembayaran}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $row = DB::table('pembayaran_vendor')->where('id_pembayaran_vendor', $idPembayaran)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'sebagian',
        ]);
    }

    public function test_hapus_semua_pembayaran_kembali_ke_belum(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $bayar = $this->bayar($idInvoice, 4000000);
        $bayar->assertStatus(201);
        $idPembayaran = $bayar->json('data.id_pembayaran_vendor');

        $this->deleteJson("/api/invoice-vendor/{$idInvoice}/pembayaran/{$idPembayaran}")
            ->assertStatus(200);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'belum',
        ]);
    }

    public function test_pembayaran_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $idInvoiceLain = $this->insertInvoice($vendorLain->id_vendor, [
            'id_perusahaan' => $vendorLain->id_perusahaan,
        ]);

        $idPembayaranLain = (string) Str::uuid();
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => $idPembayaranLain,
            'id_invoice_vendor'    => $idInvoiceLain,
            'tanggal_bayar'        => now()->toDateString(),
            'nominal'              => 1000000,
            'metode'               => 'transfer',
            'dibuat_pada'          => now(),
        ]);

        $this->getJson("/api/invoice-vendor/{$idInvoiceLain}/pembayaran")->assertStatus(404);
        $this->bayar($idInvoiceLain, 1000000)->assertStatus(404);
        $this->deleteJson("/api/invoice-vendor/{$idInvoiceLain}/pembayaran/{$idPembayaranLain}")
            ->assertStatus(404);

        $row = DB::table('pembayaran_vendor')->where('id_pembayaran_vendor', $idPembayaranLain)->first();
        $this->assertNull($row->dihapus_pada);
    }

    public function test_keuangan_boleh_mencatat_pembayaran(): void
    {
        $this->actingAsRole('KEUANGAN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 5000000)->assertStatus(201);
    }

    public function test_manager_boleh_lihat_tapi_tidak_boleh_mencatat_pembayaran(): void
    {
        $this->actingAsRole('MANAGER');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->getJson("/api/invoice-vendor/{$idInvoice}/pembayaran")->assertStatus(200);
        $this->bayar($idInvoice, 5000000)->assertStatus(403);
    }

    public function test_menolak_nominal_lebih_dari_dua_desimal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->bayar($idInvoice, 1000.999)->assertStatus(422)
            ->assertJsonValidationErrors(['nominal']);
    }
}
