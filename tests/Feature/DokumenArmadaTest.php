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

class DokumenArmadaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol = 'B 1234 XYZ'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makeDokumen(string $idArmada, string $jenis = 'STNK', ?string $berlakuSampai = '2026-12-31'): object
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => $id,
            'id_armada'         => $idArmada,
            'jenis_dokumen'     => $jenis,
            'berlaku_sampai'    => $berlakuSampai,
            'dibuat_pada'       => now(),
        ]);
        return DB::table('dokumen_armada')->where('id_dokumen_armada', $id)->first();
    }

    public function test_create_dokumen_via_endpoint_nested_dengan_upload_file_masih_berfungsi(): void
    {
        Storage::fake('public');
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->post("/api/v1/armada/{$armada->id_armada}/dokumen", [
            'jenis_dokumen'  => 'KIR',
            'nomor'          => 'KIR-001',
            'berlaku_sampai' => '2027-01-01',
            'file'           => UploadedFile::fake()->create('kir.pdf', 100, 'application/pdf'),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_dokumen', 'KIR');
        $this->assertNotNull($res->json('data.url_file'));

        $this->assertDatabaseHas('dokumen_armada', [
            'id_armada'     => $armada->id_armada,
            'jenis_dokumen' => 'KIR',
        ]);

        $filesTersimpan = Storage::disk('public')->allFiles('dokumen');
        $this->assertNotEmpty($filesTersimpan, 'File upload seharusnya benar-benar tersimpan di disk fake, bukan no-op.');
        Storage::disk('public')->assertExists($filesTersimpan[0]);
    }

    public function test_update_dan_delete_dokumen_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $dokumen = $this->makeDokumen($armada->id_armada);

        $resUpdate = $this->putJson("/api/v1/armada/{$armada->id_armada}/dokumen/{$dokumen->id_dokumen_armada}", [
            'nomor' => 'STNK-UPDATED',
        ]);
        $resUpdate->assertStatus(200)->assertJsonPath('data.nomor', 'STNK-UPDATED');

        $resDelete = $this->deleteJson("/api/v1/armada/{$armada->id_armada}/dokumen/{$dokumen->id_dokumen_armada}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('dokumen_armada', ['id_dokumen_armada' => $dokumen->id_dokumen_armada]);
    }

    public function test_list_lintas_armada_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaSendiri = $this->makeArmada('B 1111 AA');
        $this->makeDokumen($armadaSendiri->id_armada);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idArmadaLain = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaLain, 'id_perusahaan' => $idPerusahaanLain,
            'nopol' => 'D 9999 ZZ', 'dibuat_pada' => now(),
        ]);
        $this->makeDokumen($idArmadaLain);

        $res = $this->getJson('/api/v1/dokumen-armada');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armadaSendiri->id_armada, $data[0]['id_armada']);
        $this->assertSame('B 1111 AA', $data[0]['armada_nopol']);
    }

    public function test_list_lintas_armada_filter_id_armada_dan_jenis_dokumen(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');
        $this->makeDokumen($armadaA->id_armada, 'STNK');
        $this->makeDokumen($armadaB->id_armada, 'KIR');

        $resByArmada = $this->getJson("/api/v1/dokumen-armada?id_armada={$armadaA->id_armada}");
        $resByArmada->assertStatus(200);
        $this->assertCount(1, $resByArmada->json('data'));

        $resByJenis = $this->getJson('/api/v1/dokumen-armada?jenis_dokumen=KIR');
        $resByJenis->assertStatus(200);
        $this->assertCount(1, $resByJenis->json('data'));
        $this->assertSame('KIR', $resByJenis->json('data.0.jenis_dokumen'));
    }

    public function test_endpoint_expiring_yang_sudah_ada_tidak_berubah_bentuk(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'STNK', now()->addDays(10)->toDateString());

        $res = $this->getJson('/api/v1/dokumen-armada/expiring?days=30');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('STNK', $res->json('data.0.jenis_dokumen'));
    }
}
