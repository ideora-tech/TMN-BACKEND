<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KomponenUangJalanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function tambah(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/komponen-uang-jalan', $data);
    }

    private function proyekRute(string $idPerusahaan = self::PERUSAHAAN_ID, array $kolom = []): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek_rute')->insert(array_merge([
            'id_proyek_rute' => $id, 'id_perusahaan' => $idPerusahaan, 'id_proyek' => (string) Str::uuid(),
            'id_rute' => (string) Str::uuid(), 'dibuat_pada' => now(),
        ], $kolom));

        return $id;
    }

    public function test_kelola_komponen_uang_jalan(): void
    {
        $this->actingAsRole('KEUANGAN');

        $parkir = $this->tambah(['nama' => '  Parkir ', 'urutan' => 4])->assertStatus(201)
            ->assertJsonPath('data.nama', 'Parkir')
            ->assertJsonPath('data.urutan', 4)
            ->assertJsonPath('data.aktif', true)
            ->json('data.id_komponen_uang_jalan');
        $this->tambah(['nama' => 'Tol', 'urutan' => 1])->assertStatus(201);
        $this->tambah(['nama' => 'parkir'])->assertStatus(422)->assertJsonValidationErrors(['nama']);
        $this->tambah(['nama' => '   '])->assertStatus(422)->assertJsonValidationErrors(['nama']);

        $this->getJson('/api/komponen-uang-jalan')->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.nama', 'Tol')
            ->assertJsonPath('data.1.nama', 'Parkir');
        $this->getJson('/api/komponen-uang-jalan?search=par')->assertStatus(200)->assertJsonPath('meta.total', 1);

        $this->getJson("/api/komponen-uang-jalan/{$parkir}")->assertStatus(200)->assertJsonPath('data.nama', 'Parkir');
        $this->putJson("/api/komponen-uang-jalan/{$parkir}", ['nama' => 'Parkir Pelabuhan', 'aktif' => false])->assertStatus(200)
            ->assertJsonPath('data.nama', 'Parkir Pelabuhan')
            ->assertJsonPath('data.aktif', false);
        $this->putJson("/api/komponen-uang-jalan/{$parkir}", ['nama' => 'TOL'])->assertStatus(422);

        $this->getJson('/api/komponen-uang-jalan/opsi')->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Tol');
        $this->getJson('/api/komponen-uang-jalan?aktif=0')->assertStatus(200)->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/komponen-uang-jalan/{$parkir}")->assertStatus(200);
        $this->assertNotNull(DB::table('komponen_uang_jalan')->where('id_komponen_uang_jalan', $parkir)->value('dihapus_pada'));
        $this->tambah(['nama' => 'Parkir Pelabuhan'])->assertStatus(201);
    }

    public function test_komponen_yang_dipakai_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->tambah(['nama' => 'Tol'])->json('data.id_komponen_uang_jalan');
        $idProyekRute = $this->proyekRute();
        DB::table('proyek_rute_komponen')->insert([
            'id_proyek_rute_komponen' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek_rute' => $idProyekRute, 'id_komponen_uang_jalan' => $id, 'nominal' => 100000, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/komponen-uang-jalan/{$id}")->assertStatus(422);

        DB::table('proyek_rute')->where('id_proyek_rute', $idProyekRute)->update(['dihapus_pada' => now()]);
        $this->deleteJson("/api/komponen-uang-jalan/{$id}")->assertStatus(200);
    }

    public function test_komponen_perusahaan_lain_tidak_terlihat_dan_tidak_bisa_diubah(): void
    {
        $asing = (string) Str::uuid();
        DB::table('komponen_uang_jalan')->insert([
            'id_komponen_uang_jalan' => $asing, 'id_perusahaan' => (string) Str::uuid(),
            'nama' => 'Tol', 'urutan' => 1, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/komponen-uang-jalan')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/komponen-uang-jalan/opsi')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson("/api/komponen-uang-jalan/{$asing}")->assertStatus(404);
        $this->putJson("/api/komponen-uang-jalan/{$asing}", ['nama' => 'Bocor'])->assertStatus(404);
        $this->deleteJson("/api/komponen-uang-jalan/{$asing}")->assertStatus(404);
    }

    public function test_izin_kelola_dan_opsi_untuk_pemakai_form(): void
    {
        $this->actingAsRole('MANAGER');
        $this->getJson('/api/komponen-uang-jalan')->assertStatus(200);
        $this->tambah(['nama' => 'Tol'])->assertStatus(403);

        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/komponen-uang-jalan')->assertStatus(403);
        $this->getJson('/api/komponen-uang-jalan/opsi')->assertStatus(200);

        $this->actingAsRole('SUPIR');
        $this->getJson('/api/komponen-uang-jalan/opsi')->assertStatus(403);
    }

    public function test_perusahaan_baru_dan_seeder_mendapat_komponen_bawaan_sekali_saja(): void
    {
        $baru = app(\App\Modules\Perusahaan\PerusahaanService::class)->create(['nama' => 'PT Cabang Baru']);
        $nama = fn (string $id) => DB::table('komponen_uang_jalan')->where('id_perusahaan', $id)->orderBy('urutan')->pluck('nama')->all();
        $this->assertSame(['Tol', 'BBM', 'Biaya Lain'], $nama((string) $baru->id_perusahaan));

        $this->seed(\Database\Seeders\KomponenUangJalanSeeder::class);
        $this->assertSame(['Tol', 'BBM', 'Biaya Lain'], $nama(self::PERUSAHAAN_ID));
        $this->assertCount(3, $nama((string) $baru->id_perusahaan));

        DB::table('komponen_uang_jalan')->where('id_perusahaan', self::PERUSAHAAN_ID)->update(['dihapus_pada' => now()]);
        $this->seed(\Database\Seeders\KomponenUangJalanSeeder::class);
        $this->assertSame(0, DB::table('komponen_uang_jalan')->where('id_perusahaan', self::PERUSAHAAN_ID)->whereNull('dihapus_pada')->count());
    }

    public function test_migrasi_aman_dijalankan_ulang(): void
    {
        $migrasi = require database_path('migrations/2026_10_15_200000_create_komponen_uang_jalan.php');
        $migrasi->down();
        $this->proyekRute(self::PERUSAHAAN_ID, ['uang_jalan' => 100000, 'estimasi_tol' => 100000]);

        $migrasi->up();
        $migrasi->up();

        $this->assertSame(3, DB::table('komponen_uang_jalan')->count());
        $this->assertSame(1, DB::table('proyek_rute_komponen')->count());
    }

    public function test_migrasi_memindahkan_rincian_lama_tanpa_mengubah_total(): void
    {
        $migrasi = require database_path('migrations/2026_10_15_200000_create_komponen_uang_jalan.php');
        $migrasi->down();

        $cocok   = $this->proyekRute(self::PERUSAHAAN_ID, ['uang_jalan' => 150000, 'estimasi_tol' => 100000, 'estimasi_bbm' => 50000]);
        $kurang  = $this->proyekRute(self::PERUSAHAAN_ID, ['uang_jalan' => 200000, 'estimasi_tol' => 100000, 'estimasi_biaya_lain' => 30000]);
        $lebih   = $this->proyekRute(self::PERUSAHAAN_ID, ['uang_jalan' => 20000, 'estimasi_tol' => 1000000]);
        $kosong  = $this->proyekRute(self::PERUSAHAAN_ID, ['uang_jalan' => null]);
        $idUj = (string) Str::uuid();
        DB::table('uang_jalan')->insert([
            'id_uang_jalan' => $idUj, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_uang_jalan' => 'UJ-202610-0001',
            'tanggal' => '2026-10-01', 'nama_driver' => 'Budi', 'tipe_driver' => 'internal', 'nopol' => 'B 1 A',
            'rute' => 'A - B', 'uang_jalan_per_trip' => 1000000, 'jumlah_trip' => 10, 'nominal' => 10000000,
            'nomor_rekening' => '123', 'nama_bank' => 'BCA', 'dibuat_pada' => now(),
        ]);

        $migrasi->up();

        $komponen = DB::table('komponen_uang_jalan')->where('id_perusahaan', self::PERUSAHAAN_ID)->orderBy('urutan')->pluck('nama')->all();
        $this->assertSame(['Tol', 'BBM', 'Biaya Lain'], $komponen);

        $rincianRute = fn (string $id) => DB::table('proyek_rute_komponen as prk')
            ->join('komponen_uang_jalan as k', 'k.id_komponen_uang_jalan', '=', 'prk.id_komponen_uang_jalan')
            ->where('prk.id_proyek_rute', $id)->orderBy('k.urutan')
            ->pluck('prk.nominal', 'k.nama')->map(fn ($n) => (float) $n)->all();

        $this->assertSame(['Tol' => 100000.0, 'BBM' => 50000.0], $rincianRute($cocok));
        $this->assertSame(['Tol' => 100000.0, 'Biaya Lain' => 100000.0], $rincianRute($kurang));
        $this->assertSame(['Tol' => 1000000.0], $rincianRute($lebih));
        $this->assertSame([], $rincianRute($kosong));
        $this->assertEquals(20000, DB::table('proyek_rute')->where('id_proyek_rute', $lebih)->value('uang_jalan'));
        $this->assertEquals(200000, DB::table('proyek_rute')->where('id_proyek_rute', $kurang)->value('uang_jalan'));

        $rincianUj = DB::table('uang_jalan_rincian')->where('id_uang_jalan', $idUj)->get();
        $this->assertCount(1, $rincianUj);
        $this->assertSame('Biaya Lain', $rincianUj[0]->nama_komponen);
        $this->assertEquals(1000000, $rincianUj[0]->nominal);
        $this->assertEquals(10000000, DB::table('uang_jalan')->where('id_uang_jalan', $idUj)->value('nominal'));
    }
}
