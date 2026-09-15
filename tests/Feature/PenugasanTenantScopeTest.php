<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeData(string $idPerusahaan, string $nopol): array
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek ' . $nopol,
        ]);
        $armada = ArmadaModel::create(['id_perusahaan' => $idPerusahaan, 'nopol' => $nopol, 'merk' => 'Hino']);
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => $idPerusahaan, 'nama' => 'Supir ' . $nopol,
            'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_armada'     => $armada->id_armada,
            'id_supir'      => $idSupir,
            'tanggal_tugas' => '2026-09-15',
            'status'        => 'aktif',
            'keterangan'    => 'Asli',
        ]);

        return [
            'id_proyek'    => $proyek->id_proyek,
            'id_armada'    => $armada->id_armada,
            'id_supir'     => $idSupir,
            'id_penugasan' => $penugasan->id_penugasan,
        ];
    }

    private function perusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_filter_list_dengan_proyek_armada_supir_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = $this->makeData($this->perusahaanLain(), 'D 9999 ZZ');

        $this->getJson('/api/penugasan?id_proyek=' . $lain['id_proyek'])->assertStatus(404);
        $this->getJson('/api/penugasan?id_armada=' . $lain['id_armada'])->assertStatus(404);
        $this->getJson('/api/penugasan?id_supir=' . $lain['id_supir'])->assertStatus(404);
    }

    public function test_filter_list_milik_sendiri_tetap_berfungsi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sendiri = $this->makeData(self::PERUSAHAAN_ID, 'B 1111 AA');

        foreach (['id_proyek', 'id_armada', 'id_supir'] as $filter) {
            $this->getJson("/api/penugasan?{$filter}=" . $sendiri[$filter])
                ->assertStatus(200)
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id_penugasan', $sendiri['id_penugasan']);
        }
    }

    public function test_detail_dan_ubah_penugasan_perusahaan_lain_404_tanpa_mengubah_data(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = $this->makeData($this->perusahaanLain(), 'D 9999 ZZ');

        $this->getJson('/api/penugasan/' . $lain['id_penugasan'])->assertStatus(404);
        $this->putJson('/api/penugasan/' . $lain['id_penugasan'], ['keterangan' => 'Diubah tenant lain'])->assertStatus(404);

        $this->assertSame('Asli', DB::table('penugasan')->where('id_penugasan', $lain['id_penugasan'])->value('keterangan'));
    }

    public function test_detail_dan_ubah_penugasan_milik_sendiri_tetap_berfungsi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sendiri = $this->makeData(self::PERUSAHAAN_ID, 'B 1111 AA');

        $this->getJson('/api/penugasan/' . $sendiri['id_penugasan'])
            ->assertStatus(200)
            ->assertJsonPath('data.id_penugasan', $sendiri['id_penugasan']);
        $this->putJson('/api/penugasan/' . $sendiri['id_penugasan'], ['keterangan' => 'Diubah'])
            ->assertStatus(200)
            ->assertJsonPath('data.keterangan', 'Diubah');
    }
}
