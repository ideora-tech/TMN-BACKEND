<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinMobilePembelianDokumenTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN = 'MAINTENANCE';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function setIzin(string $path, string $aksi, int $diizinkan): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', self::PERAN)->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => self::PERAN,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function login(array $izinPerMenu): void
    {
        foreach (['/pembelian-sparepart', '/dokumen-armada', '/armada', '/sparepart', '/supplier', '/perawatan-armada'] as $path) {
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                $this->setIzin($path, $aksi, in_array($path, $izinPerMenu, true) ? 1 : 0);
            }
        }
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => self::PERAN, 'username' => 'mobile_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
    }

    public function test_izin_pembelian_saja_bisa_membaca_katalog_sparepart_dan_supplier(): void
    {
        $this->login(['/pembelian-sparepart']);

        $this->getJson('/api/sparepart')->assertStatus(200);
        $this->getJson('/api/supplier?aktif=1')->assertStatus(200);
        $this->getJson('/api/pembelian-sparepart')->assertStatus(200);
        $this->postJson('/api/sparepart', ['nama' => 'X'])->assertStatus(403);
        $this->postJson('/api/supplier', ['nama' => 'X'])->assertStatus(403);
    }

    public function test_tanpa_izin_pembelian_katalog_tetap_403(): void
    {
        $this->login([]);

        $this->getJson('/api/sparepart')->assertStatus(403);
        $this->getJson('/api/supplier')->assertStatus(403);
    }

    public function test_izin_dokumen_armada_saja_bisa_upload_dan_perpanjang_dokumen(): void
    {
        $this->login(['/dokumen-armada']);
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 1 MOB',
            'status' => 'tersedia', 'dibuat_pada' => now(),
        ]);

        $res = $this->post("/api/armada/{$idArmada}/dokumen/batch", [
            'dokumen' => [['jenis_dokumen' => 'STNK', 'berlaku_sampai' => '2026-10-01', 'file' => UploadedFile::fake()->image('stnk.jpg')]],
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $idDokumen = (string) $res->json('data.0.id_dokumen_armada');

        $this->post("/api/armada/{$idArmada}/dokumen/{$idDokumen}/perpanjang", [
            'berlaku_sampai' => '2027-10-01', 'file' => UploadedFile::fake()->image('stnk-baru.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->getJson("/api/armada/{$idArmada}/dokumen?dengan_riwayat=1")->assertStatus(200)
            ->assertJsonPath('data.0.berlaku_sampai', '2027-10-01')
            ->assertJsonCount(1, 'data.0.riwayat');
    }

    private function pembelianDisetujuiFinance(): array
    {
        $idSparepart = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $idSparepart, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'SP-' . Str::random(5),
            'nama' => 'Kampas Rem', 'satuan' => 'pcs', 'harga_standar' => 50000, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $res = $this->post('/api/pembelian-sparepart', [
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [['id_sparepart' => $idSparepart, 'qty' => 2, 'harga_estimasi' => 50000]],
            'bukti'             => [UploadedFile::fake()->image('nota.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $idPembelian = (string) $res->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->update(['status' => 'disetujui_finance']);
        return [$idPembelian, (string) $res->json('data.items.0.id_item'), $idSparepart];
    }

    public function test_role_maintenance_dengan_izin_pembelian_bisa_mengajukan_dan_realisasi(): void
    {
        $this->login(['/pembelian-sparepart']);
        [$idPembelian, $idItem, $idSparepart] = $this->pembelianDisetujuiFinance();

        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [['id_item' => $idItem, 'harga_aktual' => 55000]],
        ])->assertStatus(200)->assertJsonPath('data.status', 'dibeli');

        $this->assertSame(2, (int) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok'));
    }

    public function test_realisasi_tanpa_izin_ubah_pembelian_ditolak(): void
    {
        $this->login(['/pembelian-sparepart']);
        [$idPembelian, $idItem] = $this->pembelianDisetujuiFinance();
        $this->setIzin('/pembelian-sparepart', 'ubah', 0);

        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [['id_item' => $idItem, 'harga_aktual' => 55000]],
        ])->assertStatus(403);
    }

    public function test_tanpa_izin_dokumen_maupun_armada_upload_ditolak(): void
    {
        $this->login([]);
        $this->postJson('/api/armada/' . Str::uuid() . '/dokumen/batch', [])->assertStatus(403);
    }
}
