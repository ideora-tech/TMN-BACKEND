<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanArmadaTest extends TestCase
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

    private function makePerawatan(string $idArmada, string $tanggal = '2026-01-10', string $status = 'selesai'): object
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => $tanggal,
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => $status,
            'dibuat_pada'     => now(),
        ]);
        return DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
    }

    public function test_create_perawatan_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal'                  => '2026-02-01',
            'jenis_perawatan'          => 'Servis Besar',
            'biaya'                    => 1500000,
            'km_odometer'              => 50000,
            'status'                   => 'selesai',
            'jadwal_servis_berikutnya' => '2026-08-01',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Servis Besar')
            ->assertJsonPath('data.biaya', 1500000);

        $this->assertDatabaseHas('perawatan_armada', [
            'id_armada'       => $armada->id_armada,
            'jenis_perawatan' => 'Servis Besar',
        ]);
    }

    public function test_update_dan_delete_perawatan_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $perawatan = $this->makePerawatan($armada->id_armada);

        $resUpdate = $this->putJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}", [
            'status' => 'dalam_proses',
        ]);
        $resUpdate->assertStatus(200)->assertJsonPath('data.status', 'dalam_proses');

        $resDelete = $this->deleteJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('perawatan_armada', ['id_perawatan' => $perawatan->id_perawatan]);
    }

    public function test_list_lintas_armada_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaSendiri = $this->makeArmada('B 1111 AA');
        $this->makePerawatan($armadaSendiri->id_armada);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idArmadaLain = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaLain, 'id_perusahaan' => $idPerusahaanLain,
            'nopol' => 'D 9999 ZZ', 'dibuat_pada' => now(),
        ]);
        $this->makePerawatan($idArmadaLain);

        $res = $this->getJson('/api/v1/perawatan-armada');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armadaSendiri->id_armada, $data[0]['id_armada']);
        $this->assertSame('B 1111 AA', $data[0]['armada_nopol']);
    }

    public function test_list_lintas_armada_filter_id_armada_dan_status(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');
        $this->makePerawatan($armadaA->id_armada, '2026-01-01', 'selesai');
        $this->makePerawatan($armadaB->id_armada, '2026-01-02', 'terjadwal');

        $resByArmada = $this->getJson("/api/v1/perawatan-armada?id_armada={$armadaA->id_armada}");
        $resByArmada->assertStatus(200);
        $this->assertCount(1, $resByArmada->json('data'));
        $this->assertSame($armadaA->id_armada, $resByArmada->json('data.0.id_armada'));

        $resByStatus = $this->getJson('/api/v1/perawatan-armada?status=terjadwal');
        $resByStatus->assertStatus(200);
        $this->assertCount(1, $resByStatus->json('data'));
        $this->assertSame('terjadwal', $resByStatus->json('data.0.status'));
    }

    public function test_list_lintas_armada_urut_tanggal_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-01-01');
        $this->makePerawatan($armada->id_armada, '2026-03-01');

        $res = $this->getJson('/api/v1/perawatan-armada');

        $res->assertStatus(200);
        $this->assertSame('2026-03-01', $res->json('data.0.tanggal'));
    }

    public function test_filter_jatuh_tempo_hanya_servis_terbaru_per_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');

        // Armada A: servis lama dalam window (harus diabaikan), servis terbaru di luar window
        $this->makePerawatanDenganJadwal($armadaA->id_armada, '2026-01-01', now()->addDays(5)->toDateString());
        $this->makePerawatanDenganJadwal($armadaA->id_armada, '2026-06-01', now()->addDays(90)->toDateString());

        // Armada B: servis terbaru dalam window -> harus muncul
        $this->makePerawatanDenganJadwal($armadaB->id_armada, '2026-06-01', now()->addDays(15)->toDateString());

        $res = $this->getJson('/api/v1/perawatan-armada?jatuh_tempo=1');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armadaB->id_armada, $data[0]['id_armada']);
    }

    public function test_filter_jatuh_tempo_default_tidak_aktif(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01');

        $res = $this->getJson('/api/v1/perawatan-armada');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    private function makePerawatanDenganJadwal(string $idArmada, string $tanggal, ?string $jadwal): object
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => 'Ganti Oli', 'biaya' => 100000, 'status' => 'selesai',
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
    }
}
