<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoint rincian sumber dipakai drawer "Detail Pengajuan" di Proses Pembayaran
 * supaya keuangan bisa lihat rincian transaksi asalnya tanpa pindah halaman.
 */
class ArusKasRincianSumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatArmada(string $nopol = 'B 9002 TMN'): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatSparepart(string $nama = 'Oli Mesin'): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 60000,
            'stok'          => 10,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatSupplier(string $nama = 'Bengkel Maju'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_rincian_pengajuan_dari_perawatan_berisi_sparepart_dan_biaya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'     => '2026-09-10',
            'biaya'       => 250000,
            'km_odometer' => 120000,
            'status'      => 'selesai',
            'id_supplier' => $this->buatSupplier(),
            'keterangan'  => 'Servis berkala 20.000 km',
            'sparepart'   => [
                ['id_sparepart' => $this->buatSparepart(), 'qty' => 2, 'harga' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $idPengajuan = DB::table('pengajuan_pengeluaran')
            ->where('id_perawatan', $idPerawatan)->value('id_pengajuan');
        $this->assertNotNull($idPengajuan);

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'perawatan')
            ->assertJsonPath('data.data.id_perawatan', $idPerawatan)
            ->assertJsonPath('data.data.biaya', 250000)
            ->assertJsonPath('data.data.km_odometer', 120000)
            ->assertJsonPath('data.data.nama_supplier', 'Bengkel Maju')
            ->assertJsonPath('data.data.armada_nopol', 'B 9002 TMN')
            ->assertJsonPath('data.data.sparepart.0.nama_sparepart', 'Oli Mesin')
            ->assertJsonPath('data.data.sparepart.0.subtotal', 120000);
    }

    public function test_rincian_pengajuan_dari_pembelian_berisi_item(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/pembelian-sparepart', [
            'id_supplier'       => $this->buatSupplier('Toko Sparepart Jaya'),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->buatSparepart('Filter Udara'), 'qty' => 2, 'harga_estimasi' => 60000],
            ],
            'bukti'             => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $res->assertStatus(201);
        $idPembelian = $res->json('data.id_pembelian');

        $idPengajuan = DB::table('pengajuan_pengeluaran')
            ->where('id_pembelian', $idPembelian)->value('id_pengajuan');
        $this->assertNotNull($idPengajuan);

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'pembelian')
            ->assertJsonPath('data.data.id_pembelian', $idPembelian)
            ->assertJsonPath('data.data.nama_supplier', 'Toko Sparepart Jaya')
            ->assertJsonPath('data.data.total_estimasi', 120000)
            ->assertJsonPath('data.data.items.0.nama_sparepart', 'Filter Udara')
            ->assertJsonPath('data.data.items.0.qty', 2);
    }

    public function test_pengajuan_manual_tanpa_sumber_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/arus-kas/pengajuan', [
            'kategori'          => 'lainnya',
            'nominal'           => 500000,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Budi',
            'keterangan'        => 'Pengeluaran manual',
        ]);
        $res->assertStatus(201);

        $this->getJson("/api/arus-kas/pengajuan/{$res->json('data.id_pengajuan')}/rincian-sumber")
            ->assertStatus(404);
    }

    public function test_rincian_uang_jalan_berisi_daftar_penugasan_yang_dibiayai(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Budi Supir', 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => (string) Str::uuid(), 'kode_proyek' => 'PRJ-' . Str::random(6),
            'nama_proyek' => 'Proyek Uang Jalan', 'dibuat_pada' => now(),
        ]);

        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Bandung',
            'dibuat_pada' => now(),
        ]);

        $idArmada = $this->buatArmada('B 7001 TMN');

        $tanggal = ['2026-09-01', '2026-09-02', '2026-09-03'];
        $pengajuan = app(ArusKasService::class)->buatPengajuanUangJalanPenugasan(
            self::PERUSAHAAN_ID,
            $idSupir,
            $idProyek,
            150000,
            $tanggal,
        );

        // Satu tanggal dibatalkan setelah pengajuan dibuat — selisihnya harus terlihat.
        foreach ($tanggal as $i => $t) {
            DB::table('penugasan')->insert([
                'id_penugasan'   => (string) Str::uuid(),
                'id_proyek'      => $idProyek,
                'id_armada'      => $idArmada,
                'id_supir'       => $idSupir,
                'id_rute'        => $idRute,
                'id_pengajuan'   => $pengajuan->id_pengajuan,
                'tanggal_tugas'  => $t,
                'status'         => $i === 2 ? 'batal' : 'aktif',
                'estimasi_biaya' => 150000,
                'dibuat_pada'    => now(),
            ]);
        }

        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'uang_jalan')
            ->assertJsonPath('data.data.nama_supir', 'Budi Supir')
            ->assertJsonPath('data.data.nama_proyek', 'Proyek Uang Jalan')
            ->assertJsonPath('data.data.tarif_per_hari', 150000)
            ->assertJsonPath('data.data.jumlah_hari_ditagih', 3)
            ->assertJsonPath('data.data.jumlah_penugasan', 3)
            ->assertJsonPath('data.data.jumlah_dibatalkan', 1)
            ->assertJsonPath('data.data.penugasan.0.tanggal_tugas', '2026-09-01')
            ->assertJsonPath('data.data.penugasan.0.nama_rute', 'Jakarta - Bandung')
            ->assertJsonPath('data.data.penugasan.0.nopol', 'B 7001 TMN')
            ->assertJsonPath('data.data.penugasan.2.status', 'batal');
    }

    public function test_rincian_payroll_hanya_agregat_tanpa_slip_per_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPeriode = (string) Str::uuid();
        DB::table('payroll_periode')->insert([
            'id_periode' => $idPeriode, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Payroll September 2026', 'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-30', 'status' => 'final', 'dibuat_pada' => now(),
        ]);

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'K-' . Str::random(6), 'nama_karyawan' => 'Siti Karyawan',
            'dibuat_pada' => now(),
        ]);
        DB::table('payroll_slip')->insert([
            'id_slip' => (string) Str::uuid(), 'id_periode' => $idPeriode,
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'gaji_pokok' => 5000000, 'total_bruto' => 5000000,
            'total_potongan' => 500000, 'gaji_bersih' => 4500000,
            'dibuat_pada' => now(),
        ]);

        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_periode' => $idPeriode, 'nomor_pengajuan' => 'PP-202609-9001',
            'kategori' => 'penggajian', 'nominal' => 4500000,
            'tanggal_pengajuan' => '2026-09-30', 'penerima' => 'Payroll September 2026',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber");
        $res->assertStatus(200)
            ->assertJsonPath('data.tipe', 'payroll')
            ->assertJsonPath('data.data.periode.nama', 'Payroll September 2026')
            ->assertJsonPath('data.data.ringkasan.jumlah_slip', 1)
            ->assertJsonPath('data.data.ringkasan.total_gaji_bersih', 4500000);

        // Gaji per karyawan ada di balik izin `karyawan`, jangan bocor lewat endpoint ini.
        $this->assertArrayNotHasKey('slip', $res->json('data.data'));
        $this->assertArrayNotHasKey('slips', $res->json('data.data'));
        $res->assertJsonMissing(['nama_karyawan' => 'Siti Karyawan']);
    }

    public function test_rincian_pengajuan_dari_invoice_vendor_berisi_nilai_invoice(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => 'Vendor Angkutan',
            'dibuat_pada' => now(),
        ]);

        $idInvoice = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoice, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor' => $idVendor, 'nomor_invoice' => 'INV-' . Str::random(8),
            'tanggal_invoice' => '2026-09-01', 'jatuh_tempo' => '2026-09-30',
            'dpp' => 1000000, 'ppn' => 110000, 'pph' => 20000, 'total' => 1090000,
            'status' => 'diverifikasi', 'status_pembayaran' => 'belum',
            'dibuat_pada' => now(),
        ]);

        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_invoice_vendor' => $idInvoice, 'nomor_pengajuan' => 'PP-202609-9002',
            'kategori' => 'pembayaran_vendor', 'nominal' => 1090000,
            'tanggal_pengajuan' => '2026-09-05', 'penerima' => 'Vendor Angkutan',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'invoice_vendor')
            ->assertJsonPath('data.data.id_invoice_vendor', $idInvoice)
            ->assertJsonPath('data.data.vendor.nama_vendor', 'Vendor Angkutan')
            ->assertJsonPath('data.data.total', 1090000)
            ->assertJsonPath('data.data.sisa', 1090000);
    }
}
