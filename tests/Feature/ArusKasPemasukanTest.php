<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasPemasukanTest extends TestCase
{
    use RefreshDatabase;

    private function payloadValid(array $override = []): array
    {
        return array_merge([
            'kategori'    => 'pendapatan_jasa',
            'nominal'     => 1500000,
            'tanggal'     => '2026-08-05',
            'sumber_dana' => 'PT Klien Jaya',
            'keterangan'  => 'Pembayaran jasa angkut',
        ], $override);
    }

    private function buatPemasukan(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pemasukan')->insert(array_merge([
            'id_pemasukan'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'nomor_pemasukan' => 'PM-TEST-' . Str::random(6),
            'kategori'        => 'pendapatan_jasa',
            'tanggal'         => '2026-08-05',
            'nominal'         => 500000,
            'sumber_dana'     => 'PT Sumber Dana',
            'dibuat_pada'     => now(),
        ], $override));
        return $id;
    }

    private function buatFaktur(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('faktur')->insert(array_merge([
            'id_faktur'      => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'nomor_faktur'   => 'FK-TEST-' . Str::random(6),
            'total'          => 1000000,
            'status'         => 'terkirim',
            'tanggal_faktur' => '2026-08-05',
            'dibuat_pada'    => now(),
        ], $override));
        return $id;
    }

    public function test_keuangan_membuat_pemasukan_langsung_tercatat_dengan_nomor_pm(): void
    {
        $this->actingAsRole('KEUANGAN');

        $res = $this->postJson('/api/arus-kas/pemasukan', $this->payloadValid());

        $res->assertStatus(201)
            ->assertJsonPath('data.kategori', 'pendapatan_jasa')
            ->assertJsonPath('data.nominal', 1500000)
            ->assertJsonPath('data.sumber_dana', 'PT Klien Jaya');

        $this->assertMatchesRegularExpression('/^PM-\d{6}-0001$/', (string) $res->json('data.nomor_pemasukan'));
        $this->assertDatabaseHas('pemasukan', [
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kategori'      => 'pendapatan_jasa',
            'sumber_dana'   => 'PT Klien Jaya',
        ]);
    }

    public function test_nomor_pemasukan_berurutan_per_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $a = $this->postJson('/api/arus-kas/pemasukan', $this->payloadValid())->json('data.nomor_pemasukan');
        $b = $this->postJson('/api/arus-kas/pemasukan', $this->payloadValid())->json('data.nomor_pemasukan');

        $this->assertStringEndsWith('-0001', (string) $a);
        $this->assertStringEndsWith('-0002', (string) $b);
    }

    public function test_validasi_field_wajib_dan_kategori_enum(): void
    {
        $this->actingAsRole('KEUANGAN');

        $this->postJson('/api/arus-kas/pemasukan', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori', 'nominal', 'tanggal', 'sumber_dana']);

        $this->postJson('/api/arus-kas/pemasukan', $this->payloadValid(['kategori' => 'gaji']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori']);
    }

    public function test_role_admin_tidak_bisa_membuat_pemasukan(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/arus-kas/pemasukan', $this->payloadValid())->assertStatus(403);
    }

    public function test_update_pemasukan_manual(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatPemasukan();

        $res = $this->putJson("/api/arus-kas/pemasukan/{$id}", $this->payloadValid([
            'kategori' => 'penjualan_aset',
            'nominal'  => 2500000,
        ]));

        $res->assertStatus(200)
            ->assertJsonPath('data.kategori', 'penjualan_aset')
            ->assertJsonPath('data.nominal', 2500000);
        $this->assertDatabaseHas('pemasukan', ['id_pemasukan' => $id, 'kategori' => 'penjualan_aset']);
    }

    public function test_delete_pemasukan_soft_delete(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatPemasukan();

        $this->deleteJson("/api/arus-kas/pemasukan/{$id}")->assertStatus(200);

        $this->assertNotNull(DB::table('pemasukan')->where('id_pemasukan', $id)->value('dihapus_pada'));
    }

    public function test_pemasukan_perusahaan_lain_tidak_bisa_diubah(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $id = $this->buatPemasukan(['id_perusahaan' => $idLain]);

        $this->putJson("/api/arus-kas/pemasukan/{$id}", $this->payloadValid())->assertStatus(404);
        $this->deleteJson("/api/arus-kas/pemasukan/{$id}")->assertStatus(404);
    }

    public function test_role_admin_tidak_bisa_ubah_atau_hapus(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->buatPemasukan();

        $this->putJson("/api/arus-kas/pemasukan/{$id}", $this->payloadValid())->assertStatus(403);
        $this->deleteJson("/api/arus-kas/pemasukan/{$id}")->assertStatus(403);
    }

    public function test_list_gabungan_invoice_dan_manual(): void
    {
        $this->actingAsRole('ADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $this->buatFaktur(['status' => 'batal', 'tanggal_faktur' => '2026-08-06', 'total' => 999999]);
        $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000]);

        $res = $this->getJson('/api/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = collect($res->json('data'));
        $this->assertCount(2, $rows);

        $invoice = $rows->firstWhere('jenis', 'invoice');
        $this->assertSame(1000000, $invoice['nominal']);
        $this->assertFalse($invoice['dapat_diubah']);
        $this->assertNull($invoice['kategori']);

        $manual = $rows->firstWhere('jenis', 'manual');
        $this->assertSame(750000, $manual['nominal']);
        $this->assertTrue($manual['dapat_diubah']);
        $this->assertSame('pendapatan_jasa', $manual['kategori']);
        $this->assertSame('PT Sumber Dana', $manual['sumber_dana']);
    }

    public function test_list_gabungan_filter_jenis_dan_kategori(): void
    {
        $this->actingAsRole('ADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05']);
        $this->buatPemasukan(['kategori' => 'pendapatan_jasa', 'tanggal' => '2026-08-06']);
        $this->buatPemasukan(['kategori' => 'modal_pinjaman', 'tanggal' => '2026-08-07']);

        $invoice = $this->getJson('/api/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31&jenis=invoice');
        $this->assertCount(1, $invoice->json('data'));
        $this->assertSame('invoice', $invoice->json('data.0.jenis'));

        $kategori = $this->getJson('/api/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31&kategori=modal_pinjaman');
        $this->assertCount(1, $kategori->json('data'));
        $this->assertSame('modal_pinjaman', $kategori->json('data.0.kategori'));
    }

    public function test_list_gabungan_isolasi_tenant_dan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->buatFaktur(['id_perusahaan' => $idLain, 'tanggal_faktur' => '2026-08-05']);
        $this->buatPemasukan(['id_perusahaan' => $idLain, 'tanggal' => '2026-08-06']);
        $this->buatPemasukan(['tanggal' => '2026-08-07', 'dihapus_pada' => now()]);

        $res = $this->getJson('/api/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31');
        $this->assertCount(0, $res->json('data'));
    }

    public function test_list_gabungan_default_bulan_berjalan(): void
    {
        $this->actingAsRole('ADMIN');
        $this->buatPemasukan(['tanggal' => now()->startOfMonth()->addDays(2)->toDateString()]);
        $this->buatPemasukan(['tanggal' => now()->subMonths(2)->toDateString()]);

        $res = $this->getJson('/api/arus-kas/pemasukan');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_rekap_memuat_pemasukan_manual_dan_filter_sumber(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $id = $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000, 'keterangan' => 'Setoran modal']);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 1750000)
            ->assertJsonPath('data.ringkasan.netto', 1750000);

        $rows = collect($res->json('data.transaksi'))->where('sumber', 'pemasukan_manual')->values();
        $this->assertCount(1, $rows);
        $this->assertSame('masuk', $rows[0]['arah']);
        $this->assertSame(750000, $rows[0]['nominal']);
        $this->assertSame('pendapatan_jasa', $rows[0]['kategori']);
        $this->assertSame('2026-08-09', $rows[0]['tanggal']);
        $this->assertSame($id, $rows[0]['referensi']['id']);
        $this->assertSame('Setoran modal', $rows[0]['keterangan']);

        $filter = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31&sumber=pemasukan_manual');
        $this->assertCount(1, $filter->json('data.transaksi'));
        $this->assertSame('pemasukan_manual', $filter->json('data.transaksi.0.sumber'));
    }

    public function test_export_excel_memuat_pemasukan_manual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000]);

        $res = $this->get('/api/arus-kas/export/excel?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
    }
}
