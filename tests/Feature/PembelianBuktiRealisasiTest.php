<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembelianBuktiRealisasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => 'Toko Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama = 'Oli Mesin', int $stok = 0, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => 50000, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadPengajuan(array $override = []): array
    {
        return array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepart('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
                ['id_sparepart' => $this->makeSparepart('Filter Udara'), 'qty' => 1, 'harga_estimasi' => 80000],
            ],
        ], $override);
    }

    private function pengajuanDisetujuiFinance(): array
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());
        $id = $res->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);
        return [$id, $res->json('data.items')];
    }

    public function test_upload_dan_hapus_bukti(): void
    {
        Storage::fake('public');
        [$id] = $this->pengajuanDisetujuiFinance();

        $res = $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg'), UploadedFile::fake()->image('nota2.png')],
        ]);
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data.bukti'));
        $this->assertStringContainsString('/storage/pembelian-sparepart/', (string) $res->json('data.bukti.0.url_file'));

        $tersimpan = (string) DB::table('pembelian_sparepart_bukti')->orderByDesc('dibuat_pada')->value('url_file');
        $this->assertStringStartsNotWith('http', $tersimpan);
        $this->assertStringStartsWith('pembelian-sparepart/', $tersimpan);
        Storage::disk('public')->assertExists($tersimpan);

        $idBukti = $res->json('data.bukti.0.id_bukti');
        $this->deleteJson("/api/v1/pembelian-sparepart/{$id}/bukti/{$idBukti}")->assertStatus(200);
        $this->assertCount(1, $this->getJson("/api/v1/pembelian-sparepart/{$id}")->json('data.bukti'));
    }

    public function test_upload_validasi(): void
    {
        Storage::fake('public');
        [$id] = $this->pengajuanDisetujuiFinance();

        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", ['bukti' => []])->assertStatus(422);
        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->create('nota.txt', 10)],
        ])->assertStatus(422);
    }

    public function test_upload_boleh_saat_diajukan_tapi_ditolak_saat_status_ditolak(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');

        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('penawaran.jpg'), UploadedFile::fake()->create('penawaran.pdf', 100, 'application/pdf')],
        ])->assertStatus(200);
        $this->assertCount(2, $this->getJson("/api/v1/pembelian-sparepart/{$id}")->json('data.bukti'));

        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'ditolak']);
        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(422);
    }

    public function test_realisasi_menaikkan_stok_dan_membuat_mutasi(): void
    {
        Storage::fake('public');
        [$id, $items] = $this->pengajuanDisetujuiFinance();
        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);

        $res = $this->patchJson("/api/v1/pembelian-sparepart/{$id}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.total_aktual', 205000)
            ->assertJsonPath('data.items.0.selisih', 5000);

        $idSparepart = $items[0]['id_sparepart'];
        $this->assertSame(2, (int) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $idSparepart, 'jenis' => 'masuk', 'qty' => 2, 'harga' => 65000,
            'id_pembelian' => $id,
        ]);

        $mutasi = $this->getJson("/api/v1/sparepart/{$idSparepart}/mutasi")->json('data');
        $this->assertSame($id, $mutasi[0]['id_pembelian']);
    }

    public function test_realisasi_tanpa_bukti_atau_item_kurang_ditolak(): void
    {
        Storage::fake('public');
        [$id, $items] = $this->pengajuanDisetujuiFinance();

        $payload = [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ];
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/realisasi", $payload)->assertStatus(422);

        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000]],
        ])->assertStatus(422);
    }

    public function test_realisasi_status_salah_ditolak(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());
        $id = $res->json('data.id_pembelian');
        $items = $res->json('data.items');

        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(422);

        [$idDibeli, $itemsDibeli] = $this->pengajuanDisetujuiFinance();
        $this->postJson("/api/v1/pembelian-sparepart/{$idDibeli}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);

        $payloadRealisasi = [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $itemsDibeli[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $itemsDibeli[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ];
        $this->patchJson("/api/v1/pembelian-sparepart/{$idDibeli}/realisasi", $payloadRealisasi)
            ->assertStatus(200)->assertJsonPath('data.status', 'dibeli');

        $this->patchJson("/api/v1/pembelian-sparepart/{$idDibeli}/realisasi", $payloadRealisasi)->assertStatus(422);
    }

    public function test_realisasi_id_item_duplikat_ditolak(): void
    {
        Storage::fake('public');
        [$id, $items] = $this->pengajuanDisetujuiFinance();
        $this->postJson("/api/v1/pembelian-sparepart/{$id}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);

        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(422);
    }

    public function test_bukti_milik_pembelian_lain_404(): void
    {
        Storage::fake('public');
        [$idA] = $this->pengajuanDisetujuiFinance();
        $res = $this->postJson("/api/v1/pembelian-sparepart/{$idA}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $idBukti = $res->json('data.bukti.0.id_bukti');
        [$idB] = $this->pengajuanDisetujuiFinance();
        $this->deleteJson("/api/v1/pembelian-sparepart/{$idB}/bukti/{$idBukti}")->assertStatus(404);
    }
}
