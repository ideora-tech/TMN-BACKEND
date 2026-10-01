<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JudulPermintaanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatJudul(string $nama = 'ATK Bulanan', string $tipe = 'umum', int $aktif = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('judul_permintaan')->insert([
            'id_judul_permintaan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_judul' => $nama, 'tipe' => $tipe, 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadItemUmum(): array
    {
        return [
            'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000]],
        ];
    }

    public function test_pengadaan_crud_judul_permintaan(): void
    {
        $this->actingAsRole('PENGADAAN');

        $id = $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Servis Kantor', 'tipe' => 'umum'])
            ->assertStatus(201)
            ->assertJsonPath('data.nama_judul', 'Servis Kantor')
            ->assertJsonPath('data.tipe', 'umum')
            ->assertJsonPath('data.aktif', true)
            ->json('data.id_judul_permintaan');

        $this->putJson("/api/judul-permintaan/{$id}", ['tipe' => 'aset', 'aktif' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'aset')
            ->assertJsonPath('data.aktif', false);

        $this->getJson('/api/judul-permintaan?search=Servis')->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->deleteJson("/api/judul-permintaan/{$id}")->assertStatus(200);
        $this->getJson("/api/judul-permintaan/{$id}")->assertStatus(404);
    }

    public function test_nama_duplikat_409_dan_tipe_tidak_valid_422(): void
    {
        $this->actingAsRole('PENGADAAN');
        $this->buatJudul('ATK Bulanan');

        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'atk bulanan', 'tipe' => 'umum'])->assertStatus(409);
        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Baru', 'tipe' => 'lainnya'])->assertStatus(422);
        $this->postJson('/api/judul-permintaan', ['tipe' => 'umum'])->assertStatus(422);
    }

    public function test_sales_tidak_boleh_kelola_master_namun_boleh_baca_opsi_aktif(): void
    {
        $aktif = $this->buatJudul('Aktif', 'umum', 1);
        $this->buatJudul('Nonaktif', 'umum', 0);
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'X', 'tipe' => 'umum'])->assertStatus(403);

        $res = $this->getJson('/api/judul-permintaan/opsi-aktif')->assertStatus(200);
        $this->assertSame([$aktif], array_column($res->json('data'), 'id_judul_permintaan'));
    }

    public function test_pr_dibuat_dari_master_mengikuti_judul_dan_tipe_master(): void
    {
        $idJudul = $this->buatJudul('Servis AC Kantor', 'umum');
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), [
            'id_judul_permintaan' => $idJudul,
        ]))->assertStatus(201)
            ->assertJsonPath('data.judul', 'Servis AC Kantor')
            ->assertJsonPath('data.tipe', 'umum')
            ->assertJsonPath('data.id_judul_permintaan', $idJudul);

        $idJudulAset = $this->buatJudul('Armada Baru', 'aset');
        $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), [
            'id_judul_permintaan' => $idJudulAset,
        ]))->assertStatus(422);

        $this->assertNotNull($res->json('data.id_permintaan'));
    }

    public function test_pr_dengan_judul_nonaktif_atau_milik_tenant_lain_422(): void
    {
        $nonaktif = $this->buatJudul('Lama', 'umum', 0);
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), ['id_judul_permintaan' => $nonaktif]))->assertStatus(422);
        $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), ['id_judul_permintaan' => (string) Str::uuid()]))->assertStatus(422);
    }

    public function test_pr_tanpa_master_tetap_wajib_judul_dan_tipe_manual(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payloadItemUmum())->assertStatus(422);
        $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), ['judul' => 'Manual', 'tipe' => 'umum']))->assertStatus(201);
    }

    public function test_judul_yang_dipakai_pr_tidak_bisa_dihapus(): void
    {
        $idJudul = $this->buatJudul('Dipakai', 'umum');
        $this->actingAsRole('DISPATCHER');
        $this->postJson('/api/permintaan-pembelian', array_merge($this->payloadItemUmum(), ['id_judul_permintaan' => $idJudul]))->assertStatus(201);

        $this->actingAsRole('PENGADAAN');
        $this->deleteJson("/api/judul-permintaan/{$idJudul}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Judul permintaan masih dipakai di permintaan pembelian — nonaktifkan saja');
    }
}
