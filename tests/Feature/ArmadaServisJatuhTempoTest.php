<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArmadaServisJatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol = 'B 1234 XY'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'status'        => 'tersedia',
        ]);
    }

    private function makePerawatan(string $idArmada, string $tanggal, ?string $jadwal, string $jenis = 'Ganti Oli'): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => $jenis, 'biaya' => 100000, 'status' => 'selesai',
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_endpoint_mengembalikan_armada_dengan_servis_jatuh_tempo(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(10)->toDateString(), 'Servis Besar');

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armada->id_armada, $data[0]['id_armada']);
        $this->assertSame('Servis Besar', $data[0]['jenis_perawatan']);
    }

    public function test_hanya_servis_terbaru_per_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-01-01', now()->addDays(5)->toDateString());
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(90)->toDateString());

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_param_days_custom(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(45)->toDateString());

        $resDefault = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $resDefault->assertStatus(200);
        $this->assertCount(0, $resDefault->json('data'));

        $resCustom = $this->getJson('/api/v1/armada/servis-jatuh-tempo?days=60');
        $resCustom->assertStatus(200);
        $this->assertCount(1, $resCustom->json('data'));
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $armadaLain = ArmadaModel::create(['id_perusahaan' => $lain, 'nopol' => 'D 9999 ZZ', 'status' => 'tersedia']);
        $this->makePerawatan($armadaLain->id_armada, '2026-06-01', now()->addDays(10)->toDateString());

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_route_terdaftar_sebelum_apiresource_show(): void
    {
        $this->actingAsRole('ADMIN');
        // Memastikan 'servis-jatuh-tempo' tidak tertangkap sebagai {id} pada GET armada/{id}
        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $res->assertStatus(200); // bukan 404 "Armada tidak ditemukan"
    }
}
