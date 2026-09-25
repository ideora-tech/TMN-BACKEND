<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LaporanPengadaanTest extends TestCase
{
    use RefreshDatabase;

    private string $idPengaju;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        $this->idPengaju = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'DISPATCHER',
            'username' => 'pengaju_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ])->id_pengguna;
    }

    private function makeDepartemen(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_departemen' => 'DPT-' . Str::random(4), 'nama_departemen' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupplier(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKategoriBarang(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('kategori_barang')->insert([
            'id_kategori_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeBarang(string $nama, string $idKategori): string
    {
        $id = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'BRG-' . Str::random(4),
            'nama' => $nama, 'id_kategori_barang' => $idKategori, 'satuan' => 'rim', 'harga_standar' => 50000,
            'stok' => 0, 'stok_minimum' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKategoriSparepart(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama, ?string $idKategori = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'id_kategori_sparepart' => $idKategori, 'satuan' => 'pcs', 'harga_standar' => 100000,
            'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(4), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerawatan(string $nopol): string
    {
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol, 'merk' => 'Hino']);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $armada->id_armada, 'tanggal' => '2026-09-01',
            'jenis_perawatan' => 'Ganti Oli', 'biaya' => 0, 'status' => 'selesai', 'dibuat_pada' => now(),
        ]);
        return $idPerawatan;
    }

    private function insertPr(array $header, array $items): string
    {
        $id = (string) Str::uuid();
        DB::table('permintaan_pembelian')->insert(array_merge([
            'id_permintaan'      => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nomor_permintaan'   => 'PR-TEST-' . Str::random(6),
            'id_pengaju'         => $this->idPengaju,
            'tanggal_permintaan' => now()->toDateString(),
            'judul'              => 'PR Test',
            'alasan'             => 'Kebutuhan test',
            'status'             => 'diajukan',
            'tipe'               => 'umum',
            'total_estimasi'     => 0,
            'dibuat_pada'        => now(),
        ], $header));
        foreach ($items as $item) {
            DB::table('permintaan_pembelian_item')->insert(array_merge([
                'id_item'        => (string) Str::uuid(),
                'id_permintaan'  => $id,
                'jenis'          => 'barang',
                'nama_item'      => 'Item Test',
                'qty'            => 1,
                'satuan'         => 'pcs',
                'harga_estimasi' => 0,
                'dibuat_pada'    => now(),
            ], $item));
        }
        return $id;
    }

    private function insertPsLangsung(array $header, array $items): string
    {
        $id = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert(array_merge([
            'id_pembelian'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-TEST-' . Str::random(6),
            'status'            => 'dibeli',
            'total_estimasi'    => 0,
            'tanggal_pengajuan' => now()->toDateString(),
            'dibuat_pada'       => now(),
        ], $header));
        foreach ($items as $item) {
            DB::table('pembelian_sparepart_item')->insert(array_merge([
                'id_item'        => (string) Str::uuid(),
                'id_pembelian'   => $id,
                'nama_sparepart' => 'Item Test',
                'qty'            => 1,
                'harga_estimasi' => 0,
                'dibuat_pada'    => now(),
            ], $item));
        }
        return $id;
    }

    private function insertTermin(string $idPermintaan, int $urutan, float $nominal, string $status): void
    {
        DB::table('permintaan_pembelian_termin')->insert([
            'id_termin'     => (string) Str::uuid(),
            'id_permintaan' => $idPermintaan,
            'urutan'        => $urutan,
            'nama'          => "Termin {$urutan}",
            'nominal'       => $nominal,
            'status'        => $status,
            'dibuat_pada'   => now(),
        ]);
    }

    private function cari(array $rows, string $key, $value): ?array
    {
        foreach ($rows as $row) {
            if ($row[$key] === $value) {
                return $row;
            }
        }
        return null;
    }

    /** @return array{idAset:string} */
    private function seedGabunganLengkap(): array
    {
        $idDept = $this->makeDepartemen('Operasional');
        $idSupUmum = $this->makeSupplier('Toko Umum');
        $idSupSparepart = $this->makeSupplier('Toko Sparepart');
        $idSupAki = $this->makeSupplier('Toko Aki');
        $idKatBarang = $this->makeKategoriBarang('ATK');
        $idBarang = $this->makeBarang('Kertas A4', $idKatBarang);
        $idKatMesin = $this->makeKategoriSparepart('Mesin');
        $idKatBan = $this->makeKategoriSparepart('Ban');
        $idKatAki = $this->makeKategoriSparepart('Aki');
        $idSp1 = $this->makeSparepart('Filter Oli', $idKatMesin);
        $idSp2 = $this->makeSparepart('Busi', $idKatMesin);
        $idSp3 = $this->makeSparepart('Ban Luar', $idKatBan);
        $idSp4 = $this->makeSparepart('Aki Kering', $idKatAki);
        $idJenis = $this->makeJenisKendaraan('Truk Engkel');
        $idPerawatan = $this->makePerawatan('B 1111 SP');

        $this->insertPr([
            'tipe' => 'umum', 'status' => 'diterima', 'id_departemen' => $idDept, 'id_supplier' => $idSupUmum,
            'tanggal_permintaan' => '2026-09-01', 'tanggal_pembelian' => '2026-09-05', 'tanggal_diterima' => '2026-09-10',
            'total_estimasi' => 1000000, 'total_aktual' => 1000000, 'judul' => 'ATK Kantor',
        ], [
            ['jenis' => 'barang', 'id_barang' => $idBarang, 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 50000, 'harga_aktual' => 50000],
            ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 500000, 'harga_aktual' => 500000],
        ]);

        $this->insertPr([
            'tipe' => 'sparepart', 'status' => 'diterima', 'id_perawatan' => $idPerawatan, 'id_supplier' => $idSupSparepart,
            'tanggal_permintaan' => '2026-09-02', 'tanggal_pembelian' => '2026-09-06', 'tanggal_diterima' => '2026-09-08',
            'total_estimasi' => 400000, 'total_aktual' => 390000, 'judul' => 'Sparepart Perawatan',
        ], [
            ['jenis' => 'sparepart', 'id_sparepart' => $idSp1, 'nama_item' => 'Filter Oli', 'satuan' => 'pcs', 'qty' => 2, 'harga_estimasi' => 100000, 'harga_aktual' => 100000],
            ['jenis' => 'sparepart', 'id_sparepart' => $idSp2, 'nama_item' => 'Busi', 'satuan' => 'pcs', 'qty' => 1, 'harga_estimasi' => 190000, 'harga_aktual' => 190000],
        ]);

        $this->insertPr([
            'tipe' => 'sparepart', 'status' => 'dibeli',
            'tanggal_permintaan' => '2026-09-03', 'tanggal_pembelian' => '2026-09-07', 'tanggal_diterima' => null,
            'total_estimasi' => 250000, 'total_aktual' => 240000, 'judul' => 'Sparepart Stok',
        ], [
            ['jenis' => 'sparepart', 'id_sparepart' => $idSp3, 'nama_item' => 'Ban Luar', 'satuan' => 'pcs', 'qty' => 1, 'harga_estimasi' => 250000, 'harga_aktual' => 240000],
        ]);

        $idAset = $this->insertPr([
            'tipe' => 'aset', 'status' => 'diterima',
            'tanggal_permintaan' => '2026-09-01', 'tanggal_pembelian' => '2026-09-03', 'tanggal_diterima' => '2026-09-15',
            'total_estimasi' => 300000000, 'total_aktual' => 300000000, 'judul' => 'Pengadaan Truk',
        ], [
            ['jenis' => 'aset', 'id_jenis_kendaraan' => $idJenis, 'merk' => 'Hino', 'model' => 'Dutro', 'tahun' => 2026,
                'nama_item' => 'Hino Dutro 2026', 'satuan' => 'unit', 'qty' => 2, 'qty_diterima' => 2, 'harga_estimasi' => 150000000, 'harga_aktual' => 150000000],
        ]);
        $this->insertTermin($idAset, 1, 200000000, 'ditransfer');
        $this->insertTermin($idAset, 2, 100000000, 'menunggu');

        $this->insertPsLangsung([
            'id_supplier' => $idSupAki, 'status' => 'dibeli',
            'tanggal_pengajuan' => '2026-09-04', 'tanggal_pembelian' => '2026-09-04',
            'total_estimasi' => 150000, 'total_aktual' => 150000,
        ], [
            ['id_sparepart' => $idSp4, 'nama_sparepart' => 'Aki Kering', 'qty' => 1, 'harga_estimasi' => 150000, 'harga_aktual' => 150000],
        ]);

        $this->insertPr([
            'tipe' => 'umum', 'status' => 'disetujui',
            'tanggal_permintaan' => now()->subDays(30)->toDateString(),
            'total_estimasi' => 75000, 'judul' => 'PR Menunggu Lama',
        ], [
            ['jenis' => 'barang', 'id_barang' => $idBarang, 'nama_item' => 'Kertas A4', 'qty' => 1, 'satuan' => 'rim', 'harga_estimasi' => 75000],
        ]);

        return ['idAset' => $idAset];
    }

    public function test_gabungan_pr_dan_ps_langsung_menghasilkan_ringkasan_konsisten(): void
    {
        $data = $this->seedGabunganLengkap();

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson('/api/permintaan-pembelian/laporan?dari=2026-09-01&sampai=2026-09-30')->assertStatus(200);
        $json = $res->json('data');

        $this->assertEquals(301800000.0, $json['ringkasan']['total_estimasi']);
        $this->assertEquals(301780000.0, $json['ringkasan']['total_aktual']);
        $this->assertEquals(-20000.0, $json['ringkasan']['selisih']);
        $this->assertSame(5, $json['ringkasan']['jumlah']);
        $this->assertSame(1, $json['ringkasan']['menunggu_diproses']);
        $this->assertEquals(9.7, $json['ringkasan']['rata_lead_time_hari']);

        $this->assertCount(1, $json['per_bulan']);
        $this->assertSame('2026-09', $json['per_bulan'][0]['bulan']);
        $this->assertEquals(1000000.0, $json['per_bulan'][0]['umum']);
        $this->assertEquals(780000.0, $json['per_bulan'][0]['sparepart']);
        $this->assertEquals(300000000.0, $json['per_bulan'][0]['aset']);
        $this->assertSame(5, $json['per_bulan'][0]['jumlah']);

        $umum = $this->cari($json['per_tipe'], 'tipe', 'umum');
        $sparepart = $this->cari($json['per_tipe'], 'tipe', 'sparepart');
        $aset = $this->cari($json['per_tipe'], 'tipe', 'aset');
        $this->assertEquals(1000000.0, $umum['total_aktual']);
        $this->assertSame(1, $umum['jumlah']);
        $this->assertEquals(780000.0, $sparepart['total_aktual']);
        $this->assertSame(3, $sparepart['jumlah']);
        $this->assertEquals(300000000.0, $aset['total_aktual']);
        $this->assertSame(1, $aset['jumlah']);

        $this->assertEquals(300000000.0, $this->cari($json['per_kategori'], 'kategori', 'Aset · Truk Engkel')['total_aktual']);
        $this->assertEquals(500000.0, $this->cari($json['per_kategori'], 'kategori', 'Barang · ATK')['total_aktual']);
        $this->assertEquals(500000.0, $this->cari($json['per_kategori'], 'kategori', 'Jasa')['total_aktual']);
        $this->assertEquals(390000.0, $this->cari($json['per_kategori'], 'kategori', 'Spare Part · Mesin')['total_aktual']);
        $this->assertEquals(240000.0, $this->cari($json['per_kategori'], 'kategori', 'Spare Part · Ban')['total_aktual']);
        $this->assertEquals(150000.0, $this->cari($json['per_kategori'], 'kategori', 'Spare Part · Aki')['total_aktual']);

        $this->assertEquals(1000000.0, $this->cari($json['per_departemen'], 'departemen', 'Operasional')['total_aktual']);
        $tanpaDept = $this->cari($json['per_departemen'], 'departemen', 'Tanpa Departemen');
        $this->assertEquals(300630000.0, $tanpaDept['total_aktual']);
        $this->assertSame(3, $tanpaDept['jumlah']);
        $this->assertEquals(150000.0, $this->cari($json['per_departemen'], 'departemen', 'Pembelian Langsung')['total_aktual']);

        $this->assertEquals(1000000.0, $this->cari($json['per_supplier'], 'supplier', 'Toko Umum')['total_aktual']);
        $this->assertEquals(390000.0, $this->cari($json['per_supplier'], 'supplier', 'Toko Sparepart')['total_aktual']);
        $this->assertEquals(150000.0, $this->cari($json['per_supplier'], 'supplier', 'Toko Aki')['total_aktual']);
        $tanpaSupplier = $this->cari($json['per_supplier'], 'supplier', 'Tanpa Supplier');
        $this->assertEquals(300240000.0, $tanpaSupplier['total_aktual']);
        $this->assertSame(2, $tanpaSupplier['jumlah']);

        $this->assertCount(1, $json['per_armada']);
        $this->assertSame('B 1111 SP', $json['per_armada'][0]['nopol']);
        $this->assertEquals(390000.0, $json['per_armada'][0]['total_aktual']);
        $this->assertEquals(390000.0, $json['sparepart_tanpa_armada']['total_aktual']);
        $this->assertSame(2, $json['sparepart_tanpa_armada']['jumlah']);
        $totalArmadaGabungan = $json['per_armada'][0]['total_aktual'] + $json['sparepart_tanpa_armada']['total_aktual'];
        $this->assertEquals($sparepart['total_aktual'], $totalArmadaGabungan);

        $this->assertCount(1, $json['aset']);
        $asetRow = $json['aset'][0];
        $this->assertSame($data['idAset'], $asetRow['id_permintaan']);
        $this->assertSame(2, $asetRow['unit_total']);
        $this->assertSame(2, $asetRow['unit_terdaftar']);
        $this->assertEquals(300000000.0, $asetRow['total_aktual']);
        $this->assertEquals(200000000.0, $asetRow['terbayar']);
        $this->assertEquals(100000000.0, $asetRow['sisa']);
        $this->assertSame('diterima', $asetRow['status']);

        $this->assertCount(1, $json['menunggu_lama']);
        $this->assertSame('PR Menunggu Lama', $json['menunggu_lama'][0]['judul']);
        $this->assertSame(30, $json['menunggu_lama'][0]['umur_hari']);

        $leadUmum = $this->cari($json['lead_time_per_tipe'], 'tipe', 'umum');
        $leadSparepart = $this->cari($json['lead_time_per_tipe'], 'tipe', 'sparepart');
        $leadAset = $this->cari($json['lead_time_per_tipe'], 'tipe', 'aset');
        $this->assertEquals(9.0, $leadUmum['rata_hari']);
        $this->assertSame(1, $leadUmum['jumlah']);
        $this->assertEquals(6.0, $leadSparepart['rata_hari']);
        $this->assertSame(1, $leadSparepart['jumlah']);
        $this->assertEquals(14.0, $leadAset['rata_hari']);
        $this->assertSame(1, $leadAset['jumlah']);
    }

    public function test_filter_tipe_umum_mengecualikan_ps_langsung_dan_sparepart(): void
    {
        $this->seedGabunganLengkap();

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson('/api/permintaan-pembelian/laporan?dari=2026-09-01&sampai=2026-09-30&tipe=umum')->assertStatus(200);
        $json = $res->json('data');

        $this->assertSame(1, $json['ringkasan']['jumlah']);
        $this->assertEquals(1000000.0, $json['ringkasan']['total_aktual']);
        $this->assertCount(1, $json['per_tipe']);
        $this->assertSame('umum', $json['per_tipe'][0]['tipe']);
        $this->assertSame([], $json['aset']);
        $this->assertSame([], $json['per_armada']);
        $this->assertSame(0, $json['sparepart_tanpa_armada']['jumlah']);
    }

    public function test_akses_laporan_dibatasi_peran(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/permintaan-pembelian/laporan')->assertStatus(403)
            ->assertJsonPath('message', 'Laporan pembelian hanya untuk Superadmin, Admin, Manager, Pengadaan, dan Keuangan');

        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/permintaan-pembelian/laporan')->assertStatus(200);
    }

    public function test_export_laporan_excel_dan_pdf_200(): void
    {
        $this->seedGabunganLengkap();
        $this->actingAsRole('SUPERADMIN');

        $resExcel = $this->get('/api/permintaan-pembelian/laporan/export/excel?dari=2026-09-01&sampai=2026-09-30');
        $resExcel->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $resExcel->headers->get('content-type'));

        $resPdf = $this->get('/api/permintaan-pembelian/laporan/export/pdf?dari=2026-09-01&sampai=2026-09-30');
        $resPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $resPdf->headers->get('content-type'));
    }

    public function test_lead_time_tidak_negatif_saat_tanggal_pembelian_sebelum_tanggal_permintaan(): void
    {
        $this->insertPr([
            'tipe' => 'umum', 'status' => 'diterima',
            'tanggal_permintaan' => '2026-09-10', 'tanggal_pembelian' => '2026-09-05', 'tanggal_diterima' => '2026-09-05',
            'total_estimasi' => 100000, 'total_aktual' => 100000, 'judul' => 'PR Tanggal Mundur',
        ], [
            ['jenis' => 'barang', 'nama_item' => 'Item Mundur', 'qty' => 1, 'satuan' => 'pcs', 'harga_estimasi' => 100000, 'harga_aktual' => 100000],
        ]);

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson('/api/permintaan-pembelian/laporan?dari=2026-09-01&sampai=2026-09-30')->assertStatus(200);
        $json = $res->json('data');

        $this->assertEquals(0.0, $json['ringkasan']['rata_lead_time_hari']);
        $leadUmum = $this->cari($json['lead_time_per_tipe'], 'tipe', 'umum');
        $this->assertEquals(0.0, $leadUmum['rata_hari']);
    }

    public function test_per_armada_tetap_tampil_dengan_nopol_asli_saat_perawatan_sudah_dihapus(): void
    {
        $idPerawatan = $this->makePerawatan('B 2222 XX');
        $this->insertPr([
            'tipe' => 'sparepart', 'status' => 'diterima', 'id_perawatan' => $idPerawatan,
            'tanggal_permintaan' => '2026-09-01', 'tanggal_pembelian' => '2026-09-02', 'tanggal_diterima' => '2026-09-03',
            'total_estimasi' => 100000, 'total_aktual' => 100000, 'judul' => 'Sparepart Perawatan Dihapus',
        ], [
            ['jenis' => 'sparepart', 'nama_item' => 'Filter', 'satuan' => 'pcs', 'qty' => 1, 'harga_estimasi' => 100000, 'harga_aktual' => 100000],
        ]);

        DB::table('perawatan_armada')->where('id_perawatan', $idPerawatan)->update(['dihapus_pada' => now()]);

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson('/api/permintaan-pembelian/laporan?dari=2026-09-01&sampai=2026-09-30')->assertStatus(200);
        $json = $res->json('data');

        $this->assertCount(1, $json['per_armada']);
        $this->assertSame('B 2222 XX', $json['per_armada'][0]['nopol']);
        $this->assertEquals(100000.0, $json['per_armada'][0]['total_aktual']);
        $this->assertSame(0, $json['sparepart_tanpa_armada']['jumlah']);
    }

    public function test_laporan_menolak_parameter_tidak_valid(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/permintaan-pembelian/laporan?tipe=barang')->assertStatus(422);
        $this->getJson('/api/permintaan-pembelian/laporan?dari=abc')->assertStatus(422);
        $this->getJson('/api/permintaan-pembelian/laporan?dari=2026-09-10&sampai=2026-09-01')->assertStatus(422);
        $this->get('/api/permintaan-pembelian/laporan/export/excel?dari=abc')->assertStatus(422);
    }

    public function test_ekspor_excel_judul_berbahaya_disimpan_sebagai_teks_bukan_formula(): void
    {
        $judulBerbahaya = '=HYPERLINK("http://contoh.invalid","x")';
        $this->insertPr([
            'tipe' => 'umum', 'status' => 'disetujui',
            'tanggal_permintaan' => now()->toDateString(),
            'total_estimasi' => 50000, 'judul' => $judulBerbahaya,
        ], [
            ['jenis' => 'barang', 'nama_item' => 'Item Uji', 'qty' => 1, 'satuan' => 'pcs', 'harga_estimasi' => 50000],
        ]);

        $this->actingAsRole('SUPERADMIN');
        $res = $this->get('/api/permintaan-pembelian/laporan/export/excel');
        $res->assertStatus(200);

        $spreadsheet = IOFactory::load($res->getFile()->getPathname());
        $sheet = $spreadsheet->getSheetByName('Menunggu Lama');
        $cell = $sheet->getCell('B4');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame($judulBerbahaya, $cell->getValue());
    }
}
