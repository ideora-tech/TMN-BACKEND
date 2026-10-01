<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TipePermintaanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function buatTipe(string $nama, string $jenisForm = 'umum', int $aktif = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('tipe_permintaan')->insert([
            'id_tipe_permintaan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_tipe' => $nama, 'jenis_form' => $jenisForm, 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_pengadaan_crud_tipe_permintaan(): void
    {
        $this->actingAsRole('PENGADAAN');

        $id = $this->postJson('/api/tipe-permintaan', ['nama_tipe' => 'ATK', 'jenis_form' => 'umum'])
            ->assertStatus(201)
            ->assertJsonPath('data.nama_tipe', 'ATK')
            ->assertJsonPath('data.jenis_form', 'umum')
            ->json('data.id_tipe_permintaan');

        $this->postJson('/api/tipe-permintaan', ['nama_tipe' => 'atk', 'jenis_form' => 'aset'])->assertStatus(409);
        $this->postJson('/api/tipe-permintaan', ['nama_tipe' => 'Lain', 'jenis_form' => 'bebas'])->assertStatus(422);

        $this->putJson("/api/tipe-permintaan/{$id}", ['aktif' => false])->assertOk()->assertJsonPath('data.aktif', false);
        $this->getJson('/api/tipe-permintaan?search=ATK')->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson("/api/tipe-permintaan/{$id}")->assertOk();
        $this->getJson("/api/tipe-permintaan/{$id}")->assertStatus(404);
    }

    public function test_judul_memilih_tipe_dari_master_dan_jenis_form_terbawa(): void
    {
        $this->actingAsRole('PENGADAAN');
        $idTipe = $this->buatTipe('Ban & Oli', 'sparepart');

        $res = $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Ganti Ban', 'id_tipe_permintaan' => $idTipe])
            ->assertStatus(201)
            ->assertJsonPath('data.tipe', 'sparepart')
            ->assertJsonPath('data.id_tipe_permintaan', $idTipe)
            ->assertJsonPath('data.id_perusahaan', self::PERUSAHAAN_ID);
        $this->assertSame('Ban & Oli', $res->json('data.nama_tipe'));

        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Tanpa Tipe'])->assertStatus(422);
        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Tipe Asing', 'id_tipe_permintaan' => (string) Str::uuid()])->assertStatus(404);

        $idNonaktif = $this->buatTipe('Lama', 'umum', 0);
        $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Pakai Nonaktif', 'id_tipe_permintaan' => $idNonaktif])->assertStatus(422);
    }

    public function test_ubah_jenis_form_tipe_menyinkronkan_judul_dan_tipe_dipakai_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('PENGADAAN');
        $idTipe = $this->buatTipe('Kebutuhan Kantor', 'umum');
        $idJudul = $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Meja Kantor', 'id_tipe_permintaan' => $idTipe])
            ->assertStatus(201)->json('data.id_judul_permintaan');

        $this->putJson("/api/tipe-permintaan/{$idTipe}", ['jenis_form' => 'aset'])->assertOk();
        $this->assertSame('aset', DB::table('judul_permintaan')->where('id_judul_permintaan', $idJudul)->value('tipe'));

        $this->getJson("/api/tipe-permintaan/{$idTipe}")->assertOk()->assertJsonPath('data.jumlah_judul', 1);
        $this->deleteJson("/api/tipe-permintaan/{$idTipe}")->assertStatus(422);
    }

    public function test_judul_dengan_tipe_bawaan_tetap_jalan_dan_pr_mengikuti_jenis_form(): void
    {
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
        $this->actingAsRole('PENGADAAN');
        $this->buatTipe('Spare Part', 'sparepart');

        $idJudul = $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Judul Lama', 'tipe' => 'sparepart'])
            ->assertStatus(201)
            ->assertJsonPath('data.tipe', 'sparepart')
            ->json('data.id_judul_permintaan');
        $this->assertNotNull(DB::table('judul_permintaan')->where('id_judul_permintaan', $idJudul)->value('id_tipe_permintaan'));

        $idTipeAset = $this->buatTipe('Unit Baru', 'aset');
        $idJudulAset = $this->postJson('/api/judul-permintaan', ['nama_judul' => 'Beli Truk', 'id_tipe_permintaan' => $idTipeAset])
            ->assertStatus(201)->json('data.id_judul_permintaan');

        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/permintaan-pembelian', [
            'id_judul_permintaan' => $idJudulAset, 'alasan' => 'Armada baru', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [['jenis' => 'jasa', 'nama_item' => 'Servis', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 1000]],
        ])->assertStatus(422);
    }

    public function test_peran_tanpa_izin_ditolak_tetapi_opsi_aktif_terbuka_untuk_pr(): void
    {
        $this->buatTipe('Aktif', 'umum');
        $this->buatTipe('Mati', 'umum', 0);

        $this->actingAsRole('SALES');
        $this->postJson('/api/tipe-permintaan', ['nama_tipe' => 'X', 'jenis_form' => 'umum'])->assertStatus(403);

        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/tipe-permintaan/opsi-aktif')->assertOk()->assertJsonCount(1, 'data');
    }
}
