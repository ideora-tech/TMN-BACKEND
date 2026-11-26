<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KategoriPemasukanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function tambah(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/kategori-pemasukan', array_merge([
            'nama'     => 'Hibah Pemerintah',
            'pos_psak' => 'operasi_lain',
        ], $override));
    }

    private function catatPemasukan(string $kategori, float $nominal = 500000, string $tanggal = '2026-08-05'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/arus-kas/pemasukan', [
            'kategori'    => $kategori,
            'nominal'     => $nominal,
            'tanggal'     => $tanggal,
            'sumber_dana' => 'Dinas Perhubungan',
        ]);
    }

    public function test_kategori_bawaan_otomatis_tersedia_dan_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('KEUANGAN');

        $opsi = collect($this->getJson('/api/kategori-pemasukan/opsi')->assertStatus(200)->json('data.kategori'));
        $this->assertEqualsCanonicalizing(
            ['pendapatan_jasa', 'penjualan_aset', 'pengembalian_dana', 'modal_pinjaman', 'lainnya'],
            $opsi->pluck('kode')->all(),
        );
        $this->assertTrue($opsi->every(fn ($k) => $k['sistem'] === true));

        $id = $opsi->firstWhere('kode', 'lainnya')['id_kategori_pemasukan'];
        $this->deleteJson("/api/kategori-pemasukan/{$id}")->assertStatus(422);

        // Nama bawaan boleh diganti; kodenya tetap.
        $this->putJson("/api/kategori-pemasukan/{$id}", ['nama' => 'Penerimaan Lain'])
            ->assertStatus(200)->assertJsonPath('data.kode', 'lainnya')->assertJsonPath('data.nama', 'Penerimaan Lain');
    }

    public function test_tambah_kategori_membuat_kode_unik_dan_menolak_nama_ganda(): void
    {
        $this->actingAsRole('KEUANGAN');

        $this->tambah()->assertStatus(201)->assertJsonPath('data.kode', 'hibah_pemerintah')->assertJsonPath('data.sistem', false);
        $this->tambah(['nama' => 'hibah pemerintah'])->assertStatus(422);
        $this->tambah(['nama' => 'Hibah Pemerintah!'])->assertStatus(201)->assertJsonPath('data.kode', 'hibah_pemerintah_2');
        $this->tambah(['nama' => 'Invoice'])->assertStatus(201)->assertJsonPath('data.kode', 'invoice_2');
        $this->tambah(['pos_psak' => 'ngawur', 'nama' => 'Lain'])->assertStatus(422)->assertJsonValidationErrors('pos_psak');
    }

    public function test_kategori_baru_dipakai_di_pemasukan_dengan_label_dan_pos_psak(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->tambah(['nama' => 'Setoran Pemegang Saham', 'pos_psak' => 'pendanaan'])->assertStatus(201);
        $kode = 'setoran_pemegang_saham';

        $this->catatPemasukan($kode, 2000000)->assertStatus(201);

        $baris = $this->getJson('/api/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31')->assertStatus(200)->json('data.0');
        $this->assertSame($kode, $baris['kategori']);
        $this->assertSame('Setoran Pemegang Saham', $baris['label_kategori']);

        $rekap = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31')->json('data.transaksi.0');
        $this->assertSame('Setoran Pemegang Saham', $rekap['label_kategori']);

        $psak = app(ArusKasService::class)->laporanPsak(self::PERUSAHAAN_ID, '2026-08-01', '2026-08-31');
        $pendanaan = collect($psak['kelompok'])->firstWhere('judul', 'ARUS KAS DARI AKTIVITAS PENDANAAN');
        $this->assertEquals(2000000, collect($pendanaan['baris'])->firstWhere('label', 'Penerimaan modal/pinjaman')['nominal']);

        $laporan = $this->getJson('/api/laporan-pemasukan?dari=2026-08-01&sampai=2026-08-31')->assertStatus(200);
        $this->assertContains('Setoran Pemegang Saham', collect($laporan->json('data.opsi_kategori'))->pluck('label')->all());
    }

    public function test_kategori_nonaktif_tidak_bisa_dipilih_dan_kategori_terpakai_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = (string) $this->tambah()->json('data.id_kategori_pemasukan');
        $idPemasukan = (string) $this->catatPemasukan('hibah_pemerintah')->assertStatus(201)->json('data.id_pemasukan');

        $this->deleteJson("/api/kategori-pemasukan/{$id}")->assertStatus(422);

        $this->putJson("/api/kategori-pemasukan/{$id}", ['aktif' => false])->assertStatus(200);
        $this->catatPemasukan('hibah_pemerintah')->assertStatus(422)->assertJsonValidationErrors('kategori');
        $this->catatPemasukan('kategori_ngawur')->assertStatus(422)->assertJsonValidationErrors('kategori');

        // Data lama tetap bisa diedit dengan kategori nonaktifnya.
        $this->putJson("/api/arus-kas/pemasukan/{$idPemasukan}", ['kategori' => 'hibah_pemerintah', 'nominal' => 600000])
            ->assertStatus(200);

        $this->assertNotContains('hibah_pemerintah', collect($this->getJson('/api/kategori-pemasukan/opsi?aktif=1')->json('data.kategori'))->pluck('kode')->all());

        $idKosong = (string) $this->tambah(['nama' => 'Tidak Terpakai'])->json('data.id_kategori_pemasukan');
        $this->deleteJson("/api/kategori-pemasukan/{$idKosong}")->assertStatus(200);
    }

    public function test_hak_akses_dan_isolasi_perusahaan(): void
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idAsing = (string) Str::uuid();
        DB::table('kategori_pemasukan')->insert([
            'id_kategori_pemasukan' => $idAsing, 'id_perusahaan' => $idLain, 'kode' => 'asing', 'nama' => 'Asing',
            'pos_psak' => 'operasi_lain', 'sistem' => 0, 'aktif' => 1, 'urutan' => 50, 'dibuat_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $this->getJson("/api/kategori-pemasukan/{$idAsing}")->assertStatus(404);
        $this->catatPemasukan('asing')->assertStatus(422);

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/kategori-pemasukan')->assertStatus(200);
        $this->tambah()->assertStatus(403);
    }
}
