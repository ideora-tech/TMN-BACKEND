<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembayaranVendorPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Pengajuan Test',
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

    private function setBatasTinggi(): void
    {
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    public function test_ajukan_pembayaran_membuat_pengajuan_kategori_pembayaran_vendor(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $res = $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", [
            'nominal' => 4000000,
            'catatan' => 'Termin pertama',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'      => $res->json('data.id_pengajuan'),
            'id_invoice_vendor' => $idInvoice,
            'kategori'          => 'pembayaran_vendor',
            'penerima'          => 'Vendor Pengajuan Test',
            'nominal'           => 4000000,
        ]);
        $this->assertSame(0, DB::table('pembayaran_vendor')->where('id_invoice_vendor', $idInvoice)->count());
    }

    public function test_ajukan_pembayaran_invoice_belum_diverifikasi_ditolak_409(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor, ['status' => 'draft']);

        $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 1000000])
            ->assertStatus(409);
    }

    public function test_ajukan_pembayaran_melebihi_sisa_termasuk_pengajuan_berjalan_ditolak_409(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor, ['total' => 10000000]);

        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => (string) Str::uuid(),
            'id_invoice_vendor'    => $idInvoice,
            'tanggal_bayar'        => now()->toDateString(),
            'nominal'              => 3000000,
            'metode'               => 'transfer',
            'dibuat_pada'          => now(),
        ]);

        $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 4000000])
            ->assertStatus(201);

        $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 4000000])
            ->assertStatus(409);
    }

    public function test_transfer_pengajuan_membuat_baris_pembayaran_dan_recalc_status(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor, ['total' => 10000000]);

        $idPengajuan = $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 10000000])
            ->assertStatus(201)
            ->json('data.id_pengajuan');

        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $this->assertDatabaseHas('pembayaran_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'nominal'           => 10000000,
            'metode'            => 'transfer',
        ]);
        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $idInvoice,
            'status_pembayaran' => 'lunas',
        ]);
    }

    public function test_detail_invoice_menyertakan_daftar_pengajuan_pembayaran(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor);

        $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 2500000])
            ->assertStatus(201);

        $res = $this->getJson("/api/invoice-vendor/{$idInvoice}")->assertStatus(200);
        $daftar = $res->json('data.pengajuan_pembayaran');
        $this->assertCount(1, $daftar);
        $this->assertSame(2500000, (int) $daftar[0]['nominal']);
        $this->assertSame('disetujui', $daftar[0]['status']);
        $this->assertNotEmpty($daftar[0]['nomor_pengajuan']);
    }

    public function test_pengeluaran_bertaut_invoice_tidak_dobel_hitung_di_arus_kas(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->setBatasTinggi();
        $vendor = $this->makeVendor();
        $idInvoice = $this->insertInvoice($vendor->id_vendor, ['total' => 5000000]);

        $idPengajuan = $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", ['nominal' => 5000000])
            ->json('data.id_pengajuan');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $dari = now()->subDay()->toDateString();
        $sampai = now()->addDay()->toDateString();
        $res = $this->getJson("/api/arus-kas?dari={$dari}&sampai={$sampai}&arah=keluar")->assertStatus(200);

        $baris = collect($res->json('data.transaksi'))
            ->filter(fn ($t) => in_array($t['sumber'], ['pengajuan_pengeluaran', 'pembayaran_vendor'], true)
                && (float) $t['nominal'] === 5000000.0);
        $this->assertCount(1, $baris);
        $this->assertSame('pembayaran_vendor', $baris->first()['sumber']);
    }
}
