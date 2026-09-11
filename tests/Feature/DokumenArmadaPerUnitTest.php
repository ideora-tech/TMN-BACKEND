<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenArmadaPerUnitTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol, string $status = 'tersedia', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
            'status'        => $status,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeDokumen(string $idArmada, string $jenis, ?string $berlakuSampai, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert(array_merge([
            'id_dokumen_armada' => $id,
            'id_armada'         => $idArmada,
            'jenis_dokumen'     => $jenis,
            'berlaku_sampai'    => $berlakuSampai,
            'aktif'             => 1,
            'dibuat_pada'       => now(),
        ], $extra));
        return $id;
    }

    private function siapkanEmpatUnit(): array
    {
        $habis    = $this->makeArmada('B 4444 DD');
        $segera   = $this->makeArmada('B 3333 CC');
        $belumAda = $this->makeArmada('B 1111 AA');
        $aman     = $this->makeArmada('B 2222 BB');

        $this->makeDokumen($habis, 'STNK', now()->subDay()->toDateString());
        $this->makeDokumen($habis, 'KIR', now()->addDays(200)->toDateString());
        $this->makeDokumen($segera, 'KIR', now()->addDays(10)->toDateString());
        $this->makeDokumen($aman, 'STNK', now()->addDays(100)->toDateString());
        $this->makeDokumen($aman, 'BPKB', null);

        return compact('habis', 'segera', 'belumAda', 'aman');
    }

    public function test_menampilkan_semua_unit_termasuk_yang_belum_punya_dokumen_diurutkan_dari_yang_perlu_perhatian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->siapkanEmpatUnit();

        $res = $this->getJson('/api/dokumen-armada/per-unit');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(4, $data);
        $this->assertSame([$unit['habis'], $unit['segera'], $unit['belumAda'], $unit['aman']], array_column($data, 'id_armada'));
        $this->assertSame(['habis', 'segera', 'belum_ada', 'aman'], array_column($data, 'kondisi'));

        $this->assertSame(2, $data[0]['jumlah_dokumen']);
        $this->assertSame('STNK', $data[0]['terdekat']['jenis_dokumen']);
        $this->assertSame(['STNK', 'KIR'], array_column($data[0]['dokumen'], 'jenis_dokumen'));
        $this->assertSame('B 4444 DD', $data[0]['dokumen'][0]['armada_nopol']);

        $this->assertSame(0, $data[2]['jumlah_dokumen']);
        $this->assertNull($data[2]['terdekat']);
        $this->assertSame([], $data[2]['dokumen']);

        $this->assertSame(['STNK', 'BPKB'], array_column($data[3]['dokumen'], 'jenis_dokumen'));
        $res->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.ringkasan', ['total' => 4, 'habis' => 1, 'segera' => 1, 'belum_ada' => 1, 'aman' => 1]);
    }

    public function test_filter_kondisi_membatasi_data_tapi_ringkasan_tetap_menghitung_semua_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->siapkanEmpatUnit();

        $res = $this->getJson('/api/dokumen-armada/per-unit?kondisi=belum_ada');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_armada', $unit['belumAda'])
            ->assertJsonPath('meta.ringkasan.total', 4);
    }

    public function test_filter_kondisi_tidak_dikenal_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/dokumen-armada/per-unit?kondisi=ngawur')->assertStatus(422);
    }

    public function test_filter_jenis_dokumen_menilai_kondisi_dari_jenis_itu_saja(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->siapkanEmpatUnit();

        $res = $this->getJson('/api/dokumen-armada/per-unit?jenis_dokumen=KIR');

        $res->assertStatus(200);
        $kondisi = array_column($res->json('data'), 'kondisi', 'id_armada');
        $this->assertSame('aman', $kondisi[$unit['habis']]);
        $this->assertSame('segera', $kondisi[$unit['segera']]);
        $this->assertSame('belum_ada', $kondisi[$unit['aman']]);
        $this->assertSame(['KIR'], array_column($res->json('data.0.dokumen'), 'jenis_dokumen'));
    }

    public function test_pencarian_cocok_ke_nopol_atau_nomor_dokumen(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $a = $this->makeArmada('B 1111 AA');
        $b = $this->makeArmada('B 2222 BB');
        $this->makeDokumen($b, 'STNK', now()->addDays(90)->toDateString(), ['nomor' => 'STNK-777']);

        $this->assertSame([$a], array_column($this->getJson('/api/dokumen-armada/per-unit?search=1111')->json('data'), 'id_armada'));
        $this->assertSame([$b], array_column($this->getJson('/api/dokumen-armada/per-unit?search=STNK-777')->json('data'), 'id_armada'));
    }

    public function test_hanya_unit_perusahaan_sendiri_dan_dokumen_riwayat_atau_terhapus_diabaikan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sendiri = $this->makeArmada('B 1111 AA');
        $this->makeDokumen($sendiri, 'STNK', now()->subDays(5)->toDateString(), ['aktif' => 0]);
        $this->makeDokumen($sendiri, 'KIR', now()->subDays(5)->toDateString(), ['dihapus_pada' => now()]);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $lain = $this->makeArmada('D 9999 ZZ', 'tersedia', $idPerusahaanLain);
        $this->makeDokumen($lain, 'STNK', now()->addDays(5)->toDateString());

        $res = $this->getJson('/api/dokumen-armada/per-unit');

        $res->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->assertSame($sendiri, $res->json('data.0.id_armada'));
        $this->assertSame('belum_ada', $res->json('data.0.kondisi'));

        $this->getJson("/api/dokumen-armada/per-unit?id_armada={$lain}")->assertJsonPath('meta.total', 0);
    }

    public function test_unit_tidak_aktif_disembunyikan_kecuali_dipilih_lewat_filter_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeArmada('B 1111 AA');
        $tidakAktif = $this->makeArmada('B 2222 BB', 'tidak_aktif');

        $this->getJson('/api/dokumen-armada/per-unit')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/dokumen-armada/per-unit?id_armada={$tidakAktif}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_armada', $tidakAktif);
    }

    public function test_pagination_per_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        foreach (['B 1 A', 'B 2 A', 'B 3 A'] as $nopol) {
            $this->makeArmada($nopol);
        }

        $res = $this->getJson('/api/dokumen-armada/per-unit?page=2&limit=2');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.totalPages', 2)
            ->assertJsonPath('data.0.nopol', 'B 3 A');
        $this->assertCount(1, $res->json('data'));
    }
}
