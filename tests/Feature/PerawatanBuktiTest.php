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

class PerawatanBuktiTest extends TestCase
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

    private function makePerawatan(string $idArmada): object
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => '2026-07-31',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'terjadwal',
            'dibuat_pada'     => now(),
        ]);
        return DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
    }

    public function test_upload_bukti_tersimpan_dan_muncul_di_detail(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $perawatan = $this->makePerawatan($armada->id_armada);

        $res = $this->post("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}/bukti", [
            'bukti' => [
                UploadedFile::fake()->image('kerusakan.jpg'),
                UploadedFile::fake()->image('nota.png'),
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data.bukti')
            ->assertJsonPath('data.bukti.0.nama_asli', 'kerusakan.jpg')
            ->assertJsonPath('data.bukti.1.nama_asli', 'nota.png');

        $this->assertCount(2, Storage::disk('public')->files('perawatan'));
        $this->assertDatabaseCount('perawatan_armada_bukti', 2);

        $detail = $this->getJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}");
        $detail->assertStatus(200)->assertJsonCount(2, 'data.bukti');
    }

    public function test_hapus_bukti_dan_404_untuk_bukti_perawatan_lain(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $perawatanA = $this->makePerawatan($armada->id_armada);
        $perawatanB = $this->makePerawatan($armada->id_armada);

        $this->post("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatanA->id_perawatan}/bukti", [
            'bukti' => [UploadedFile::fake()->image('foto.jpg')],
        ])->assertStatus(200);

        $idBukti = DB::table('perawatan_armada_bukti')->value('id_bukti');

        $this->deleteJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatanB->id_perawatan}/bukti/{$idBukti}")
            ->assertStatus(404);

        $this->deleteJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatanA->id_perawatan}/bukti/{$idBukti}")
            ->assertStatus(200);

        $this->assertSoftDeleted('perawatan_armada_bukti', ['id_bukti' => $idBukti]);

        $this->getJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatanA->id_perawatan}")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.bukti');
    }

    public function test_validasi_upload_bukti(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $perawatan = $this->makePerawatan($armada->id_armada);
        $url = "/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}/bukti";

        $this->postJson($url, [])->assertStatus(422);

        $this->postJson($url, [
            'bukti' => [UploadedFile::fake()->create('catatan.txt', 10, 'text/plain')],
        ])->assertStatus(422);

        $this->postJson($url, [
            'bukti' => [UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')],
        ])->assertStatus(422);

        $banyak = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 11));
        $this->postJson($url, ['bukti' => $banyak])->assertStatus(422);
    }
}
