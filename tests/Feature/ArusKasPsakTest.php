<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use App\Modules\ArusKas\Exports\LaporanArusKasSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasPsakTest extends TestCase
{
    use RefreshDatabase;

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
            'status'            => 'ditransfer',
            'tanggal_transfer'  => '2026-08-10',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function buatPembayaranVendor(float $nominal, string $tanggalBayar): string
    {
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor'     => $idVendor,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
            'dibuat_pada'   => now(),
        ]);
        $idInvoice = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoice,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_invoice'     => 'INV-' . Str::random(10),
            'tanggal_invoice'   => '2026-08-01',
            'total'             => $nominal,
            'status'            => 'diverifikasi',
            'status_pembayaran' => 'lunas',
            'dibuat_pada'       => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => $id,
            'id_invoice_vendor'    => $idInvoice,
            'tanggal_bayar'        => $tanggalBayar,
            'nominal'              => $nominal,
            'metode'               => 'transfer',
            'dibuat_pada'          => now(),
        ]);
        return $id;
    }

    public function test_kategori_pengajuan_baru_lolos_validasi(): void
    {
        $this->actingAsRole('KEUANGAN');
        $payload = [
            'nominal'           => 1000000,
            'tanggal_pengajuan' => '2026-08-05',
            'penerima'          => 'Penerima Test',
        ];

        $this->postJson('/api/v1/arus-kas/pengajuan', $payload + ['kategori' => 'pembelian_aset'])
            ->assertStatus(201)
            ->assertJsonPath('data.kategori', 'pembelian_aset');

        $this->postJson('/api/v1/arus-kas/pengajuan', $payload + ['kategori' => 'pembayaran_pinjaman'])
            ->assertStatus(201)
            ->assertJsonPath('data.kategori', 'pembayaran_pinjaman');

        $this->postJson('/api/v1/arus-kas/pengajuan', $payload + ['kategori' => 'kategori_ngawur'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori']);
    }

    public function test_saldo_kas_sebelum_menghitung_empat_sumber(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-07-10', 'total' => 5000000]);
        $this->buatFaktur(['tanggal_faktur' => '2026-07-11', 'total' => 999999, 'status' => 'batal']);
        $this->buatPemasukan(['tanggal' => '2026-07-15', 'nominal' => 1000000]);
        $this->buatPengajuanDitransfer(['tanggal_transfer' => '2026-07-20', 'nominal' => 2000000]);
        $this->buatPengajuanDitransfer(['tanggal_transfer' => null, 'status' => 'diajukan', 'nominal' => 777777]);
        $this->buatPembayaranVendor(500000, '2026-07-25');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-01', 'total' => 888888]);

        $saldo = app(ArusKasRepositoryInterface::class)->saldoKasSebelum(self::PERUSAHAAN_ID, '2026-08-01');

        $this->assertSame(3500000.0, $saldo);
    }

    public function test_saldo_kas_sebelum_scope_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->buatFaktur(['id_perusahaan' => $idLain, 'tanggal_faktur' => '2026-07-10', 'total' => 123456]);

        $saldo = app(ArusKasRepositoryInterface::class)->saldoKasSebelum(self::PERUSAHAAN_ID, '2026-08-01');

        $this->assertSame(0.0, $saldo);
    }

    private function nominalBaris(array $kelompok, string $label): float
    {
        foreach ($kelompok['baris'] as $baris) {
            if ($baris['label'] === $label) {
                return (float) $baris['nominal'];
            }
        }
        $this->fail("Baris '{$label}' tidak ditemukan di kelompok {$kelompok['judul']}");
    }

    public function test_laporan_psak_mengelompokkan_tiga_aktivitas_dan_saldo(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 10000000]);
        $this->buatPemasukan(['kategori' => 'pendapatan_jasa', 'tanggal' => '2026-08-06', 'nominal' => 2000000]);
        $this->buatPemasukan(['kategori' => 'pengembalian_dana', 'tanggal' => '2026-08-06', 'nominal' => 300000]);
        $this->buatPemasukan(['kategori' => 'lainnya', 'tanggal' => '2026-08-06', 'nominal' => 100000]);
        $this->buatPemasukan(['kategori' => 'penjualan_aset', 'tanggal' => '2026-08-07', 'nominal' => 50000000]);
        $this->buatPemasukan(['kategori' => 'modal_pinjaman', 'tanggal' => '2026-08-07', 'nominal' => 25000000]);
        $this->buatPengajuanDitransfer(['kategori' => 'uang_jalan', 'nominal' => 1500000, 'tanggal_transfer' => '2026-08-08']);
        $this->buatPengajuanDitransfer(['kategori' => 'penggajian', 'nominal' => 4000000, 'tanggal_transfer' => '2026-08-08']);
        $this->buatPengajuanDitransfer(['kategori' => 'pembelian_aset', 'nominal' => 30000000, 'tanggal_transfer' => '2026-08-09']);
        $this->buatPengajuanDitransfer(['kategori' => 'pembayaran_pinjaman', 'nominal' => 7000000, 'tanggal_transfer' => '2026-08-09']);
        $this->buatPengajuanDitransfer(['kategori' => 'kategori_lama_aneh', 'nominal' => 111111, 'tanggal_transfer' => '2026-08-09']);
        $this->buatPembayaranVendor(800000, '2026-08-10');
        $this->buatFaktur(['tanggal_faktur' => '2026-07-10', 'total' => 5000000]);

        $laporan = app(ArusKasService::class)->laporanPsak(self::PERUSAHAAN_ID, '2026-08-01', '2026-08-31');

        [$operasi, $investasi, $pendanaan] = $laporan['kelompok'];

        $this->assertSame('ARUS KAS DARI AKTIVITAS OPERASI', $operasi['judul']);
        $this->assertSame(12000000.0, $this->nominalBaris($operasi, 'Penerimaan dari pelanggan'));
        $this->assertSame(300000.0, $this->nominalBaris($operasi, 'Penerimaan pengembalian dana'));
        $this->assertSame(100000.0, $this->nominalBaris($operasi, 'Penerimaan operasional lainnya'));
        $this->assertSame(1500000.0, $this->nominalBaris($operasi, 'Pembayaran uang jalan'));
        $this->assertSame(4000000.0, $this->nominalBaris($operasi, 'Pembayaran gaji karyawan'));
        $this->assertSame(800000.0, $this->nominalBaris($operasi, 'Pembayaran ke vendor'));
        $this->assertSame(111111.0, $this->nominalBaris($operasi, 'Pembayaran operasional lainnya'));
        $this->assertSame(5988889.0, $operasi['subtotal']);

        $this->assertSame('ARUS KAS DARI AKTIVITAS INVESTASI', $investasi['judul']);
        $this->assertSame(50000000.0, $this->nominalBaris($investasi, 'Penerimaan penjualan aset'));
        $this->assertSame(30000000.0, $this->nominalBaris($investasi, 'Pembayaran pembelian aset'));
        $this->assertSame(20000000.0, $investasi['subtotal']);

        $this->assertSame('ARUS KAS DARI AKTIVITAS PENDANAAN', $pendanaan['judul']);
        $this->assertSame(25000000.0, $this->nominalBaris($pendanaan, 'Penerimaan modal/pinjaman'));
        $this->assertSame(7000000.0, $this->nominalBaris($pendanaan, 'Pembayaran pinjaman'));
        $this->assertSame(18000000.0, $pendanaan['subtotal']);

        $this->assertSame(43988889.0, $laporan['kenaikan_bersih']);
        $this->assertSame(5000000.0, $laporan['saldo_awal']);
        $this->assertSame(48988889.0, $laporan['saldo_akhir']);
    }

    public function test_sheet_laporan_psak_menyembunyikan_baris_nol_dan_memuat_penutup(): void
    {
        $laporan = [
            'kelompok' => [[
                'judul'          => 'ARUS KAS DARI AKTIVITAS OPERASI',
                'subtotal_label' => 'Kas Bersih dari Aktivitas Operasi',
                'subtotal'       => 500000.0,
                'baris' => [
                    ['label' => 'Penerimaan dari pelanggan', 'arah' => 'masuk',  'nominal' => 500000.0],
                    ['label' => 'Pembayaran uang jalan',     'arah' => 'keluar', 'nominal' => 0.0],
                ],
            ]],
            'kenaikan_bersih' => 500000.0,
            'saldo_awal'      => 100000.0,
            'saldo_akhir'     => 600000.0,
        ];

        $sheet = new LaporanArusKasSheet($laporan, 'PT Uji Transport', '2026-08-01', '2026-08-31');
        $rows  = $sheet->collection()->all();
        $labels = array_map(fn (array $row) => $row[0] ?? '', $rows);

        $this->assertSame('PT Uji Transport', $labels[0]);
        $this->assertSame('LAPORAN ARUS KAS', $labels[1]);
        $this->assertSame('Periode: 01/08/2026 — 31/08/2026', $labels[2]);
        $this->assertSame('(Metode Langsung)', $labels[3]);
        $this->assertContains('ARUS KAS DARI AKTIVITAS OPERASI', $labels);
        $this->assertContains('    Penerimaan dari pelanggan', $labels);
        $this->assertNotContains('    Pembayaran uang jalan', $labels);
        $this->assertContains('Kas Bersih dari Aktivitas Operasi', $labels);
        $this->assertContains('SALDO KAS AKHIR PERIODE', $labels);

        $barisAkhir = $rows[array_search('SALDO KAS AKHIR PERIODE', $labels, true)];
        $this->assertSame(600000.0, $barisAkhir[1]);
    }

    public function test_export_excel_dua_sheet_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
        $this->buatPengajuanDitransfer(['nominal' => 400000, 'tanggal_transfer' => '2026-08-10']);

        $res = $this->get('/api/v1/arus-kas/export/excel?dari=2026-08-01&sampai=2026-08-31');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('arus-kas-', (string) $res->headers->get('content-disposition'));
    }
}
