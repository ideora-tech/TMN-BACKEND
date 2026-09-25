<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalRincianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function buatEventType(string $kode, ?string $idPerusahaan = null): string
    {
        $idPerusahaan ??= self::PERUSAHAAN_ID;
        $id = DB::table('approval_event_type')
            ->where('id_perusahaan', $idPerusahaan)->where('kode', $kode)->value('id_event_type');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => $kode,
            'nama' => 'Approval ' . $kode, 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatApproval(string $kode, string $idReferensi, ?string $idPerusahaan = null): string
    {
        $idPerusahaan ??= self::PERUSAHAAN_ID;
        $id = (string) Str::uuid();
        DB::table('approval_pengajuan')->insert([
            'id_approval' => $id, 'id_perusahaan' => $idPerusahaan,
            'id_event_type' => $this->buatEventType($kode, $idPerusahaan),
            'id_referensi' => $idReferensi, 'id_pengguna_pengaju' => (string) Str::uuid(),
            'nominal' => 1000000, 'status' => 'menunggu', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function rincian(string $idApproval): TestResponse
    {
        return $this->getJson("/api/approval-pengajuan/{$idApproval}/rincian");
    }

    private function nilaiInfo(TestResponse $res, string $label): mixed
    {
        $baris = collect($res->json('data.rincian.info'))->firstWhere('label', $label);
        $this->assertNotNull($baris, "Baris info '{$label}' tidak ditemukan");
        return $baris['value'];
    }

    private function barisInfo(TestResponse $res, string $label): array
    {
        $baris = collect($res->json('data.rincian.info'))->firstWhere('label', $label);
        $this->assertNotNull($baris, "Baris info '{$label}' tidak ditemukan");
        return $baris;
    }

    private function buatKlien(string $nama = 'PT Klien Maju', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien' => 'KL-' . Str::random(8), 'nama_klien' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatVendor(string $nama = 'PT Vendor Angkut'): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VD-' . Str::random(8), 'nama_vendor' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatProyek(string $idKlien, string $nama = 'Proyek Distribusi', array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert(array_merge([
            'id_proyek' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => $nama, 'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function buatRute(string $nama = 'Jakarta - Bandung'): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatJenisKendaraan(string $nama = 'Truk Engkel'): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatDepartemen(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_departemen' => 'DP-' . Str::random(6), 'nama_departemen' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatSupplier(string $nama = 'Bengkel Maju'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPengajuan(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert(array_merge([
            'id_pengajuan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PP-202609-0001',
            'kategori' => 'lainnya', 'nominal' => 500000, 'tanggal_pengajuan' => '2026-09-24',
            'penerima' => 'Budi Santoso', 'keterangan' => 'Beli alat tulis', 'status' => 'menunggu_approval',
            'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function buatTenantLain(): array
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'PT Lain', 'dibuat_pada' => now()]);
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'MANAGER',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
        return [$idLain, $pengguna];
    }

    public function test_rincian_permintaan_pembelian_memuat_info_dan_item(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $idEventType = $this->buatEventType('permintaan_pembelian');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);
        $idDepartemen = $this->buatDepartemen('BOD');

        $pengaju = $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Benerin mesin cuci', 'tipe' => 'umum', 'alasan' => 'Mesin cuci bengkel rusak',
            'id_departemen' => $idDepartemen, 'tanggal_permintaan' => '2026-09-24', 'tanggal_dibutuhkan' => '2026-09-30',
            'items' => [
                ['jenis' => 'barang', 'nama_item' => 'Filter Oli', 'qty' => 2, 'satuan' => 'pcs', 'harga_estimasi' => 85000],
                ['jenis' => 'jasa', 'nama_item' => 'Jasa Teknisi', 'qty' => 1, 'satuan' => 'kali', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');
        $this->assertSame('menunggu_approval', $pr['status']);
        $idApproval = (string) DB::table('approval_pengajuan')->where('id_referensi', $pr['id_permintaan'])->value('id_approval');

        Sanctum::actingAs($approver, ['*']);
        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kode', 'permintaan_pembelian')
            ->assertJsonPath('data.rincian.kode', 'permintaan_pembelian')
            ->assertJsonPath('data.rincian.judul', "{$pr['nomor_permintaan']} · Benerin mesin cuci")
            ->assertJsonPath('data.rincian.link', '/permintaan-pembelian?detail=' . $pr['id_permintaan'])
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Item')
            ->assertJsonPath('data.rincian.bagian.0.total.label', 'Total Estimasi')
            ->assertJsonPath('data.rincian.bagian.0.total.value', 470000);

        $this->assertSame($pengaju->username, $this->nilaiInfo($res, 'Pengaju'));
        $this->assertSame('BOD', $this->nilaiInfo($res, 'Departemen'));
        $this->assertSame('Umum', $this->nilaiInfo($res, 'Tipe'));
        $this->assertSame('2026-09-24', $this->nilaiInfo($res, 'Tanggal'));
        $this->assertSame('2026-09-30', $this->nilaiInfo($res, 'Dibutuhkan'));
        $this->assertSame('Mesin cuci bengkel rusak', $this->nilaiInfo($res, 'Alasan'));
        $this->assertEquals(['label' => 'Total Estimasi', 'value' => 470000, 'tipe' => 'rupiah'], $this->barisInfo($res, 'Total Estimasi'));

        $kolom = collect($res->json('data.rincian.bagian.0.kolom'));
        $this->assertSame(['nama', 'qty', 'satuan', 'harga', 'subtotal'], $kolom->pluck('key')->all());
        $this->assertSame(['key' => 'qty', 'label' => 'Qty', 'align' => 'right', 'tipe' => 'angka'], $kolom->firstWhere('key', 'qty'));
        $this->assertSame(['key' => 'harga', 'label' => 'Harga Estimasi', 'align' => 'right', 'tipe' => 'rupiah'], $kolom->firstWhere('key', 'harga'));

        $baris = collect($res->json('data.rincian.bagian.0.baris'))->keyBy('nama');
        $this->assertCount(2, $baris);
        $this->assertEquals(['nama' => 'Filter Oli', 'qty' => 2, 'satuan' => 'pcs', 'harga' => 85000, 'subtotal' => 170000], $baris['Filter Oli']);
        $this->assertEquals(300000, $baris['Jasa Teknisi']['subtotal']);
        $this->assertCount(1, $res->json('data.rincian.bagian'));
    }

    public function test_rincian_permintaan_pembelian_aset_memuat_unit_dan_termin(): void
    {
        $this->actingAsRole('MANAGER');
        $idJenis = $this->buatJenisKendaraan('Truk Engkel');
        $idPengaju = (string) DB::table('pengguna')->value('id_pengguna');
        $idPermintaan = (string) Str::uuid();
        DB::table('permintaan_pembelian')->insert([
            'id_permintaan' => $idPermintaan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_permintaan' => 'PR-202609-0009',
            'id_pengaju' => $idPengaju, 'tipe' => 'aset', 'tanggal_permintaan' => '2026-09-20',
            'judul' => 'Pengadaan 2 unit truk', 'alasan' => 'Ekspansi armada', 'status' => 'menunggu_approval',
            'total_estimasi' => 700000000, 'dibuat_pada' => now(),
        ]);
        DB::table('permintaan_pembelian_item')->insert([
            'id_item' => (string) Str::uuid(), 'id_permintaan' => $idPermintaan, 'jenis' => 'aset', 'id_jenis_kendaraan' => $idJenis,
            'merk' => 'Hino', 'model' => 'Dutro', 'tahun' => 2026, 'nama_item' => 'Hino Dutro 2026',
            'qty' => 2, 'satuan' => 'unit', 'harga_estimasi' => 350000000, 'dibuat_pada' => now(),
        ]);
        foreach ([[1, 'DP 30%', 210000000], [2, 'Pelunasan', 490000000]] as [$urutan, $nama, $nominal]) {
            DB::table('permintaan_pembelian_termin')->insert([
                'id_termin' => (string) Str::uuid(), 'id_permintaan' => $idPermintaan, 'urutan' => $urutan,
                'nama' => $nama, 'nominal' => $nominal, 'jatuh_tempo' => '2026-10-0' . $urutan, 'status' => 'menunggu',
                'dibuat_pada' => now(),
            ]);
        }
        $idApproval = $this->buatApproval('permintaan_pembelian_aset', $idPermintaan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'permintaan_pembelian_aset')
            ->assertJsonPath('data.rincian.judul', 'PR-202609-0009 · Pengadaan 2 unit truk')
            ->assertJsonPath('data.rincian.link', "/permintaan-pembelian?detail={$idPermintaan}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Item')
            ->assertJsonPath('data.rincian.bagian.0.kolom.0.label', 'Unit')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.nama', 'Hino Dutro 2026 · Truk Engkel')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.satuan', 'unit')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 700000000)
            ->assertJsonPath('data.rincian.bagian.1.judul', 'Termin')
            ->assertJsonPath('data.rincian.bagian.1.baris.0.nama', 'DP 30%')
            ->assertJsonPath('data.rincian.bagian.1.baris.1.jatuh_tempo', '2026-10-02')
            ->assertJsonPath('data.rincian.bagian.1.total.label', 'Total Termin')
            ->assertJsonPath('data.rincian.bagian.1.total.value', 700000000);
        $this->assertSame('Aset', $this->nilaiInfo($res, 'Tipe'));
    }

    public function test_rincian_pengajuan_pengeluaran_memuat_info_utama(): void
    {
        $this->actingAsRole('MANAGER');
        $idPengajuan = $this->buatPengajuan(['kategori' => 'pembayaran_pinjaman', 'penerima' => 'Bank Mandiri', 'nominal' => 12500000.5]);
        $idApproval = $this->buatApproval('pembayaran_pinjaman', $idPengajuan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'pembayaran_pinjaman')
            ->assertJsonPath('data.rincian.judul', 'PP-202609-0001 · Beli alat tulis')
            ->assertJsonPath('data.rincian.link', '/proses-pembayaran')
            ->assertJsonPath('data.rincian.bagian', []);
        $this->assertSame('PP-202609-0001', $this->nilaiInfo($res, 'No. Pengajuan'));
        $this->assertSame('Pembayaran Pinjaman', $this->nilaiInfo($res, 'Kategori'));
        $this->assertSame('Bank Mandiri', $this->nilaiInfo($res, 'Penerima'));
        $this->assertEquals(['label' => 'Nominal', 'value' => 12500000.5, 'tipe' => 'rupiah'], $this->barisInfo($res, 'Nominal'));
        $this->assertEquals(['label' => 'Tanggal Pengajuan', 'value' => '2026-09-24', 'tipe' => 'tanggal'], $this->barisInfo($res, 'Tanggal Pengajuan'));
        $this->assertSame('Beli alat tulis', $this->nilaiInfo($res, 'Keterangan'));
    }

    public function test_rincian_pengeluaran_tanpa_keterangan_memakai_label_kategori_di_judul(): void
    {
        $this->actingAsRole('MANAGER');
        $idPengajuan = $this->buatPengajuan(['kategori' => 'uang_jalan', 'keterangan' => null]);
        $idApproval = $this->buatApproval('uang_jalan', $idPengajuan);

        $this->rincian($idApproval)->assertStatus(200)
            ->assertJsonPath('data.rincian.judul', 'PP-202609-0001 · Uang Jalan');
    }

    public function test_rincian_pengeluaran_perawatan_memuat_sparepart_dan_total_jasa_plus_sparepart(): void
    {
        $this->actingAsRole('MANAGER');
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 9002 TMN',
            'merk' => 'Hino', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'interval_km' => 10000, 'interval_bulan' => 6, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada, 'id_supplier' => $this->buatSupplier('Bengkel Maju'),
            'id_interval_perawatan' => $idInterval, 'tanggal' => '2026-09-10', 'biaya' => 250000,
            'status' => 'selesai', 'keterangan' => 'Servis berkala', 'dibuat_pada' => now(),
        ]);
        DB::table('perawatan_sparepart')->insert([
            'id_perawatan_sparepart' => (string) Str::uuid(), 'id_perawatan' => $idPerawatan, 'sumber' => 'bengkel',
            'nama_sparepart' => 'Oli Mesin', 'qty' => 2, 'harga' => 60000, 'dibuat_pada' => now(),
        ]);
        DB::table('perawatan_sparepart')->insert([
            'id_perawatan_sparepart' => (string) Str::uuid(), 'id_perawatan' => $idPerawatan, 'sumber' => 'bengkel',
            'nama_sparepart' => 'Filter Oli', 'qty' => 1, 'harga' => 45000, 'dibuat_pada' => now(), 'dihapus_pada' => now(),
        ]);
        $idPengajuan = $this->buatPengajuan([
            'kategori' => 'perawatan', 'id_perawatan' => $idPerawatan, 'nominal' => 370000,
            'penerima' => 'B 9002 TMN', 'keterangan' => 'Tiap 10.000 km / 6 bulan - B 9002 TMN',
        ]);
        $idApproval = $this->buatApproval('perawatan', $idPengajuan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'perawatan')
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Sparepart Dipakai')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.nama', 'Oli Mesin')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.qty', 2)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.harga', 60000)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 120000)
            ->assertJsonPath('data.rincian.bagian.0.total.label', 'Total Jasa + Sparepart')
            ->assertJsonPath('data.rincian.bagian.0.total.value', 370000);
        $this->assertCount(1, $res->json('data.rincian.bagian.0.baris'));
        $this->assertSame('B 9002 TMN', $this->nilaiInfo($res, 'Penerima'));
        $this->assertSame('B 9002 TMN · Hino', $this->nilaiInfo($res, 'Armada'));
        $this->assertSame('Tiap 10.000 km / 6 bulan', $this->nilaiInfo($res, 'Paket Perawatan'));
        $this->assertSame('2026-09-10', $this->nilaiInfo($res, 'Tanggal Perawatan'));
        $this->assertSame('Bengkel Maju', $this->nilaiInfo($res, 'Bengkel'));
        $this->assertEquals(250000, $this->nilaiInfo($res, 'Biaya Jasa'));
        $this->assertSame('Servis berkala', $this->nilaiInfo($res, 'Keterangan Perawatan'));
    }

    public function test_rincian_pengeluaran_perawatan_tanpa_paket_memakai_label_perbaikan(): void
    {
        $this->actingAsRole('MANAGER');
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 7777 TMN', 'dibuat_pada' => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada, 'tanggal' => '2026-09-12', 'biaya' => 100000,
            'status' => 'selesai', 'dibuat_pada' => now(),
        ]);
        $idPengajuan = $this->buatPengajuan(['kategori' => 'perawatan', 'id_perawatan' => $idPerawatan]);
        $idApproval = $this->buatApproval('pengajuan_pengeluaran', $idPengajuan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)->assertJsonPath('data.rincian.bagian', []);
        $this->assertSame('Perbaikan', $this->nilaiInfo($res, 'Paket Perawatan'));
        $this->assertSame('B 7777 TMN', $this->nilaiInfo($res, 'Armada'));
        $this->assertNull($this->nilaiInfo($res, 'Bengkel'));
    }

    public function test_rincian_pengeluaran_sparepart_memuat_item_pembelian(): void
    {
        $this->actingAsRole('MANAGER');
        $idPembelian = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian' => $idPembelian, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PS-202609-0004',
            'id_supplier' => $this->buatSupplier('Toko Sparepart Jaya'), 'status' => 'diajukan',
            'total_estimasi' => 205000, 'tanggal_pengajuan' => '2026-09-22', 'keterangan' => 'Stok bulanan',
            'dibuat_pada' => now(),
        ]);
        DB::table('pembelian_sparepart_item')->insert([
            ['id_item' => (string) Str::uuid(), 'id_pembelian' => $idPembelian, 'id_sparepart' => (string) Str::uuid(),
                'nama_sparepart' => 'Filter Udara', 'qty' => 3, 'harga_estimasi' => 60000, 'dibuat_pada' => now()],
            ['id_item' => (string) Str::uuid(), 'id_pembelian' => $idPembelian, 'id_sparepart' => (string) Str::uuid(),
                'nama_sparepart' => 'Kampas Rem', 'qty' => 1, 'harga_estimasi' => 25000, 'dibuat_pada' => now()->addSecond()],
        ]);
        $idPengajuan = $this->buatPengajuan([
            'kategori' => 'sparepart', 'id_pembelian' => $idPembelian, 'nominal' => 205000, 'penerima' => 'Toko Sparepart Jaya',
        ]);
        $idApproval = $this->buatApproval('sparepart', $idPengajuan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'sparepart')
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Item Pembelian')
            ->assertJsonPath('data.rincian.bagian.0.kolom.2.label', 'Harga Estimasi')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.nama', 'Filter Udara')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 180000)
            ->assertJsonPath('data.rincian.bagian.0.baris.1.nama', 'Kampas Rem')
            ->assertJsonPath('data.rincian.bagian.0.total.label', 'Total Estimasi')
            ->assertJsonPath('data.rincian.bagian.0.total.value', 205000);
        $this->assertSame('PS-202609-0004', $this->nilaiInfo($res, 'No. Pembelian'));
        $this->assertSame('Toko Sparepart Jaya', $this->nilaiInfo($res, 'Supplier'));
        $this->assertSame('2026-09-22', $this->nilaiInfo($res, 'Tanggal Pengajuan Pembelian'));
        $this->assertEquals(205000, $this->nilaiInfo($res, 'Total Estimasi'));
        $this->assertSame('Stok bulanan', $this->nilaiInfo($res, 'Keterangan Pembelian'));
    }

    public function test_rincian_penawaran_memuat_klien_dan_item(): void
    {
        $this->actingAsRole('MANAGER');
        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaran, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->buatKlien('PT Klien Maju'),
            'nomor_penawaran' => 'PNW-202609-0002', 'judul' => 'Distribusi Jawa', 'nilai_penawaran' => 9000000,
            'status' => 'menunggu_approval', 'tipe_harga' => 'per_rit', 'tanggal_penawaran' => '2026-09-15',
            'jumlah_hari' => 30, 'dibuat_pada' => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_penawaran' => $idPenawaran,
            'id_rute' => $this->buatRute('Jakarta - Bandung'), 'id_jenis_kendaraan' => $this->buatJenisKendaraan('Fuso'),
            'harga_satuan' => 1500000, 'estimasi_ritase' => 6, 'subtotal' => 9000000, 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('penawaran', $idPenawaran);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'penawaran')
            ->assertJsonPath('data.rincian.judul', 'PNW-202609-0002 · Distribusi Jawa')
            ->assertJsonPath('data.rincian.link', "/penawaran/{$idPenawaran}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Item Penawaran')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.rute', 'Jakarta - Bandung')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', 'Fuso')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.ritase', 6)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.harga', 1500000)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 9000000)
            ->assertJsonPath('data.rincian.bagian.0.total.value', 9000000);
        $this->assertSame('PT Klien Maju', $this->nilaiInfo($res, 'Klien'));
        $this->assertSame('On Call', $this->nilaiInfo($res, 'Tipe Harga'));
        $this->assertSame('2026-09-15', $this->nilaiInfo($res, 'Tanggal Penawaran'));
        $this->assertSame(30, $this->nilaiInfo($res, 'Jumlah Hari'));
        $this->assertEquals(9000000, $this->nilaiInfo($res, 'Nilai Penawaran'));
    }

    public function test_rincian_penawaran_nilai_tetap_tanpa_harga_item_tidak_punya_total(): void
    {
        $this->actingAsRole('MANAGER');
        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaran, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->buatKlien(),
            'nomor_penawaran' => 'PNW-202609-0003', 'judul' => 'Sewa Bulanan', 'nilai_penawaran' => 45000000,
            'status' => 'menunggu_approval', 'tipe_harga' => 'unit_driver', 'dibuat_pada' => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_penawaran' => $idPenawaran,
            'id_rute' => $this->buatRute('Cikarang - Surabaya'), 'id_jenis_kendaraan' => null,
            'harga_satuan' => null, 'estimasi_ritase' => 1, 'subtotal' => 0, 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('penawaran', $idPenawaran);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.rute', 'Cikarang - Surabaya')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', null)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.harga', null)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', null);
        $this->assertArrayNotHasKey('total', $res->json('data.rincian.bagian.0'));
        $this->assertSame('Unit + Driver', $this->nilaiInfo($res, 'Tipe Harga'));
        $this->assertNull($this->nilaiInfo($res, 'Jumlah Hari'));
    }

    public function test_rincian_proyek_memuat_periode_nilai_dan_rute(): void
    {
        $this->actingAsRole('MANAGER');
        $idProyek = $this->buatProyek($this->buatKlien('PT Klien Maju'), 'Proyek Distribusi', [
            'kode_proyek' => 'PRJ-0007', 'tipe_harga' => 'per_rit', 'tanggal_mulai' => '2026-10-01',
            'tanggal_selesai' => '2026-12-31', 'harga_proyek' => 120000000, 'keterangan' => 'Kontrak tahunan',
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_proyek' => $idProyek,
            'id_rute' => $this->buatRute('Jakarta - Semarang'), 'id_jenis_kendaraan' => $this->buatJenisKendaraan('Wingbox'),
            'harga_penawaran' => 2500000, 'estimasi_ritase' => 4, 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('proyek', $idProyek);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'proyek')
            ->assertJsonPath('data.rincian.judul', 'PRJ-0007 · Proyek Distribusi')
            ->assertJsonPath('data.rincian.link', "/project/{$idProyek}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Rute Proyek')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.rute', 'Jakarta - Semarang')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', 'Wingbox')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.harga', 2500000)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 10000000)
            ->assertJsonPath('data.rincian.bagian.0.total.value', 10000000);
        $this->assertSame('PT Klien Maju', $this->nilaiInfo($res, 'Klien'));
        $this->assertSame('On Call', $this->nilaiInfo($res, 'Tipe Harga'));
        $this->assertSame('2026-10-01', $this->nilaiInfo($res, 'Tanggal Mulai'));
        $this->assertSame('2026-12-31', $this->nilaiInfo($res, 'Tanggal Selesai'));
        $this->assertNull($this->nilaiInfo($res, 'Harga Penawaran'));
        $this->assertEquals(120000000, $this->nilaiInfo($res, 'Harga Proyek'));
        $this->assertSame('Kontrak tahunan', $this->nilaiInfo($res, 'Keterangan'));
    }

    public function test_rincian_faktur_memuat_pajak_dan_item(): void
    {
        $this->actingAsRole('MANAGER');
        $idKlien = $this->buatKlien('PT Klien Maju');
        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $idFaktur, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'id_proyek' => $this->buatProyek($idKlien, 'Proyek Distribusi'), 'nomor_faktur' => 'INV-202609-0011',
            'total' => 5550000, 'status' => 'menunggu_approval', 'tanggal_faktur' => '2026-09-23',
            'jatuh_tempo' => '2026-10-23', 'dibuat_pada' => now(),
        ]);
        DB::table('faktur_item')->insert([
            'id_faktur_item' => (string) Str::uuid(), 'id_faktur' => $idFaktur, 'deskripsi' => 'Jasa angkutan — 5 rit',
            'qty' => 5, 'harga_satuan' => 1000000, 'subtotal' => 5000000, 'dibuat_pada' => now(),
        ]);
        DB::table('faktur_pajak')->insert([
            'id_faktur_pajak' => (string) Str::uuid(), 'id_faktur' => $idFaktur, 'nama' => 'PPN', 'persen' => 11,
            'urutan' => 1, 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('faktur', $idFaktur);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'faktur')
            ->assertJsonPath('data.rincian.judul', 'INV-202609-0011 · Proyek Distribusi')
            ->assertJsonPath('data.rincian.link', "/faktur/{$idFaktur}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Item Faktur')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.deskripsi', 'Jasa angkutan — 5 rit')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.qty', 5)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.harga', 1000000)
            ->assertJsonPath('data.rincian.bagian.0.baris.0.subtotal', 5000000)
            ->assertJsonPath('data.rincian.bagian.0.total.label', 'Subtotal')
            ->assertJsonPath('data.rincian.bagian.0.total.value', 5000000);
        $this->assertSame('PT Klien Maju', $this->nilaiInfo($res, 'Klien'));
        $this->assertSame('Proyek Distribusi', $this->nilaiInfo($res, 'Proyek'));
        $this->assertSame('2026-09-23', $this->nilaiInfo($res, 'Tanggal Faktur'));
        $this->assertSame('2026-10-23', $this->nilaiInfo($res, 'Jatuh Tempo'));
        $this->assertSame('PPN 11%', $this->nilaiInfo($res, 'Pajak'));
        $this->assertEquals(5550000, $this->nilaiInfo($res, 'Total'));
    }

    public function test_rincian_faktur_memakai_kolom_pajak_lama_bila_tabel_pajak_kosong(): void
    {
        $this->actingAsRole('MANAGER');
        $idKlien = $this->buatKlien('PT Klien Maju');
        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $idFaktur, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_faktur' => 'INV-202609-0012', 'total' => 1020000, 'nama_pajak' => 'PPh 23', 'persen_pajak' => 2.5,
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('faktur', $idFaktur);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.rincian.judul', 'INV-202609-0012 · PT Klien Maju')
            ->assertJsonPath('data.rincian.bagian', []);
        $this->assertSame('PPh 23 2.5%', $this->nilaiInfo($res, 'Pajak'));
    }

    public function test_rincian_invoice_vendor_memuat_nilai_dan_trip(): void
    {
        $this->actingAsRole('MANAGER');
        $idVendor = $this->buatVendor('PT Vendor Angkut');
        $idKontrak = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $idKontrak, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_kontrak' => 'KV-2026-01', 'mekanisme' => 'unit_only', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idInvoice = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoice, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'id_kontrak_vendor' => $idKontrak, 'nomor_invoice' => 'IV-2026-0005', 'tanggal_invoice' => '2026-09-05',
            'jatuh_tempo' => '2026-10-05', 'periode_dari' => '2026-08-01', 'periode_sampai' => '2026-08-31',
            'dpp' => 10000000, 'ppn' => 1100000, 'pph' => 200000, 'total' => 10900000, 'status' => 'menunggu_approval',
            'status_pembayaran' => 'belum', 'keterangan' => 'Sewa Agustus', 'dibuat_pada' => now(),
        ]);
        $idProyek = $this->buatProyek($this->buatKlien(), 'Proyek Vendor');
        $idArmadaVendor = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmadaVendor, 'id_vendor' => $idVendor, 'nopol' => 'B 4455 VDR', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idSupirVendor = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor, 'id_vendor' => $idVendor, 'nama' => 'Supir Vendor Satu', 'dibuat_pada' => now(),
        ]);
        $idPenugasan = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $idPenugasan, 'id_proyek' => $idProyek, 'sumber' => 'vendor', 'id_kontrak_vendor' => $idKontrak,
            'id_armada_vendor' => $idArmadaVendor, 'id_supir_vendor' => $idSupirVendor, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal' => $idJadwal, 'id_penugasan' => $idPenugasan, 'rute' => 'Jakarta - Surabaya',
            'waktu_berangkat' => '2026-08-15 08:00:00', 'dibuat_pada' => now(),
        ]);
        $idTrip = (string) Str::uuid();
        DB::table('trip')->insert(['id_trip' => $idTrip, 'id_jadwal' => $idJadwal, 'status' => 'selesai', 'dibuat_pada' => now()]);
        DB::table('invoice_vendor_trip')->insert([
            'id_invoice_vendor_trip' => (string) Str::uuid(), 'id_invoice_vendor' => $idInvoice, 'id_trip' => $idTrip, 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('invoice_vendor', $idInvoice);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'invoice_vendor')
            ->assertJsonPath('data.rincian.judul', 'IV-2026-0005 · PT Vendor Angkut')
            ->assertJsonPath('data.rincian.link', "/invoice-vendor/{$idInvoice}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Trip Ditagihkan')
            ->assertJsonPath('data.rincian.bagian.0.kolom.0.tipe', 'tanggal')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.tanggal', '2026-08-15')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.rute', 'Jakarta - Surabaya')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.nopol', 'B 4455 VDR')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.driver', 'Supir Vendor Satu')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.proyek', 'Proyek Vendor');
        $this->assertSame('PT Vendor Angkut', $this->nilaiInfo($res, 'Vendor'));
        $this->assertSame('KV-2026-01', $this->nilaiInfo($res, 'No. Kontrak'));
        $this->assertSame('2026-09-05', $this->nilaiInfo($res, 'Tanggal Invoice'));
        $this->assertSame('2026-08-01', $this->nilaiInfo($res, 'Periode Dari'));
        $this->assertSame('2026-08-31', $this->nilaiInfo($res, 'Periode Sampai'));
        $this->assertEquals(10000000, $this->nilaiInfo($res, 'DPP'));
        $this->assertEquals(1100000, $this->nilaiInfo($res, 'PPN'));
        $this->assertEquals(200000, $this->nilaiInfo($res, 'PPh'));
        $this->assertEquals(10900000, $this->nilaiInfo($res, 'Total'));
        $this->assertSame('Sewa Agustus', $this->nilaiInfo($res, 'Keterangan'));
    }

    public function test_rincian_invoice_vendor_tanpa_trip_tidak_punya_bagian(): void
    {
        $this->actingAsRole('MANAGER');
        $idInvoice = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoice, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $this->buatVendor(),
            'nomor_invoice' => 'IV-2026-0006', 'tanggal_invoice' => '2026-09-06', 'no_kontrak' => 'KTR-MANUAL',
            'dpp' => 1000000, 'ppn' => 0, 'pph' => 0, 'total' => 1000000, 'status' => 'menunggu_approval',
            'status_pembayaran' => 'belum', 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('invoice_vendor', $idInvoice);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)->assertJsonPath('data.rincian.bagian', []);
        $this->assertSame('KTR-MANUAL', $this->nilaiInfo($res, 'No. Kontrak'));
    }

    public function test_rincian_kontrak_vendor_memuat_nilai_unit_dan_supir(): void
    {
        $this->actingAsRole('MANAGER');
        $idVendor = $this->buatVendor('PT Vendor Angkut');
        $idKontrak = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $idKontrak, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'id_proyek' => $this->buatProyek($this->buatKlien(), 'Proyek Vendor'),
            'nomor_kontrak' => 'KV-2026-07', 'mekanisme' => 'unit_driver', 'nilai_kontrak' => 60000000, 'rate' => 3000000,
            'satuan' => 'per hari', 'pajak_persen' => 11, 'termin_pembayaran_hari' => 30, 'jumlah_trip' => 20, 'jumlah_hari' => 20,
            'tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2026-10-31', 'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);
        $armadaVendor = [
            ['id_kontrak_vendor' => $idKontrak, 'nopol' => 'B 1111 VDR', 'merk' => 'Hino', 'jenis' => 'Truk Box'],
            ['id_kontrak_vendor' => $idKontrak, 'nopol' => 'B 2222 VDR', 'merk' => 'Isuzu', 'id_jenis_kendaraan' => $this->buatJenisKendaraan('Fuso')],
            ['id_kontrak_vendor' => (string) Str::uuid(), 'nopol' => 'B 9999 XXX'],
        ];
        foreach ($armadaVendor as $baris) {
            DB::table('armada_vendor')->insert(array_merge([
                'id_armada_vendor' => (string) Str::uuid(), 'id_vendor' => $idVendor, 'aktif' => 1, 'dibuat_pada' => now(),
            ], $baris));
        }
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => (string) Str::uuid(), 'id_vendor' => $idVendor, 'id_kontrak_vendor' => $idKontrak,
            'nama' => 'Andi Supir', 'dibuat_pada' => now(),
        ]);
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => (string) Str::uuid(), 'id_vendor' => $idVendor, 'id_kontrak_vendor' => $idKontrak,
            'nama' => 'Bayu Supir', 'dibuat_pada' => now(), 'dihapus_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('kontrak_vendor', $idKontrak);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'kontrak_vendor')
            ->assertJsonPath('data.rincian.judul', 'KV-2026-07 · PT Vendor Angkut')
            ->assertJsonPath('data.rincian.link', "/kontrak-vendor/{$idKontrak}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Unit')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.nopol', 'B 1111 VDR')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', 'Truk Box')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.merk', 'Hino')
            ->assertJsonPath('data.rincian.bagian.0.baris.1.nopol', 'B 2222 VDR')
            ->assertJsonPath('data.rincian.bagian.0.baris.1.jenis', 'Fuso')
            ->assertJsonPath('data.rincian.bagian.1.judul', 'Supir')
            ->assertJsonPath('data.rincian.bagian.1.baris.0.nama', 'Andi Supir');
        $this->assertCount(2, $res->json('data.rincian.bagian.0.baris'));
        $this->assertCount(1, $res->json('data.rincian.bagian.1.baris'));
        $this->assertSame('PT Vendor Angkut', $this->nilaiInfo($res, 'Vendor'));
        $this->assertSame('Proyek Vendor', $this->nilaiInfo($res, 'Proyek'));
        $this->assertSame('Unit + Driver', $this->nilaiInfo($res, 'Mekanisme'));
        $this->assertEquals(60000000, $this->nilaiInfo($res, 'Nilai Kontrak'));
        $this->assertEquals(3000000, $this->nilaiInfo($res, 'Rate'));
        $this->assertSame('per hari', $this->nilaiInfo($res, 'Satuan'));
        $this->assertSame(11, $this->nilaiInfo($res, 'Pajak (%)'));
        $this->assertSame(30, $this->nilaiInfo($res, 'Termin Pembayaran (hari)'));
        $this->assertSame('2026-10-01', $this->nilaiInfo($res, 'Tanggal Mulai'));
        $this->assertSame('2026-10-31', $this->nilaiInfo($res, 'Tanggal Selesai'));
    }

    public function test_rincian_permintaan_vendor_memuat_unit_diminta(): void
    {
        $this->actingAsRole('MANAGER');
        $idPermintaan = (string) Str::uuid();
        DB::table('permintaan_vendor')->insert([
            'id_permintaan' => $idPermintaan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_permintaan' => 'PV-202609-0003',
            'id_proyek' => $this->buatProyek($this->buatKlien(), 'Proyek Vendor'), 'jumlah_unit' => 5, 'mekanisme' => 'full',
            'periode_dari' => '2026-10-01', 'periode_sampai' => '2026-10-15', 'catatan' => 'Butuh cepat',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);
        DB::table('permintaan_vendor_unit')->insert([
            ['id_permintaan_unit' => (string) Str::uuid(), 'id_permintaan' => $idPermintaan,
                'id_jenis_kendaraan' => $this->buatJenisKendaraan('Fuso'), 'jumlah_unit' => 3, 'urutan' => 1, 'dibuat_pada' => now()],
            ['id_permintaan_unit' => (string) Str::uuid(), 'id_permintaan' => $idPermintaan,
                'id_jenis_kendaraan' => $this->buatJenisKendaraan('Wingbox'), 'jumlah_unit' => 2, 'urutan' => 2, 'dibuat_pada' => now()],
        ]);
        $idApproval = $this->buatApproval('permintaan_vendor', $idPermintaan);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200)
            ->assertJsonPath('data.kode', 'permintaan_vendor')
            ->assertJsonPath('data.rincian.judul', 'PV-202609-0003 · Proyek Vendor')
            ->assertJsonPath('data.rincian.link', "/permintaan-vendor/{$idPermintaan}")
            ->assertJsonPath('data.rincian.bagian.0.judul', 'Unit Diminta')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', 'Fuso')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jumlah', 3)
            ->assertJsonPath('data.rincian.bagian.0.baris.1.jenis', 'Wingbox')
            ->assertJsonPath('data.rincian.bagian.0.baris.1.jumlah', 2);
        $this->assertSame('Proyek Vendor', $this->nilaiInfo($res, 'Proyek'));
        $this->assertSame('All In', $this->nilaiInfo($res, 'Mekanisme'));
        $this->assertSame('2026-10-01', $this->nilaiInfo($res, 'Periode Dari'));
        $this->assertSame('2026-10-15', $this->nilaiInfo($res, 'Periode Sampai'));
        $this->assertSame('Butuh cepat', $this->nilaiInfo($res, 'Catatan'));
    }

    public function test_rincian_permintaan_vendor_lama_tanpa_baris_unit_memakai_data_header(): void
    {
        $this->actingAsRole('MANAGER');
        $idPermintaan = (string) Str::uuid();
        DB::table('permintaan_vendor')->insert([
            'id_permintaan' => $idPermintaan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_permintaan' => 'PV-202609-0004',
            'id_jenis_kendaraan' => $this->buatJenisKendaraan('Fuso'), 'jumlah_unit' => 4, 'mekanisme' => 'unit_only',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('permintaan_vendor', $idPermintaan);

        $this->rincian($idApproval)->assertStatus(200)
            ->assertJsonPath('data.rincian.judul', 'PV-202609-0004')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jenis', 'Fuso')
            ->assertJsonPath('data.rincian.bagian.0.baris.0.jumlah', 4);
    }

    public function test_approval_perusahaan_lain_mengembalikan_404(): void
    {
        [$idLain, $penggunaLain] = $this->buatTenantLain();
        $idPengajuan = $this->buatPengajuan();
        $idApproval = $this->buatApproval('pengajuan_pengeluaran', $idPengajuan);

        Sanctum::actingAs($penggunaLain, ['*']);
        $this->rincian($idApproval)->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Pengajuan approval tidak ditemukan');

        $this->actingAsRole('MANAGER');
        $this->rincian($idApproval)->assertStatus(200);
    }

    public function test_approval_tidak_ada_atau_sudah_dihapus_mengembalikan_404(): void
    {
        $this->actingAsRole('MANAGER');
        $this->rincian((string) Str::uuid())->assertStatus(404)
            ->assertJsonPath('message', 'Pengajuan approval tidak ditemukan');

        $idApproval = $this->buatApproval('pengajuan_pengeluaran', $this->buatPengajuan());
        DB::table('approval_pengajuan')->where('id_approval', $idApproval)->update(['dihapus_pada' => now()]);
        $this->rincian($idApproval)->assertStatus(404);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/approval-pengajuan/' . Str::uuid() . '/rincian')->assertStatus(401);
    }

    public function test_kode_tanpa_cabang_mengembalikan_rincian_null(): void
    {
        $this->actingAsRole('MANAGER');
        $idApproval = $this->buatApproval('test_dummy', (string) Str::uuid());

        $this->rincian($idApproval)->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kode', 'test_dummy')
            ->assertJsonPath('data.rincian', null);
    }

    public function test_persetujuan_transfer_memakai_cabang_pengeluaran(): void
    {
        $this->actingAsRole('MANAGER');
        $idPengajuan = $this->buatPengajuan(['kategori' => 'lainnya']);
        $idApproval = $this->buatApproval('persetujuan_transfer', $idPengajuan);

        $this->rincian($idApproval)->assertStatus(200)
            ->assertJsonPath('data.kode', 'persetujuan_transfer')
            ->assertJsonPath('data.rincian.judul', 'PP-202609-0001 · Beli alat tulis');
    }

    public function test_dokumen_referensi_tidak_ditemukan_mengembalikan_rincian_null(): void
    {
        $this->actingAsRole('MANAGER');

        foreach (['penawaran', 'proyek', 'faktur', 'invoice_vendor', 'kontrak_vendor', 'permintaan_vendor', 'permintaan_pembelian', 'permintaan_pembelian_aset', 'pengajuan_pengeluaran', 'perawatan', 'sparepart'] as $kode) {
            $idApproval = $this->buatApproval($kode, (string) Str::uuid());
            $this->rincian($idApproval)->assertStatus(200)
                ->assertJsonPath('data.kode', $kode)
                ->assertJsonPath('data.rincian', null);
        }
    }

    public function test_dokumen_milik_perusahaan_lain_tidak_bocor_lewat_referensi_approval(): void
    {
        [$idLain] = $this->buatTenantLain();
        $this->actingAsRole('MANAGER');

        $idKlienLain = $this->buatKlien('Klien Rahasia', $idLain);
        $idPenawaranLain = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaranLain, 'id_perusahaan' => $idLain, 'id_klien' => $idKlienLain,
            'nomor_penawaran' => 'PNW-RAHASIA', 'judul' => 'Rahasia', 'status' => 'draft', 'dibuat_pada' => now(),
        ]);
        $idPengajuanLain = $this->buatPengajuan(['id_perusahaan' => $idLain, 'nomor_pengajuan' => 'PP-RAHASIA']);
        $idFakturLain = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $idFakturLain, 'id_perusahaan' => $idLain, 'nomor_faktur' => 'INV-RAHASIA', 'total' => 1,
            'status' => 'draft', 'dibuat_pada' => now(),
        ]);

        foreach ([['penawaran', $idPenawaranLain], ['pengajuan_pengeluaran', $idPengajuanLain], ['faktur', $idFakturLain]] as [$kode, $idReferensi]) {
            $idApproval = $this->buatApproval($kode, $idReferensi);
            $res = $this->rincian($idApproval);
            $res->assertStatus(200)->assertJsonPath('data.rincian', null);
            $this->assertStringNotContainsString('RAHASIA', $res->getContent());
            $this->assertStringNotContainsString('Rahasia', $res->getContent());
        }
    }

    public function test_nama_master_perusahaan_lain_tidak_bocor_lewat_join(): void
    {
        [$idLain] = $this->buatTenantLain();
        $this->actingAsRole('MANAGER');

        $idKlienLain = $this->buatKlien('Klien Rahasia', $idLain);
        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaran, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlienLain,
            'nomor_penawaran' => 'PNW-202609-0010', 'judul' => 'Judul Aman', 'status' => 'draft', 'dibuat_pada' => now(),
        ]);
        $idApproval = $this->buatApproval('penawaran', $idPenawaran);

        $res = $this->rincian($idApproval);

        $res->assertStatus(200);
        $this->assertNull($this->nilaiInfo($res, 'Klien'));
        $this->assertStringNotContainsString('Klien Rahasia', $res->getContent());
    }

    public function test_bentuk_keluaran_seragam_untuk_semua_cabang(): void
    {
        $this->actingAsRole('MANAGER');
        $idPengajuan = $this->buatPengajuan();
        $idApproval = $this->buatApproval('pengajuan_pengeluaran', $idPengajuan);

        $rincian = $this->rincian($idApproval)->assertStatus(200)->json('data.rincian');

        $this->assertSame(['kode', 'judul', 'info', 'bagian', 'link'], array_keys($rincian));
        foreach ($rincian['info'] as $baris) {
            $this->assertContains(array_keys($baris), [['label', 'value'], ['label', 'value', 'tipe']]);
            if (isset($baris['tipe'])) {
                $this->assertContains($baris['tipe'], ['rupiah', 'tanggal', 'angka']);
            }
        }
    }

    public function test_rincian_hanya_untuk_pengaju_approver_atau_peran_pengawas(): void
    {
        $idApproval = $this->buatApproval('pengajuan_pengeluaran', $this->buatPengajuan());

        $tidakTerlibat = $this->actingAsRole('DISPATCHER');
        $this->rincian($idApproval)->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak terlibat dalam pengajuan approval ini');

        DB::table('approval_pengajuan')->where('id_approval', $idApproval)
            ->update(['id_pengguna_pengaju' => $tidakTerlibat->id_pengguna]);
        $this->rincian($idApproval)->assertStatus(200);

        DB::table('approval_pengajuan')->where('id_approval', $idApproval)
            ->update(['id_pengguna_pengaju' => (string) Str::uuid()]);
        $this->rincian($idApproval)->assertStatus(403);

        DB::table('approval_keputusan')->insert([
            'id_keputusan' => (string) Str::uuid(), 'id_approval' => $idApproval,
            'id_pengguna' => $tidakTerlibat->id_pengguna, 'status' => 'menunggu', 'dibuat_pada' => now(),
        ]);
        $this->rincian($idApproval)->assertStatus(200);

        DB::table('approval_keputusan')->where('id_approval', $idApproval)->update(['dihapus_pada' => now()]);
        $this->rincian($idApproval)->assertStatus(403);

        foreach (['SUPERADMIN', 'ADMIN', 'MANAGER'] as $peran) {
            $this->actingAsRole($peran);
            $this->rincian($idApproval)->assertStatus(200);
        }
    }
}
