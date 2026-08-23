<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Supir Proyek Test',
        ]);
    }

    private function makeSupir(string $nama = 'Budi', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeShift(string $nama = 'Pagi'): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'jam_mulai' => '08:00:00', 'jam_selesai' => '16:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJadwalShift(string $idProyek, string $idSupir, string $idShift, string $tanggal): string
    {
        $id = (string) Str::uuid();
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => $id, 'id_proyek' => $idProyek, 'id_shift' => $idShift,
            'id_supir' => $idSupir, 'tanggal' => $tanggal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_tambah_batch_dan_list(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirA = $this->makeSupir('Budi');
        $supirB = $this->makeSupir('Andi');

        $res = $this->postJson('/api/v1/supir-proyek', [
            'id_proyek' => $proyek->id_proyek,
            'supir'     => [$supirA, $supirB],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 2)
            ->assertJsonPath('data.gagal', []);

        $list = $this->getJson("/api/v1/supir-proyek?id_proyek={$proyek->id_proyek}");
        $list->assertStatus(200);
        $this->assertCount(2, $list->json('data'));
        $this->assertSame('Andi', $list->json('data.0.nama'));
        $this->assertSame('Budi', $list->json('data.1.nama'));
    }

    public function test_duplikat_dan_supir_asing_gagal_per_item(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirTerdaftar = $this->makeSupir('Budi');
        $supirAsing     = $this->makeSupir('Asing', (string) Str::uuid());

        $this->postJson('/api/v1/supir-proyek', [
            'id_proyek' => $proyek->id_proyek,
            'supir'     => [$supirTerdaftar],
        ])->assertJsonPath('data.sukses', 1);

        $res = $this->postJson('/api/v1/supir-proyek', [
            'id_proyek' => $proyek->id_proyek,
            'supir'     => [$supirTerdaftar, $supirAsing],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 0);

        $gagal = $res->json('data.gagal');
        $this->assertCount(2, $gagal);
        $this->assertSame($supirTerdaftar, $gagal[0]['id_supir']);
        $this->assertSame('Supir sudah terdaftar di proyek ini', $gagal[0]['alasan']);
        $this->assertSame($supirAsing, $gagal[1]['id_supir']);
        $this->assertSame('Supir tidak ditemukan', $gagal[1]['alasan']);
    }

    public function test_hapus_membersihkan_shift_mulai_hari_ini(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir  = $this->makeSupir('Wawan');
        $shift  = $this->makeShift();

        $this->postJson('/api/v1/supir-proyek', [
            'id_proyek' => $proyek->id_proyek,
            'supir'     => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        $idSupirProyek = (string) DB::table('supir_proyek')
            ->where('id_proyek', $proyek->id_proyek)
            ->where('id_supir', $supir)
            ->value('id_supir_proyek');
        $this->assertNotEmpty($idSupirProyek);

        $kemarin = now()->subDay()->toDateString();
        $hariIni = now()->toDateString();
        $besok   = now()->addDay()->toDateString();

        $jadwalKemarin = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $kemarin);
        $jadwalHariIni = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $hariIni);
        $jadwalBesok   = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $besok);

        $this->deleteJson("/api/v1/supir-proyek/{$idSupirProyek}")
            ->assertStatus(200);

        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalKemarin, 'dihapus_pada' => null]);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni]);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $jadwalBesok]);
        $this->assertSoftDeleted('supir_proyek', ['id_supir_proyek' => $idSupirProyek]);
    }
}
