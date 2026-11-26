<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KategoriPengajuanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        $service = app(ArusKasService::class);
        $service->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
        $service->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 999999999);
    }

    private function tambah(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/kategori-pengajuan', array_merge(['nama' => 'Beban Listrik'], $override));
    }

    private function ajukan(string $kategori): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/arus-kas/pengajuan', [
            'kategori'          => $kategori,
            'nominal'           => 250000,
            'tanggal_pengajuan' => '2026-08-05',
            'penerima'          => 'PLN',
            'keterangan'        => 'Tagihan listrik kantor',
        ]);
    }

    public function test_kategori_bawaan_tersedia_tanpa_kategori_otomatis(): void
    {
        $this->actingAsRole('KEUANGAN');

        $kode = collect($this->getJson('/api/kategori-pengajuan/opsi')->assertStatus(200)->json('data.kategori'))->pluck('kode');
        foreach (['legalitas', 'perawatan', 'pembelian_aset', 'pembayaran_pinjaman', 'lainnya'] as $bawaan) {
            $this->assertContains($bawaan, $kode);
        }
        // Kategori otomatis dari modul tidak masuk master.
        foreach (['uang_jalan', 'penggajian', 'pembayaran_vendor', 'pengadaan', 'kasbon'] as $otomatis) {
            $this->assertNotContains($otomatis, $kode);
        }

        $id = collect($this->getJson('/api/kategori-pengajuan/opsi')->json('data.kategori'))->firstWhere('kode', 'legalitas')['id_kategori_pengajuan'];
        $this->deleteJson("/api/kategori-pengajuan/{$id}")->assertStatus(422);
    }

    public function test_kategori_baru_bisa_dipakai_pengajuan_dengan_labelnya(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->tambah()->assertStatus(201)->assertJsonPath('data.kode', 'beban_listrik');

        $this->ajukan('beban_listrik')
            ->assertStatus(201)
            ->assertJsonPath('data.kategori', 'beban_listrik')
            ->assertJsonPath('data.label_kategori', 'Beban Listrik');

        $this->assertSame('Beban Listrik', collect($this->getJson('/api/arus-kas/pengajuan')->json('data'))->first()['label_kategori']);

        // Ganti nama → label ikut berubah di pengajuan yang sudah ada.
        $id = collect($this->getJson('/api/kategori-pengajuan/opsi')->json('data.kategori'))->firstWhere('kode', 'beban_listrik')['id_kategori_pengajuan'];
        $this->putJson("/api/kategori-pengajuan/{$id}", ['nama' => 'Beban Listrik & Air'])->assertStatus(200);
        $this->assertSame('Beban Listrik & Air', collect($this->getJson('/api/arus-kas/pengajuan')->json('data'))->first()['label_kategori']);
    }

    public function test_kode_tidak_bentrok_dengan_kategori_sistem_dan_nama_unik(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->tambah(['nama' => 'Uang Jalan'])->assertStatus(201)->assertJsonPath('data.kode', 'uang_jalan_2');
        $this->tambah(['nama' => 'uang jalan'])->assertStatus(422);
    }

    public function test_kategori_nonaktif_dan_terpakai(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = (string) $this->tambah()->json('data.id_kategori_pengajuan');
        $this->ajukan('beban_listrik')->assertStatus(201);

        $this->deleteJson("/api/kategori-pengajuan/{$id}")->assertStatus(422);
        $this->putJson("/api/kategori-pengajuan/{$id}", ['aktif' => false])->assertStatus(200);
        $this->ajukan('beban_listrik')->assertStatus(422)->assertJsonValidationErrors('kategori');
        $this->ajukan('kategori_ngawur')->assertStatus(422)->assertJsonValidationErrors('kategori');

        // Kategori tetap non-master masih diterima API.
        $this->ajukan('uang_jalan')->assertStatus(201);

        $idKosong = (string) $this->tambah(['nama' => 'Beban Coba'])->json('data.id_kategori_pengajuan');
        $this->deleteJson("/api/kategori-pengajuan/{$idKosong}")->assertStatus(200);
    }

    public function test_hak_akses_dan_isolasi_perusahaan(): void
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        DB::table('kategori_pengajuan')->insert([
            'id_kategori_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode' => 'asing',
            'nama' => 'Asing', 'sistem' => 0, 'aktif' => 1, 'urutan' => 50, 'dibuat_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $this->ajukan('asing')->assertStatus(422);

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/kategori-pengajuan')->assertStatus(200);
        $this->tambah()->assertStatus(403);
    }
}
