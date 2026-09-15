<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanOdometerServisBerkalaTest extends TestCase
{
    use RefreshDatabase;

    private ArmadaModel $armada;
    private string $idPaket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('SUPERADMIN');

        $idJenis = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->idPaket = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $this->idPaket, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenis, 'interval_bulan' => 6, 'interval_km' => 10000,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 9001 TMN', 'id_jenis_kendaraan' => $idJenis,
        ]);
    }

    private function url(string $akhiran = ''): string
    {
        return "/api/armada/{$this->armada->id_armada}/perawatan{$akhiran}";
    }

    public function test_servis_berkala_tanpa_km_odometer_ditolak(): void
    {
        $this->postJson($this->url(), [
            'tanggal' => now()->toDateString(), 'status' => 'terjadwal',
            'id_interval_perawatan' => $this->idPaket,
        ])->assertStatus(422)->assertJsonValidationErrors(['km_odometer']);

        $this->postJson($this->url(), [
            'tanggal' => now()->toDateString(), 'status' => 'terjadwal',
            'id_interval_perawatan' => $this->idPaket, 'km_odometer' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['km_odometer']);
    }

    public function test_servis_berkala_dengan_km_dan_perbaikan_tanpa_km_diterima(): void
    {
        $this->postJson($this->url(), [
            'tanggal' => now()->toDateString(), 'status' => 'terjadwal',
            'id_interval_perawatan' => $this->idPaket, 'km_odometer' => 25000,
        ])->assertStatus(201)->assertJsonPath('data.km_odometer', 25000);

        $this->postJson($this->url(), [
            'tanggal' => now()->toDateString(), 'status' => 'terjadwal',
            'id_interval_perawatan' => null,
        ])->assertStatus(201);
    }

    public function test_ubah_ke_servis_berkala_tanpa_km_ditolak_tapi_ubah_status_saja_tetap_bisa(): void
    {
        $id = $this->postJson($this->url(), [
            'tanggal' => now()->toDateString(), 'status' => 'terjadwal',
        ])->assertStatus(201)->json('data.id_perawatan');

        $this->putJson($this->url("/{$id}"), [
            'id_interval_perawatan' => $this->idPaket, 'km_odometer' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['km_odometer']);

        $this->patchJson($this->url("/{$id}"), ['status' => 'dalam_proses'])->assertStatus(200);
    }
}
