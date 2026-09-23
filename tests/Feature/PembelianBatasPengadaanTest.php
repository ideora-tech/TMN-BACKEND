<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PembelianBatasPengadaanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSupplier(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Toko Batas Pengadaan', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(float $hargaStandar): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => 'Sparepart Uji', 'satuan' => 'pcs',
            'harga_standar' => $hargaStandar, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function ajukanDisetujuiFinance(float $hargaEstimasi): array
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postJson('/api/pembelian-sparepart', [
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepart($hargaEstimasi), 'qty' => 1, 'harga_estimasi' => $hargaEstimasi],
            ],
            'bukti'             => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $id = $res->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);
        return [$id, $res->json('data.items')];
    }

    private function berikanIzinUbahPembelianSparepart(string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', '/pembelian-sparepart')->value('id_menu');
        DB::table('izin_peran')->insert([
            'id_izin'       => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'id_menu'       => $idMenu,
            'aksi'          => 'ubah',
            'diizinkan'     => 1,
            'dibuat_pada'   => now(),
        ]);
    }

    private function realisasi(string $id, array $items, string $kodePeran): TestResponse
    {
        $this->actingAsRole($kodePeran);
        return $this->patchJson("/api/pembelian-sparepart/{$id}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => array_map(fn ($i) => ['id_item' => $i['id_item'], 'harga_aktual' => $i['harga_estimasi']], $items),
        ]);
    }

    public function test_wajib_pengadaan_false_tepat_di_batas_dan_true_di_atasnya(): void
    {
        [$idBatas] = $this->ajukanDisetujuiFinance(500_000);
        $this->assertFalse((bool) $this->getJson("/api/pembelian-sparepart/{$idBatas}")->json('data.wajib_pengadaan'));

        [$idDiatas] = $this->ajukanDisetujuiFinance(500_001);
        $this->assertTrue((bool) $this->getJson("/api/pembelian-sparepart/{$idDiatas}")->json('data.wajib_pengadaan'));
    }

    public function test_realisasi_dibawah_batas_boleh_oleh_dispatcher(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(300_000);
        $this->realisasi($id, $items, 'DISPATCHER')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.wajib_pengadaan', false);
    }

    public function test_realisasi_tepat_di_batas_boleh_oleh_dispatcher(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(500_000);
        $this->realisasi($id, $items, 'DISPATCHER')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.wajib_pengadaan', false);
    }

    public function test_realisasi_diatas_batas_ditolak_untuk_role_biasa(): void
    {
        foreach (['DISPATCHER', 'ADMIN', 'MANAGER', 'KEUANGAN'] as $kodePeran) {
            [$id, $items] = $this->ajukanDisetujuiFinance(500_001);
            $this->realisasi($id, $items, $kodePeran)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Pembelian senilai Rp 500.000 — pembelian di atas nilai ini wajib diproses oleh tim Pengadaan');
        }
    }

    public function test_realisasi_diatas_batas_boleh_oleh_pengadaan(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(2_000_000);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $this->realisasi($id, $items, 'PENGADAAN')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.wajib_pengadaan', true);
    }

    public function test_realisasi_diatas_batas_boleh_oleh_superadmin(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(3_000_000);
        $this->realisasi($id, $items, 'SUPERADMIN')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.wajib_pengadaan', true);
    }

    public function test_realisasi_diatas_batas_boleh_oleh_pengadaan_case_insensitive(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(700_000);
        $this->berikanIzinUbahPembelianSparepart('pengadaan');
        $this->realisasi($id, $items, 'pengadaan')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli');
    }

    public function test_realisasi_diatas_batas_ditolak_untuk_role_tidak_dikenal_meski_punya_izin_menu(): void
    {
        [$id, $items] = $this->ajukanDisetujuiFinance(550_000);
        $this->berikanIzinUbahPembelianSparepart('STAF_BARU');
        $this->realisasi($id, $items, 'STAF_BARU')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembelian senilai Rp 500.000 — pembelian di atas nilai ini wajib diproses oleh tim Pengadaan');
    }

    public function test_wajib_pengadaan_ikut_menyesuaikan_saat_batas_perusahaan_diubah(): void
    {
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 2_000_000);

        [$idKecil] = $this->ajukanDisetujuiFinance(1_500_000);
        $this->assertFalse((bool) $this->getJson("/api/pembelian-sparepart/{$idKecil}")->json('data.wajib_pengadaan'));

        [$idBesar] = $this->ajukanDisetujuiFinance(2_500_000);
        $this->assertTrue((bool) $this->getJson("/api/pembelian-sparepart/{$idBesar}")->json('data.wajib_pengadaan'));
    }

    public function test_realisasi_ikut_batas_perusahaan_yang_sudah_diturunkan(): void
    {
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 100_000);

        [$id, $items] = $this->ajukanDisetujuiFinance(300_000);
        $this->realisasi($id, $items, 'DISPATCHER')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembelian senilai Rp 100.000 — pembelian di atas nilai ini wajib diproses oleh tim Pengadaan');
    }

    public function test_wajib_pengadaan_ikut_terisi_di_daftar(): void
    {
        [$idKecil] = $this->ajukanDisetujuiFinance(300_000);
        [$idBesar] = $this->ajukanDisetujuiFinance(600_000);

        $res = $this->getJson('/api/pembelian-sparepart?limit=50')->assertStatus(200);
        $data = collect($res->json('data'));
        $this->assertFalse((bool) $data->firstWhere('id_pembelian', $idKecil)['wajib_pengadaan']);
        $this->assertTrue((bool) $data->firstWhere('id_pembelian', $idBesar)['wajib_pengadaan']);
    }
}
