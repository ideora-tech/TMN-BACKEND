<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturPajakTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(6),
            'nama_klien'    => 'Klien Pajak Test',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_membuat_faktur_dengan_persen_pajak_menghitung_total(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-PAJAK-1',
            'nama_pajak'   => 'PPN',
            'persen_pajak' => 11,
            'items'        => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama_pajak', 'PPN')
            ->assertJsonPath('data.persen_pajak', 11)
            ->assertJsonPath('data.total', 1110000);
    }

    public function test_update_persen_pajak_tanpa_ubah_item_menghitung_ulang_total(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = FakturModel::create([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_klien'       => $this->makeKlien(),
            'nomor_faktur'   => 'INV-PAJAK-2',
            'total'          => 1000000,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);
        DB::table('faktur_item')->insert([
            'id_faktur_item' => (string) Str::uuid(),
            'id_faktur'      => $faktur->id_faktur,
            'deskripsi'      => 'Jasa angkut',
            'qty'            => 1,
            'harga_satuan'   => 1000000,
            'subtotal'       => 1000000,
            'dibuat_pada'    => now(),
        ]);

        $res = $this->putJson("/api/faktur/{$faktur->id_faktur}", [
            'nama_pajak'   => 'PPN',
            'persen_pajak' => 10,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.total', 1100000);
    }

    public function test_update_item_mempertahankan_persen_pajak_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = FakturModel::create([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_klien'       => $this->makeKlien(),
            'nomor_faktur'   => 'INV-PAJAK-3',
            'total'          => 1110000,
            'nama_pajak'     => 'PPN',
            'persen_pajak'   => 11,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res = $this->putJson("/api/faktur/{$faktur->id_faktur}", [
            'items' => [
                ['deskripsi' => 'Jasa angkut revisi', 'qty' => 2, 'harga_satuan' => 500000],
            ],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.total', 1110000);
    }

    public function test_membuat_faktur_dengan_pajak_multi_baris_menghitung_total_dan_resource(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-MULTI-1',
            'pajak'        => [
                ['nama' => 'PPN', 'persen' => 11],
                ['nama' => 'PPH', 'persen' => 2],
            ],
            'items' => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.total', 1130000)
            ->assertJsonPath('data.nama_pajak', 'PPN')
            ->assertJsonPath('data.persen_pajak', 11)
            ->assertJsonPath('data.pajak.0.nama', 'PPN')
            ->assertJsonPath('data.pajak.0.persen', 11)
            ->assertJsonPath('data.pajak.1.nama', 'PPH')
            ->assertJsonPath('data.pajak.1.persen', 2);

        $idFaktur = $res->json('data.id_faktur');
        $this->assertDatabaseHas('faktur_pajak', ['id_faktur' => $idFaktur, 'nama' => 'PPN', 'persen' => 11, 'urutan' => 1]);
        $this->assertDatabaseHas('faktur_pajak', ['id_faktur' => $idFaktur, 'nama' => 'PPH', 'persen' => 2, 'urutan' => 2]);
    }

    public function test_membuat_faktur_dengan_pajak_duplikat_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-DUP-1',
            'pajak'        => [
                ['nama' => 'PPN', 'persen' => 11],
                ['nama' => 'PPN', 'persen' => 5],
            ],
            'items' => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Nama pajak duplikat');
    }

    public function test_update_faktur_mengganti_baris_pajak_menghitung_ulang_total(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-MULTI-2',
            'pajak'        => [['nama' => 'PPN', 'persen' => 11]],
            'items'        => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);
        $res->assertStatus(201)->assertJsonPath('data.total', 1110000);
        $idFaktur = $res->json('data.id_faktur');

        $resUpdate = $this->putJson("/api/faktur/{$idFaktur}", [
            'pajak' => [
                ['nama' => 'PPN', 'persen' => 11],
                ['nama' => 'PPH', 'persen' => 2],
            ],
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.total', 1130000)
            ->assertJsonPath('data.pajak.1.nama', 'PPH');

        $this->assertDatabaseHas('faktur_pajak', ['id_faktur' => $idFaktur, 'nama' => 'PPH', 'persen' => 2, 'urutan' => 2]);
    }

    public function test_update_faktur_tanpa_kirim_pajak_sama_sekali_tidak_mengubah_baris_pajak(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-MULTI-3',
            'pajak'        => [
                ['nama' => 'PPN', 'persen' => 11],
                ['nama' => 'PPH', 'persen' => 2],
            ],
            'items' => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);
        $idFaktur = $res->json('data.id_faktur');

        $resUpdate = $this->putJson("/api/faktur/{$idFaktur}", [
            'jatuh_tempo' => now()->addDays(30)->toDateString(),
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.total', 1130000)
            ->assertJsonCount(2, 'data.pajak');

        $this->assertEquals(
            2,
            DB::table('faktur_pajak')->where('id_faktur', $idFaktur)->whereNull('dihapus_pada')->count()
        );
    }

    public function test_payload_legacy_tanpa_field_pajak_tetap_menghasilkan_resource_pajak_satu_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', [
            'id_klien'     => $this->makeKlien(),
            'nomor_faktur' => 'INV-LEGACY-1',
            'nama_pajak'   => 'PPN',
            'persen_pajak' => 11,
            'items'        => [
                ['deskripsi' => 'Jasa angkut', 'qty' => 1, 'harga_satuan' => 1000000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonCount(1, 'data.pajak')
            ->assertJsonPath('data.pajak.0.nama', 'PPN')
            ->assertJsonPath('data.pajak.0.persen', 11);
    }
}
