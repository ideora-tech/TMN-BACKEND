<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanPembelianPrioritasKategoriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatKategori(string $nama = 'Office & ATK', string $tipe = 'umum'): string
    {
        $id = (string) Str::uuid();
        DB::table('judul_permintaan')->insert([
            'id_judul_permintaan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_judul' => $nama, 'tipe' => $tipe, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payload(array $tambahan = []): array
    {
        return array_merge([
            'alasan' => 'Stok kebutuhan dua bulan', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [[
                'jenis' => 'jasa', 'nama_item' => 'Pensil', 'spesifikasi' => 'warna hitam 2B', 'qty' => 5,
                'satuan' => 'pack', 'harga_estimasi' => 25000, 'keterangan' => 'Untuk tim Finance',
            ]],
        ], $tambahan);
    }

    public function test_judul_diketik_bebas_kategori_dari_master_dan_prioritas_tersimpan(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori,
            'judul'               => 'Pengadaan ATK untuk kebutuhan Finance',
            'prioritas'           => 'urgent',
        ]))->assertStatus(201)
            ->assertJsonPath('data.judul', 'Pengadaan ATK untuk kebutuhan Finance')
            ->assertJsonPath('data.nama_kategori', 'Office & ATK')
            ->assertJsonPath('data.id_judul_permintaan', $idKategori)
            ->assertJsonPath('data.prioritas', 'urgent')
            ->assertJsonPath('data.tipe', 'umum');

        $id = $res->json('data.id_permintaan');
        $this->getJson("/api/permintaan-pembelian/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.judul', 'Pengadaan ATK untuk kebutuhan Finance')
            ->assertJsonPath('data.nama_kategori', 'Office & ATK')
            ->assertJsonPath('data.prioritas', 'urgent')
            ->assertJsonPath('data.items.0.keterangan', 'Untuk tim Finance');
    }

    public function test_payload_lama_tanpa_judul_dan_prioritas_tetap_diterima(): void
    {
        $idKategori = $this->buatKategori('ATK Bulanan');
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategori]))
            ->assertStatus(201)
            ->assertJsonPath('data.judul', 'ATK Bulanan')
            ->assertJsonPath('data.nama_kategori', 'ATK Bulanan')
            ->assertJsonPath('data.prioritas', 'normal');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategori, 'judul' => '   ']))
            ->assertStatus(201)
            ->assertJsonPath('data.judul', 'ATK Bulanan');
    }

    public function test_prioritas_di_luar_pilihan_ditolak(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategori, 'prioritas' => 'segera']))
            ->assertStatus(422);
        $this->assertSame(0, DB::table('permintaan_pembelian')->count());
    }

    public function test_ubah_tanpa_prioritas_mempertahankan_nilai_lama_dan_judul_bisa_diganti(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $id = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Judul awal', 'prioritas' => 'urgent',
        ]))->assertStatus(201)->json('data.id_permintaan');

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Judul diperbaiki',
        ]))->assertStatus(200)
            ->assertJsonPath('data.judul', 'Judul diperbaiki')
            ->assertJsonPath('data.prioritas', 'urgent');

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Judul diperbaiki', 'prioritas' => 'normal',
        ]))->assertStatus(200)->assertJsonPath('data.prioritas', 'normal');
    }

    public function test_ubah_boleh_mempertahankan_kategori_nonaktif_tapi_tidak_pindah_ke_kategori_nonaktif_lain(): void
    {
        $idKategori = $this->buatKategori('ATK Lama');
        $idLain = $this->buatKategori('Kategori Mati');
        $this->actingAsRole('DISPATCHER');

        $id = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Pengadaan awal',
        ]))->assertStatus(201)->json('data.id_permintaan');

        DB::table('judul_permintaan')->whereIn('id_judul_permintaan', [$idKategori, $idLain])->update(['aktif' => 0]);

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Pengadaan direvisi',
        ]))->assertStatus(200)
            ->assertJsonPath('data.id_judul_permintaan', $idKategori)
            ->assertJsonPath('data.nama_kategori', 'ATK Lama')
            ->assertJsonPath('data.judul', 'Pengadaan direvisi');

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload([
            'id_judul_permintaan' => $idLain, 'judul' => 'Pengadaan direvisi',
        ]))->assertStatus(422);

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategori]))->assertStatus(422);
    }

    public function test_kategori_milik_perusahaan_lain_ditolak_saat_buat_maupun_ubah(): void
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idKategoriLain = (string) Str::uuid();
        DB::table('judul_permintaan')->insert([
            'id_judul_permintaan' => $idKategoriLain, 'id_perusahaan' => $idPerusahaanLain,
            'nama_judul' => 'Kategori Tetangga', 'tipe' => 'umum', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategoriLain, 'judul' => 'Coba']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori permintaan tidak ditemukan atau tidak aktif');

        $id = $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => $idKategori, 'judul' => 'Sah']))
            ->assertStatus(201)->json('data.id_permintaan');
        DB::table('permintaan_pembelian')->where('id_permintaan', $id)->update(['id_judul_permintaan' => $idKategoriLain]);

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload(['id_judul_permintaan' => $idKategoriLain, 'judul' => 'Sah']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori permintaan tidak ditemukan atau tidak aktif');
    }

    public function test_ubah_tanpa_judul_mempertahankan_judul_lama_dan_judul_kosong_tanpa_kategori_ditolak(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $id = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Pengadaan ATK untuk Finance',
        ]))->assertStatus(201)->json('data.id_permintaan');

        $this->putJson("/api/permintaan-pembelian/{$id}", $this->payload(['id_judul_permintaan' => $idKategori]))
            ->assertStatus(200)
            ->assertJsonPath('data.judul', 'Pengadaan ATK untuk Finance');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['id_judul_permintaan' => '0']))->assertStatus(422);
    }

    public function test_jalur_mobile_tanpa_kategori_tetap_diterima_dengan_prioritas_normal(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payload(['tipe' => 'umum', 'judul' => 'Servis AC kantor']))
            ->assertStatus(201)
            ->assertJsonPath('data.judul', 'Servis AC kantor')
            ->assertJsonPath('data.nama_kategori', null)
            ->assertJsonPath('data.id_judul_permintaan', null)
            ->assertJsonPath('data.prioritas', 'normal');
    }

    public function test_filter_prioritas_dan_antrean_pengadaan_mendahulukan_urgent(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');

        $normal = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Biasa', 'tanggal_permintaan' => now()->subDays(5)->toDateString(),
        ]))->assertStatus(201)->json('data.id_permintaan');
        $urgent = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Mendesak', 'prioritas' => 'urgent',
        ]))->assertStatus(201)->json('data.id_permintaan');

        $this->actingAsRole('PENGADAAN');
        $this->getJson('/api/permintaan-pembelian?prioritas=urgent')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_permintaan', $urgent);
        $this->getJson('/api/permintaan-pembelian?prioritas=normal')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_permintaan', $normal);

        $antrean = $this->getJson('/api/pengadaan/ringkasan')->assertStatus(200)->json('data.pr.menunggu');
        $this->assertSame([$urgent, $normal], array_column($antrean, 'id_permintaan'));
        $this->assertSame('urgent', $antrean[0]['prioritas']);
    }

    public function test_rincian_approval_memuat_kategori_dan_prioritas(): void
    {
        $idKategori = $this->buatKategori();
        $this->actingAsRole('DISPATCHER');
        $id = $this->postJson('/api/permintaan-pembelian', $this->payload([
            'id_judul_permintaan' => $idKategori, 'judul' => 'Pengadaan ATK', 'prioritas' => 'urgent',
        ]))->assertStatus(201)->json('data.id_permintaan');

        $rincian = app(\App\Modules\Approval\RincianReferensiRepository::class)
            ->rincian('permintaan_pembelian', $id, self::PERUSAHAAN_ID);

        $info = collect($rincian['info'] ?? [])->pluck('value', 'label');
        $this->assertSame('Office & ATK', $info['Kategori']);
        $this->assertSame('Urgent', $info['Prioritas']);
    }
}
