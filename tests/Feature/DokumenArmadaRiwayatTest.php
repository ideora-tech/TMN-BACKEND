<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenArmadaRiwayatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeArmada(string $nopol = 'B 1234 XYZ', string $idPerusahaan = self::PERUSAHAAN_ID): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makeDokumen(string $idArmada, string $jenis = 'STNK', ?string $berlakuSampai = '2026-12-31', array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert(array_merge([
            'id_dokumen_armada' => $id,
            'id_armada'         => $idArmada,
            'jenis_dokumen'     => $jenis,
            'nomor'             => 'NO-' . $jenis,
            'berlaku_sampai'    => $berlakuSampai,
            'url_file'          => 'dokumen/lama.pdf',
            'aktif'             => 1,
            'dibuat_pada'       => now(),
        ], $extra));
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    private function file(string $nama = 'dok.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nama, 100, 'application/pdf');
    }

    public function test_input_banyak_dokumen_sekaligus_untuk_satu_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->post("/api/armada/{$armada->id_armada}/dokumen/batch", [
            'dokumen' => [
                ['jenis_dokumen' => 'STNK', 'nomor' => 'STNK-1', 'berlaku_sampai' => '2027-01-01', 'file' => $this->file('stnk.pdf')],
                ['jenis_dokumen' => 'KIR', 'nomor' => 'KIR-1', 'berlaku_sampai' => '2026-12-01', 'file' => $this->file('kir.pdf')],
                ['jenis_dokumen' => 'Asuransi', 'file' => $this->file('asuransi.pdf')],
            ],
        ]);

        $res->assertStatus(201)->assertJsonCount(3, 'data');
        $this->assertSame(3, DB::table('dokumen_armada')->where('id_armada', $armada->id_armada)->where('aktif', 1)->count());
        $this->assertCount(3, Storage::disk('public')->allFiles('dokumen'));
    }

    public function test_input_banyak_tolak_jenis_dokumen_ganda_dalam_satu_kiriman(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->post("/api/armada/{$armada->id_armada}/dokumen/batch", [
            'dokumen' => [
                ['jenis_dokumen' => 'STNK', 'file' => $this->file()],
                ['jenis_dokumen' => 'STNK', 'file' => $this->file()],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, DB::table('dokumen_armada')->count());
    }

    public function test_input_banyak_tolak_jenis_yang_sudah_ada_di_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'KIR');

        $res = $this->post("/api/armada/{$armada->id_armada}/dokumen/batch", [
            'dokumen' => [
                ['jenis_dokumen' => 'STNK', 'file' => $this->file()],
                ['jenis_dokumen' => 'KIR', 'file' => $this->file()],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('KIR', (string) $res->json('message'));
        $this->assertSame(1, DB::table('dokumen_armada')->count());
    }

    public function test_input_banyak_wajib_file_per_dokumen(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $this->post("/api/armada/{$armada->id_armada}/dokumen/batch", [
            'dokumen' => [['jenis_dokumen' => 'STNK']],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_jenis_lainnya_boleh_lebih_dari_satu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'Lainnya');

        $res = $this->post("/api/armada/{$armada->id_armada}/dokumen/batch", [
            'dokumen' => [
                ['jenis_dokumen' => 'Lainnya', 'nomor' => 'GPS', 'file' => $this->file()],
                ['jenis_dokumen' => 'Lainnya', 'nomor' => 'Trayek', 'file' => $this->file()],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertSame(3, DB::table('dokumen_armada')->where('jenis_dokumen', 'Lainnya')->count());
    }

    public function test_tambah_satuan_tolak_jenis_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'STNK');

        $this->post("/api/armada/{$armada->id_armada}/dokumen", [
            'jenis_dokumen' => 'STNK',
            'file'          => $this->file(),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_perpanjang_membuat_dokumen_baru_dan_dokumen_lama_tetap_tersimpan_sebagai_riwayat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $idLama = $this->makeDokumen($armada->id_armada, 'STNK', '2026-09-01');

        $res = $this->post("/api/armada/{$armada->id_armada}/dokumen/{$idLama}/perpanjang", [
            'nomor'          => 'STNK-BARU',
            'berlaku_sampai' => '2027-09-01',
            'file'           => $this->file('stnk-baru.pdf'),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_dokumen', 'STNK')
            ->assertJsonPath('data.nomor', 'STNK-BARU')
            ->assertJsonPath('data.aktif', true)
            ->assertJsonPath('data.id_dokumen_sebelumnya', $idLama);
        $idBaru = (string) $res->json('data.id_dokumen_armada');

        $this->assertDatabaseHas('dokumen_armada', ['id_dokumen_armada' => $idLama, 'aktif' => 0, 'url_file' => 'dokumen/lama.pdf', 'dihapus_pada' => null]);

        $list = $this->getJson('/api/dokumen-armada')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($idBaru, $list[0]['id_dokumen_armada']);

        $this->getJson("/api/armada/{$armada->id_armada}/dokumen")->assertStatus(200)->assertJsonCount(1, 'data');

        $detail = $this->getJson("/api/dokumen-armada/{$idBaru}")->assertStatus(200);
        $detail->assertJsonCount(1, 'data.riwayat')
            ->assertJsonPath('data.riwayat.0.id_dokumen_armada', $idLama)
            ->assertJsonPath('data.riwayat.0.aktif', false)
            ->assertJsonPath('data.armada_nopol', 'B 1234 XYZ');

        $this->getJson("/api/dokumen-armada/{$idLama}")->assertStatus(200)
            ->assertJsonPath('data.id_dokumen_pengganti', $idBaru);
    }

    public function test_perpanjang_berulang_menyimpan_seluruh_rantai_riwayat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $id1 = $this->makeDokumen($armada->id_armada, 'KIR', '2026-01-01');

        $id2 = (string) $this->post("/api/armada/{$armada->id_armada}/dokumen/{$id1}/perpanjang", [
            'berlaku_sampai' => '2026-07-01', 'file' => $this->file(),
        ])->assertStatus(201)->json('data.id_dokumen_armada');
        $id3 = (string) $this->post("/api/armada/{$armada->id_armada}/dokumen/{$id2}/perpanjang", [
            'berlaku_sampai' => '2027-01-01', 'file' => $this->file(),
        ])->assertStatus(201)->json('data.id_dokumen_armada');

        $this->getJson("/api/dokumen-armada/{$id3}")->assertStatus(200)
            ->assertJsonCount(2, 'data.riwayat')
            ->assertJsonPath('data.riwayat.0.id_dokumen_armada', $id2)
            ->assertJsonPath('data.riwayat.1.id_dokumen_armada', $id1)
            ->assertJsonPath('data.nomor', 'NO-KIR');
    }

    public function test_perpanjang_wajib_file_dan_tanggal_berlaku_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $id = $this->makeDokumen($armada->id_armada);

        $this->post("/api/armada/{$armada->id_armada}/dokumen/{$id}/perpanjang", [
            'berlaku_sampai' => '2027-12-31',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/armada/{$armada->id_armada}/dokumen/{$id}/perpanjang", [
            'file' => $this->file(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, DB::table('dokumen_armada')->count());
    }

    public function test_perpanjang_tolak_tanggal_berlaku_tidak_setelah_tanggal_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $id = $this->makeDokumen($armada->id_armada, 'STNK', '2026-12-31');

        $this->post("/api/armada/{$armada->id_armada}/dokumen/{$id}/perpanjang", [
            'berlaku_sampai' => '2026-12-31',
            'file'           => $this->file(),
        ])->assertStatus(422);

        $this->assertDatabaseHas('dokumen_armada', ['id_dokumen_armada' => $id, 'aktif' => 1]);
    }

    public function test_dokumen_riwayat_tidak_bisa_diperpanjang_diubah_atau_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $idRiwayat = $this->makeDokumen($armada->id_armada, 'STNK', '2025-01-01', ['aktif' => 0]);
        $this->makeDokumen($armada->id_armada, 'STNK', '2026-01-01', ['id_dokumen_sebelumnya' => $idRiwayat]);

        $this->post("/api/armada/{$armada->id_armada}/dokumen/{$idRiwayat}/perpanjang", [
            'berlaku_sampai' => '2028-01-01', 'file' => $this->file(),
        ])->assertStatus(422);
        $this->putJson("/api/armada/{$armada->id_armada}/dokumen/{$idRiwayat}", ['nomor' => 'X'])->assertStatus(422);
        $this->deleteJson("/api/armada/{$armada->id_armada}/dokumen/{$idRiwayat}")->assertStatus(422);

        $this->assertDatabaseHas('dokumen_armada', ['id_dokumen_armada' => $idRiwayat, 'aktif' => 0, 'dihapus_pada' => null]);
    }

    public function test_hapus_dokumen_hasil_perpanjangan_mengaktifkan_kembali_dokumen_sebelumnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $idLama = $this->makeDokumen($armada->id_armada, 'STNK', '2026-09-01');
        $idBaru = (string) $this->post("/api/armada/{$armada->id_armada}/dokumen/{$idLama}/perpanjang", [
            'berlaku_sampai' => '2027-09-01', 'file' => $this->file(),
        ])->assertStatus(201)->json('data.id_dokumen_armada');

        $this->deleteJson("/api/armada/{$armada->id_armada}/dokumen/{$idBaru}")->assertStatus(200);

        $this->assertSoftDeleted('dokumen_armada', ['id_dokumen_armada' => $idBaru]);
        $this->assertDatabaseHas('dokumen_armada', ['id_dokumen_armada' => $idLama, 'aktif' => 1]);
    }

    public function test_ubah_jenis_ke_jenis_yang_sudah_ada_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'STNK');
        $idKir = $this->makeDokumen($armada->id_armada, 'KIR');

        $this->putJson("/api/armada/{$armada->id_armada}/dokumen/{$idKir}", ['jenis_dokumen' => 'STNK'])->assertStatus(422);
        $this->putJson("/api/armada/{$armada->id_armada}/dokumen/{$idKir}", ['jenis_dokumen' => 'KIR', 'nomor' => 'KIR-2'])
            ->assertStatus(200)->assertJsonPath('data.nomor', 'KIR-2');
    }

    public function test_dokumen_riwayat_tidak_masuk_peringatan_kedaluwarsa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $idLama = $this->makeDokumen($armada->id_armada, 'STNK', now()->addDays(5)->toDateString());

        $this->assertCount(1, $this->getJson('/api/dokumen-armada/expiring?days=30')->json('data'));

        $this->post("/api/armada/{$armada->id_armada}/dokumen/{$idLama}/perpanjang", [
            'berlaku_sampai' => now()->addYear()->toDateString(), 'file' => $this->file(),
        ])->assertStatus(201);

        $this->assertCount(0, $this->getJson('/api/dokumen-armada/expiring?days=30')->json('data'));
    }

    public function test_akses_dokumen_armada_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaSendiri = $this->makeArmada('B 1111 AA');
        $armadaLain = $this->makeArmada('D 9999 ZZ', $this->makePerusahaanLain());
        $idLain = $this->makeDokumen($armadaLain->id_armada);
        $idSendiri = $this->makeDokumen($armadaSendiri->id_armada);

        $this->getJson("/api/armada/{$armadaLain->id_armada}/dokumen")->assertStatus(404);
        $this->getJson("/api/dokumen-armada/{$idLain}")->assertStatus(404);
        $this->post("/api/armada/{$armadaLain->id_armada}/dokumen/batch", [
            'dokumen' => [['jenis_dokumen' => 'KIR', 'file' => $this->file()]],
        ])->assertStatus(404);
        $this->post("/api/armada/{$armadaLain->id_armada}/dokumen", [
            'jenis_dokumen' => 'KIR', 'file' => $this->file(),
        ])->assertStatus(404);
        $this->putJson("/api/armada/{$armadaLain->id_armada}/dokumen/{$idLain}", ['nomor' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/armada/{$armadaLain->id_armada}/dokumen/{$idLain}")->assertStatus(404);
        $this->post("/api/armada/{$armadaLain->id_armada}/dokumen/{$idLain}/perpanjang", [
            'berlaku_sampai' => '2028-01-01', 'file' => $this->file(),
        ])->assertStatus(404);

        $this->putJson("/api/armada/{$armadaLain->id_armada}/dokumen/{$idSendiri}", ['nomor' => 'X'])->assertStatus(404);

        $this->assertDatabaseHas('dokumen_armada', ['id_dokumen_armada' => $idLain, 'aktif' => 1, 'dihapus_pada' => null]);
    }
}
