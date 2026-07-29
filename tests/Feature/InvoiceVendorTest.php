<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorTest extends TestCase
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

    private function makeKontrak(string $idVendor, ?int $termin = null, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor'      => $id,
            'id_perusahaan'          => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_vendor'              => $idVendor,
            'nomor_kontrak'          => 'KV-' . Str::random(8),
            'mekanisme'              => 'unit_only',
            'termin_pembayaran_hari' => $termin,
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

    public function test_membuat_invoice_total_dihitung_server(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/v1/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-2026-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 10000000,
            'ppn'             => 1100000,
            'pph'             => 200000,
            'total'           => 1,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nomor_invoice', 'INV-2026-001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_pembayaran', 'belum');

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

        $res = $this->postJson('/api/v1/invoice-vendor', [
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

        $res = $this->postJson('/api/v1/invoice-vendor', [
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

        $res = $this->postJson('/api/v1/invoice-vendor', [
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

        $res = $this->postJson('/api/v1/invoice-vendor', [
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

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->postJson('/api/v1/invoice-vendor', [
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

        $semua = $this->getJson('/api/v1/invoice-vendor');
        $semua->assertStatus(200);
        $this->assertCount(3, $semua->json('data'));
        $this->assertArrayHasKey('meta', $semua->json());

        $byStatus = $this->getJson('/api/v1/invoice-vendor?status=diverifikasi');
        $this->assertCount(1, $byStatus->json('data'));
        $this->assertSame('INV-A2', $byStatus->json('data.0.nomor_invoice'));

        $byStatusPembayaran = $this->getJson('/api/v1/invoice-vendor?status_pembayaran=sebagian');
        $this->assertCount(1, $byStatusPembayaran->json('data'));

        $byVendor = $this->getJson("/api/v1/invoice-vendor?id_vendor={$vendorB->id_vendor}");
        $this->assertCount(1, $byVendor->json('data'));
        $this->assertSame('INV-B1', $byVendor->json('data.0.nomor_invoice'));

        $bySearch = $this->getJson('/api/v1/invoice-vendor?search=INV-A1');
        $this->assertCount(1, $bySearch->json('data'));
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

        $res = $this->getJson('/api/v1/invoice-vendor');
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('INV-SENDIRI', $res->json('data.0.nomor_invoice'));
    }

    public function test_detail_invoice_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($vendor->id_vendor, 30);
        $id = $this->insertInvoice($vendor->id_vendor, [
            'id_kontrak_vendor' => $idKontrak,
            'status'            => 'diverifikasi',
            'dpp'               => 10000000,
            'total'             => 10000000,
            'status_pembayaran' => 'sebagian',
        ]);
        $this->insertPembayaran($id, 4000000);

        $res = $this->getJson("/api/v1/invoice-vendor/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.id_invoice_vendor', $id)
            ->assertJsonPath('data.vendor.id_vendor', $vendor->id_vendor)
            ->assertJsonPath('data.vendor.nama_vendor', 'Vendor Test')
            ->assertJsonPath('data.kontrak.id_kontrak_vendor', $idKontrak);

        $this->assertSame(4000000.0, (float) $res->json('data.total_dibayar'));
        $this->assertSame(6000000.0, (float) $res->json('data.sisa'));
        $this->assertCount(1, $res->json('data.pembayaran'));
        $this->assertSame(4000000.0, (float) $res->json('data.pembayaran.0.nominal'));
        $this->assertSame('transfer', $res->json('data.pembayaran.0.metode'));
    }

    public function test_detail_invoice_tanpa_kontrak_kontrak_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $res = $this->getJson("/api/v1/invoice-vendor/{$id}");

        $res->assertStatus(200);
        $this->assertNull($res->json('data.kontrak'));
        $this->assertSame([], $res->json('data.pembayaran'));
    }

    public function test_detail_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $vendorLain->id_perusahaan]);

        $this->getJson("/api/v1/invoice-vendor/{$id}")->assertStatus(404);
    }

    public function test_update_invoice_draft_total_dihitung_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['dpp' => 1000000, 'total' => 1000000]);

        $res = $this->putJson("/api/v1/invoice-vendor/{$id}", [
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

        $res = $this->putJson("/api/v1/invoice-vendor/{$id}", [
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

        $this->putJson("/api/v1/invoice-vendor/{$id}", ['dpp' => 999])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice yang sudah diverifikasi tidak dapat diubah');
    }

    public function test_update_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertInvoice($vendorLain->id_vendor, ['id_perusahaan' => $vendorLain->id_perusahaan]);

        $this->putJson("/api/v1/invoice-vendor/{$id}", ['dpp' => 999])->assertStatus(404);
    }

    public function test_hapus_invoice_draft_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->deleteJson("/api/v1/invoice-vendor/{$id}")
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

        $this->deleteJson("/api/v1/invoice-vendor/{$id}")->assertStatus(409);

        $row = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->first();
        $this->assertNull($row->dihapus_pada);
    }

    public function test_verifikasi_invoice_berhasil(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $res = $this->patchJson("/api/v1/invoice-vendor/{$id}/verifikasi", [
            'aksi' => 'verifikasi',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'diverifikasi')
            ->assertJsonPath('data.diverifikasi_oleh', $pengguna->id_pengguna);

        $this->assertNotNull($res->json('data.diverifikasi_pada'));
    }

    public function test_tolak_tanpa_catatan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->patchJson("/api/v1/invoice-vendor/{$id}/verifikasi", [
            'aksi' => 'tolak',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['catatan']);
    }

    public function test_tolak_dengan_catatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor);

        $this->patchJson("/api/v1/invoice-vendor/{$id}/verifikasi", [
            'aksi'    => 'tolak',
            'catatan' => 'Dokumen tidak lengkap',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.catatan_verifikasi', 'Dokumen tidak lengkap');
    }

    public function test_verifikasi_invoice_bukan_draft_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['status' => 'diverifikasi']);

        $this->patchJson("/api/v1/invoice-vendor/{$id}/verifikasi", [
            'aksi' => 'verifikasi',
        ])->assertStatus(409);
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

        $res = $this->getJson('/api/v1/invoice-vendor/monitoring');

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

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->getJson('/api/v1/invoice-vendor')->assertStatus(200);

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->deleteJson("/api/v1/invoice-vendor/{$id}")->assertStatus(200);

        $this->postJson('/api/v1/invoice-vendor', [
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

        $this->putJson("/api/v1/invoice-vendor/{$id}", [
            'no_po' => 'PO-BARU-001',
        ])->assertStatus(200)
            ->assertJsonPath('data.no_po', 'PO-BARU-001');
    }

    public function test_verifikasi_invoice_total_nol_langsung_lunas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertInvoice($vendor->id_vendor, ['dpp' => 0, 'total' => 0]);

        $this->patchJson("/api/v1/invoice-vendor/{$id}/verifikasi", ['aksi' => 'verifikasi'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'diverifikasi')
            ->assertJsonPath('data.status_pembayaran', 'lunas');

        $monitoring = $this->getJson('/api/v1/invoice-vendor/monitoring')->assertStatus(200);
        $this->assertSame([], array_filter(
            $monitoring->json('data.outstanding'),
            fn ($row) => $row['id_invoice_vendor'] === $id
        ));
    }

    public function test_menolak_dpp_lebih_dari_dua_desimal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/v1/invoice-vendor', [
            'id_vendor'       => $vendor->id_vendor,
            'nomor_invoice'   => 'INV-DESIMAL-001',
            'tanggal_invoice' => '2026-07-01',
            'dpp'             => 1000000.999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['dpp']);
    }
}
