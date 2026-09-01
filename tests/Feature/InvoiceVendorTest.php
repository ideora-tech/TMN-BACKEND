<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceVendorTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeKontrak(string $idVendor, ?int $termin = null, ?string $idPerusahaan = null, ?float $nilaiKontrak = null): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor'      => $id,
            'id_perusahaan'          => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_vendor'              => $idVendor,
            'nomor_kontrak'          => 'KV-' . Str::random(8),
            'mekanisme'              => 'unit_only',
            'termin_pembayaran_hari' => $termin,
            'nilai_kontrak'          => $nilaiKontrak ?? 0,
            'status'                 => 'aktif',
            'dibuat_pada'            => now(),
        ]);
        return $id;
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
            'dibuat_pada'          => now(),
        ]);
        return $id;
    }

    private function makeTipePembayaran(string $kode, string $nama, bool $aktif = true): void
    {
        DB::table('tipe_pembayaran')->insert([
            'id_tipe_pembayaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_tipe' => $kode, 'nama_tipe' => $nama, 'aktif' => $aktif ? 1 : 0, 'dibuat_pada' => now(),
        ]);
    }

    public function test_membuat_invoice_dengan_tipe_pembayaran_valid_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeTipePembayaran('top', 'TOP');

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-TP-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
            'tipe_pembayaran' => 'top',
            'top_hari'        => 30,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.tipe_pembayaran', 'top');
    }

    public function test_membuat_invoice_dengan_tipe_pembayaran_tidak_terdaftar_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-TP-002',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
            'tipe_pembayaran' => 'cicilan_tidak_ada',
        ]);

        $res->assertStatus(422);
    }

    public function test_membuat_invoice_dengan_tipe_pembayaran_nonaktif_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeTipePembayaran('dp', 'DP', false);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-TP-003',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
            'tipe_pembayaran' => 'dp',
        ]);

        $res->assertStatus(422);
    }

    public function test_membuat_invoice_total_dihitung_server(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-2026-001',
            'tanggal_invoice' => '2026-07-01',
            'periode_dari'    => '2026-06-01',
            'periode_sampai'  => '2026-06-30',
            'dpp'             => 10000000,
            'ppn'             => 1100000,
            'pph'             => 200000,
            'total'           => 1,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nomor_invoice', 'INV-2026-001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_pembayaran', 'belum')
            ->assertJsonPath('data.periode_dari', '2026-06-01')
            ->assertJsonPath('data.periode_sampai', '2026-06-30');

        $this->assertSame(10900000.0, (float) $res->json('data.total'));

        $this->assertDatabaseHas('invoice_vendor', [
            'nomor_invoice' => 'INV-2026-001',
            'total'         => 10900000,
        ]);
    }

    public function test_total_tidak_pernah_negatif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-2026-002',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 100000,
            'pph'             => 500000,
        ]);

        $res->assertStatus(201);
        $this->assertSame(0.0, (float) $res->json('data.total'));
    }

    public function test_jatuh_tempo_otomatis_dari_termin_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor, 30);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $idKontrak,
            'nomor_invoice'     => 'INV-2026-003',
            'tanggal_invoice'   => '2026-07-01',
            'dpp'               => 5000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jatuh_tempo', '2026-07-31');
    }

    public function test_jatuh_tempo_manual_tidak_ditimpa_termin(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor, 30);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $idKontrak,
            'nomor_invoice'     => 'INV-2026-004',
            'tanggal_invoice'   => '2026-07-01',
            'jatuh_tempo'       => '2026-07-10',
            'dpp'               => 5000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jatuh_tempo', '2026-07-10');
    }

    public function test_nomor_invoice_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->insertInvoice($vendor->id_vendor, ['nomor_invoice' => 'INV-DOBEL']);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-DOBEL',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('message', 'Nomor invoice sudah digunakan');
    }

    public function test_membuat_invoice_vendor_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendorLain->id_vendor,
            'nomor_invoice'   => 'INV-2026-005',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
        ])->assertStatus(404);
    }

    public function test_kontrak_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $vendorLain = $this->makeVendorPerusahaanLain();
        $idKontrakLain = $this->makeKontrak($vendorLain->id_vendor, 30, $vendorLain->id_perusahaan);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $idKontrakLain,
            'nomor_invoice'     => 'INV-2026-006',
            'tanggal_invoice'   => '2026-07-01',
            'dpp'               => 1000000,
        ])->assertStatus(404);
    }

    public function test_kontrak_milik_vendor_lain_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $idKontrakB = $this->makeKontrak($vendorB->id_vendor, 30);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'         => $vendorA->id_vendor,
            'id_kontrak_vendor' => $idKontrakB,
            'nomor_invoice'     => 'INV-2026-007',
            'tanggal_invoice'   => '2026-07-01',
            'dpp'               => 1000000,
        ])->assertStatus(422);
    }

    public function test_list_invoice_dengan_filter(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $this->insertInvoice($vendorA->id_vendor, ['nomor_invoice' => 'INV-A1', 'status' => 'draft']);
        $this->insertInvoice($vendorA->id_vendor, ['nomor_invoice' => 'INV-A2', 'status' => 'diverifikasi', 'status_pembayaran' => 'sebagian']);
        $this->insertInvoice($vendorB->id_vendor, ['nomor_invoice' => 'INV-B1', 'status' => 'draft']);

        $semua = $this->getJson('/api/invoice-vendor');
        $semua->assertStatus(200);
        $this->assertCount(3, $semua->json('data'));
        $this->assertArrayHasKey('meta', $semua->json());

        $byStatus = $this->getJson('/api/invoice-vendor?status=diverifikasi');
        $this->assertCount(1, $byStatus->json('data'));
        $this->assertSame('INV-A2', $byStatus->json('data.0.nomor_invoice'));

        $byStatusPembayaran = $this->getJson('/api/invoice-vendor?status_pembayaran=sebagian');
        $this->assertCount(1, $byStatusPembayaran->json('data'));

        $byVendor = $this->getJson("/api/invoice-vendor?id_vendor={$vendorB->id_vendor}");
        $this->assertCount(1, $byVendor->json('data'));
        $this->assertSame('INV-B1', $byVendor->json('data.0.nomor_invoice'));

        $bySearch = $this->getJson('/api/invoice-vendor?search=INV-A1');
        $this->assertCount(1, $bySearch->json('data'));
    }

    public function test_list_invoice_menyertakan_nilai_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor, null, null, 150000000);
        $this->insertInvoice($vendor->id_vendor, [
            'nomor_invoice'     => 'INV-KONTRAK',
            'id_kontrak_vendor' => $idKontrak,
        ]);
        $this->insertInvoice($vendor->id_vendor, ['nomor_invoice' => 'INV-TANPA-KONTRAK']);

        $res = $this->getJson('/api/invoice-vendor');

        $res->assertStatus(200);
        $rows = collect($res->json('data'))->keyBy('nomor_invoice');
        $this->assertSame(150000000.0, (float) $rows['INV-KONTRAK']['nilai_kontrak']);
        $this->assertNull($rows['INV-TANPA-KONTRAK']['nilai_kontrak']);
    }

    public function test_list_tidak_bocor_lintas_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $vendorLain = $this->makeVendorPerusahaanLain();
        $this->insertInvoice($vendor->id_vendor, ['nomor_invoice' => 'INV-SENDIRI']);
        $this->insertInvoice($vendorLain->id_vendor, [
            'nomor_invoice' => 'INV-LAIN',
            'id_perusahaan' => $vendorLain->id_perusahaan,
        ]);

        $res = $this->getJson('/api/invoice-vendor');
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('INV-SENDIRI', $res->json('data.0.nomor_invoice'));
    }

    public function test_detail_invoice_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor, 30, null, 250000000);
        $id = $this->insertInvoice($vendor->id_vendor, [
            'id_kontrak_vendor' => $idKontrak,
            'status'            => 'diverifikasi',
            'dpp'               => 10000000,
            'total'             => 10000000,
            'status_pembayaran' => 'sebagian',
        ]);
        $this->insertPembayaran($id, 4000000);

        $res = $this->getJson("/api/invoice-vendor/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.id_invoice_vendor', $id)
            ->assertJsonPath('data.vendor.id_vendor', $vendor->id_vendor)
            ->assertJsonPath('data.vendor.nama_vendor', 'Vendor Test')
            ->assertJsonPath('data.kontrak.id_kontrak_vendor', $idKontrak)
            ->assertJsonPath('data.kontrak.nilai_kontrak', 250000000);

        $this->assertSame(4000000.0, (float) $res->json('data.total_dibayar'));
        $this->assertSame(6000000.0, (float) $res->json('data.sisa'));
        $this->assertCount(1, $res->json('data.pembayaran'));
        $this->assertSame(4000000.0, (float) $res->json('data.pembayaran.0.nominal'));
        $this->assertSame('transfer', $res->json('data.pembayaran.0.metode'));
    }

    public function test_detail_invoice_url_bukti_pembayaran_dikembalikan_sebagai_url_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, [
            'status'            => 'diverifikasi',
            'total'             => 5000000,
            'status_pembayaran' => 'sebagian',
        ]);
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => (string) Str::uuid(),
            'id_invoice_vendor'    => $id,
            'tanggal_bayar'        => now()->toDateString(),
            'nominal'              => 2000000,
            'metode'               => 'transfer',
            'url_bukti'            => 'bukti-pembayaran/contoh.pdf',
            'dibuat_pada'          => now(),
        ]);

        $res = $this->getJson("/api/invoice-vendor/{$id}");

        $res->assertStatus(200);
        $urlBukti = $res->json('data.pembayaran.0.url_bukti');
        $this->assertIsString($urlBukti);
        $this->assertStringContainsString('/storage/bukti-pembayaran/', $urlBukti);
    }

    public function test_detail_invoice_tanpa_kontrak_kontrak_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $res = $this->getJson("/api/invoice-vendor/{$id}");

        $res->assertStatus(200);
        $this->assertNull($res->json('data.kontrak'));
        $this->assertSame([], $res->json('data.pembayaran'));
    }

    public function test_detail_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $vendorLain->id_perusahaan]);

        $this->getJson("/api/invoice-vendor/{$id}")->assertStatus(404);
    }

    public function test_update_invoice_draft_total_dihitung_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['dpp' => 1000000, 'total' => 1000000]);

        $res = $this->putJson("/api/invoice-vendor/{$id}", [
            'dpp' => 2000000,
            'ppn' => 220000,
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSame(2220000.0, (float) $res->json('data.total'));

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id,
            'total'             => 2220000,
        ]);
    }

    public function test_update_invoice_ditolak_kembali_ke_draft(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, [
            'status'             => 'ditolak',
            'catatan_verifikasi' => 'Nominal tidak sesuai',
        ]);

        $res = $this->putJson("/api/invoice-vendor/{$id}", [
            'dpp' => 1500000,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.catatan_verifikasi', null);
    }

    public function test_update_invoice_diverifikasi_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['status' => 'diverifikasi']);

        $this->putJson("/api/invoice-vendor/{$id}", ['dpp' => 999])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice tidak dapat diubah pada status ini');
    }

    public function test_update_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $vendorLain->id_perusahaan]);

        $this->putJson("/api/invoice-vendor/{$id}", ['dpp' => 999])->assertStatus(404);
    }

    public function test_hapus_invoice_draft_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->deleteJson("/api/invoice-vendor/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $row = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_hapus_invoice_diverifikasi_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['status' => 'diverifikasi']);

        $this->deleteJson("/api/invoice-vendor/{$id}")->assertStatus(409);

        $row = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->first();
        $this->assertNull($row->dihapus_pada);
    }

    public function test_approval_disetujui_invoice_terverifikasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->verifikasiViaApproval($id);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id,
            'status'            => 'diverifikasi',
            'diverifikasi_oleh' => $this->idApproverInvoiceVendor,
        ]);

        $diverifikasiPada = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->value('diverifikasi_pada');
        $this->assertNotNull($diverifikasiPada);
    }

    public function test_tolak_via_endpoint_generik_tanpa_catatan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $idApprover = $this->pastikanApproverInvoiceVendor();
        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        Sanctum::actingAs(Pengguna::findOrFail($idApprover), ['*']);
        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $id)->value('id_approval');

        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", [
            'keputusan' => 'tolak',
        ])->assertStatus(422);
    }

    public function test_approval_ditolak_invoice_ditolak_dengan_catatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->verifikasiViaApproval($id, 'tolak', 'Dokumen tidak lengkap');

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor'  => $id,
            'status'             => 'ditolak',
            'catatan_verifikasi' => 'Dokumen tidak lengkap',
        ]);
    }

    public function test_ajukan_approval_bukan_draft_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['status' => 'diverifikasi']);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(422);
    }

    public function test_monitoring_aging_dan_outstanding(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $vendorLain = $this->makeVendorPerusahaanLain();

        $idNull = $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'jatuh_tempo' => null,
            'dpp' => 1000000, 'total' => 1000000,
        ]);
        $idBelum = $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'jatuh_tempo' => now()->addDays(10)->toDateString(),
            'dpp' => 2000000, 'total' => 2000000, 'status_pembayaran' => 'sebagian',
        ]);
        $this->insertPembayaran($idBelum, 500000);
        $id10 = $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'jatuh_tempo' => now()->subDays(10)->toDateString(),
            'dpp' => 3000000, 'total' => 3000000,
        ]);
        $id45 = $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'jatuh_tempo' => now()->subDays(45)->toDateString(),
            'dpp' => 4000000, 'total' => 4000000,
        ]);
        $id90 = $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'jatuh_tempo' => now()->subDays(90)->toDateString(),
            'dpp' => 5000000, 'total' => 5000000,
        ]);

        $this->insertInvoice($vendor->id_vendor, ['status' => 'draft', 'total' => 7000000]);
        $this->insertInvoice($vendor->id_vendor, [
            'status' => 'diverifikasi', 'status_pembayaran' => 'lunas', 'total' => 8000000,
        ]);
        $this->insertInvoice($vendorLain->id_vendor, [
            'id_perusahaan' => $vendorLain->id_perusahaan,
            'status'        => 'diverifikasi',
            'total'         => 9000000,
        ]);

        $res = $this->getJson('/api/invoice-vendor/monitoring');

        $res->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah_invoice', 5)
            ->assertJsonPath('data.ringkasan.aging.belum_jatuh_tempo.jumlah', 2)
            ->assertJsonPath('data.ringkasan.aging.hari_1_30.jumlah', 1)
            ->assertJsonPath('data.ringkasan.aging.hari_31_60.jumlah', 1)
            ->assertJsonPath('data.ringkasan.aging.di_atas_60.jumlah', 1);

        $this->assertSame(14500000.0, (float) $res->json('data.ringkasan.total_outstanding'));
        $this->assertSame(2500000.0, (float) $res->json('data.ringkasan.aging.belum_jatuh_tempo.nominal'));
        $this->assertSame(3000000.0, (float) $res->json('data.ringkasan.aging.hari_1_30.nominal'));
        $this->assertSame(4000000.0, (float) $res->json('data.ringkasan.aging.hari_31_60.nominal'));
        $this->assertSame(5000000.0, (float) $res->json('data.ringkasan.aging.di_atas_60.nominal'));

        $outstanding = $res->json('data.outstanding');
        $this->assertCount(5, $outstanding);
        $this->assertSame(
            [$id90, $id45, $id10, $idBelum, $idNull],
            array_column($outstanding, 'id_invoice_vendor')
        );

        $barisTerlambat = collect($outstanding)->firstWhere('id_invoice_vendor', $id10);
        $this->assertSame(10, $barisTerlambat['hari_terlambat']);
        $this->assertSame('Vendor Test', $barisTerlambat['vendor_nama']);
        $this->assertSame(3000000.0, (float) $barisTerlambat['sisa']);

        $barisSebagian = collect($outstanding)->firstWhere('id_invoice_vendor', $idBelum);
        $this->assertSame(500000.0, (float) $barisSebagian['dibayar']);
        $this->assertSame(1500000.0, (float) $barisSebagian['sisa']);
        $this->assertSame(0, $barisSebagian['hari_terlambat']);
    }

    public function test_keuangan_boleh_membuat_invoice(): void
    {
        $this->actingAsRole('KEUANGAN');
        $vendor = $this->makeVendor();

        $this->assertDatabaseHas('izin_peran', [
            'kode_peran' => 'KEUANGAN',
            'aksi'       => 'tambah',
            'diizinkan'  => 1,
            'id_menu'    => 'm0000001-0000-4000-8000-000000000034',
        ]);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-KEU-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
        ])->assertStatus(201);
    }

    public function test_manager_izin_lihat_boleh_get_tapi_post_403(): void
    {
        $this->actingAsRole('MANAGER');
        $vendor = $this->makeVendor();
        $this->insertInvoice($vendor->id_vendor);

        $this->getJson('/api/invoice-vendor')->assertStatus(200);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-MGR-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
        ])->assertStatus(403);
    }

    public function test_manager_dengan_izin_tambah_tetap_403_karena_role(): void
    {
        $this->actingAsRole('MANAGER');
        $vendor = $this->makeVendor();

        DB::table('izin_peran')
            ->where('id_menu', 'm0000001-0000-4000-8000-000000000034')
            ->where('kode_peran', 'MANAGER')
            ->where('aksi', 'tambah')
            ->update(['diizinkan' => 1]);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-MGR-002',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000,
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk resource ini');
    }

    public function test_nomor_invoice_bekas_invoice_terhapus_bisa_dipakai_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['nomor_invoice' => 'INV-REUSE-001']);

        $this->deleteJson("/api/invoice-vendor/{$id}")->assertStatus(200);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-REUSE-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 500000,
        ])->assertStatus(201)
            ->assertJsonPath('data.nomor_invoice', 'INV-REUSE-001');
    }

    public function test_edit_invoice_dengan_kontrak_terhapus_tetap_bisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor);
        $id = $this->insertInvoice($vendor->id_vendor, ['id_kontrak_vendor' => $idKontrak]);

        DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $idKontrak)
            ->update(['dihapus_pada' => now()]);

        $this->putJson("/api/invoice-vendor/{$id}", [
            'no_po' => 'PO-BARU-001',
        ])->assertStatus(200)
            ->assertJsonPath('data.no_po', 'PO-BARU-001');
    }

    public function test_verifikasi_invoice_total_nol_langsung_lunas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['dpp' => 0, 'total' => 0]);

        $this->verifikasiViaApproval($id);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id,
            'status'            => 'diverifikasi',
            'status_pembayaran' => 'lunas',
        ]);

        $monitoring = $this->getJson('/api/invoice-vendor/monitoring')->assertStatus(200);
        $this->assertSame([], array_filter(
            $monitoring->json('data.outstanding'),
            fn ($row) => $row['id_invoice_vendor'] === $id
        ));
    }

    private function makeProyek(): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek Trip Vendor', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKontrakLengkap(string $idVendor, string $mekanisme = 'full', ?float $rate = 500000, ?string $satuan = 'per trip'): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_kontrak' => 'KV-' . Str::random(8), 'mekanisme' => $mekanisme,
            'rate' => $rate, 'satuan' => $satuan, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatTripSelesaiUntukKontrak(string $idVendor, string $idKontrak, string $idProyek, string $status = 'selesai'): string
    {
        $idArmadaVendor = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmadaVendor, 'id_vendor' => $idVendor,
            'nopol' => 'B ' . random_int(1000, 9999) . ' IV', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idSupirVendor = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor, 'id_vendor' => $idVendor,
            'nama' => 'Supir IV ' . Str::random(4), 'dibuat_pada' => now(),
        ]);
        $idPenugasan = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $idPenugasan, 'id_proyek' => $idProyek, 'sumber' => 'vendor',
            'id_kontrak_vendor' => $idKontrak, 'id_armada_vendor' => $idArmadaVendor, 'id_supir_vendor' => $idSupirVendor,
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal' => $idJadwal, 'id_penugasan' => $idPenugasan, 'waktu_berangkat' => now()->subDay(), 'dibuat_pada' => now(),
        ]);
        $idTrip = (string) Str::uuid();
        DB::table('trip')->insert([
            'id_trip' => $idTrip, 'id_jadwal' => $idJadwal, 'status' => $status, 'dibuat_pada' => now(),
        ]);
        return $idTrip;
    }

    public function test_trip_siap_tagih_mengembalikan_trip_selesai_belum_ditagih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $kontrak = $this->makeKontrakLengkap($vendor->id_vendor);
        $tripSelesai = $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek);
        $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek, 'berjalan');

        $res = $this->getJson("/api/invoice-vendor/trip-siap-tagih?id_kontrak_vendor={$kontrak}");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($tripSelesai, $res->json('data.0.id_trip'));
        $this->assertNotNull($res->json('data.0.nopol'));
        $this->assertNotNull($res->json('data.0.driver_nama'));
        $this->assertNotNull($res->json('data.0.nama_proyek'));
    }

    public function test_trip_siap_tagih_filter_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $kontrak = $this->makeKontrakLengkap($vendor->id_vendor);
        $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyekA);
        $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyekB);

        $res = $this->getJson("/api/invoice-vendor/trip-siap-tagih?id_kontrak_vendor={$kontrak}&id_proyek={$proyekA}");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($proyekA, $res->json('data.0.id_proyek'));
    }

    public function test_membuat_invoice_dengan_trip_ids_mengunci_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $kontrak = $this->makeKontrakLengkap($vendor->id_vendor);
        $trip1 = $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek);
        $trip2 = $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak,
            'nomor_invoice'     => 'INV-TRIP-001',
            'tanggal_invoice'   => '2026-07-01',
            'dpp'               => 1000000,
            'trip_ids'          => [$trip1, $trip2],
        ]);

        $res->assertStatus(201);
        $idInvoice = $res->json('data.id_invoice_vendor');

        $this->assertDatabaseHas('invoice_vendor_trip', ['id_invoice_vendor' => $idInvoice, 'id_trip' => $trip1]);
        $this->assertDatabaseHas('invoice_vendor_trip', ['id_invoice_vendor' => $idInvoice, 'id_trip' => $trip2]);

        $detail = $this->getJson("/api/invoice-vendor/{$idInvoice}");
        $this->assertCount(2, $detail->json('data.trip_terkait'));

        // Trip sekarang sudah terkunci — tidak lagi muncul di trip-siap-tagih.
        $siapTagih = $this->getJson("/api/invoice-vendor/trip-siap-tagih?id_kontrak_vendor={$kontrak}");
        $this->assertCount(0, $siapTagih->json('data'));
    }

    public function test_membuat_invoice_dengan_trip_sudah_terpakai_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $kontrak = $this->makeKontrakLengkap($vendor->id_vendor);
        $trip = $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek);

        $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $kontrak,
            'nomor_invoice' => 'INV-TRIP-A', 'tanggal_invoice' => '2026-07-01',
            'dpp' => 500000, 'trip_ids' => [$trip],
        ])->assertStatus(201);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $kontrak,
            'nomor_invoice' => 'INV-TRIP-B', 'tanggal_invoice' => '2026-07-01',
            'dpp' => 500000, 'trip_ids' => [$trip],
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('invoice_vendor', ['nomor_invoice' => 'INV-TRIP-B']);
    }

    public function test_trip_ids_tanpa_kontrak_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $kontrak = $this->makeKontrakLengkap($vendor->id_vendor);
        $trip = $this->buatTripSelesaiUntukKontrak($vendor->id_vendor, $kontrak, $proyek);

        $res = $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-TRIP-NOKONTRAK',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 500000,
            'trip_ids'        => [$trip],
        ]);

        $res->assertStatus(422);
    }

    public function test_menolak_dpp_lebih_dari_dua_desimal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-DESIMAL-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000.999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['dpp']);
    }
}
