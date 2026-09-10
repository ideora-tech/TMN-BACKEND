<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinPetugasPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN = 'DISPATCHER';

    private function idMenu(string $path): string
    {
        $id = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $id, 'nama_menu' => trim($path, '/'), 'path' => $path,
                'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        return (string) $id;
    }

    private function setIzin(string $path, string $aksi, int $diizinkan): void
    {
        $idMenu = $this->idMenu($path);
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', self::PERAN)->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => self::PERAN,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function petugasPerawatan(bool $punyaIzinPerawatan = true): void
    {
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            $this->setIzin('/perawatan-armada', $aksi, $punyaIzinPerawatan ? 1 : 0);
            $this->setIzin('/armada', $aksi, 0);
            $this->setIzin('/supplier', $aksi, 0);
            $this->setIzin('/sparepart', $aksi, 0);
        }

        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => self::PERAN, 'username' => 'mekanik_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
    }

    private function makeArmadaBerjenis(): ArmadaModel
    {
        $idJenis = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'CDD', 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return ArmadaModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nopol'              => 'B 7777 MK',
            'merk'               => 'Hino',
            'id_jenis_kendaraan' => $idJenis,
        ]);
    }

    public function test_petugas_dengan_izin_perawatan_saja_bisa_membuka_papan_unit(): void
    {
        $this->petugasPerawatan();
        $armada = $this->makeArmadaBerjenis();

        $this->getJson('/api/perawatan-armada/papan-unit')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id_armada', $armada->id_armada)
            ->assertJsonPath('data.0.merk', 'Hino')
            ->assertJsonPath('data.0.id_jenis_kendaraan', $armada->id_jenis_kendaraan);
    }

    public function test_petugas_dengan_izin_perawatan_saja_bisa_mencatat_dan_mengubah_perawatan(): void
    {
        $this->petugasPerawatan();
        $armada = $this->makeArmadaBerjenis();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'    => now()->toDateString(),
            'biaya'      => 150000,
            'status'     => 'dalam_proses',
            'sparepart'  => [['sumber' => 'bengkel', 'nama_sparepart' => 'Kampas rem', 'qty' => 1, 'harga' => 90000]],
        ])->assertStatus(201);

        $id = (string) $res->json('data.id_perawatan');
        $this->getJson("/api/armada/{$armada->id_armada}/perawatan/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.armada_nopol', 'B 7777 MK')
            ->assertJsonPath('data.armada_merk', 'Hino');
        $this->patchJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", ['status' => 'selesai'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');

        $this->getJson('/api/perawatan-armada?status=selesai')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_petugas_perawatan_bisa_membaca_data_referensi_form(): void
    {
        $this->petugasPerawatan();

        $this->getJson('/api/interval-perawatan')->assertStatus(200);
        $this->getJson('/api/supplier?aktif=1')->assertStatus(200);
        $this->getJson('/api/sparepart')->assertStatus(200);
    }

    public function test_petugas_perawatan_tidak_bisa_mengubah_data_master(): void
    {
        $this->petugasPerawatan();

        $this->postJson('/api/supplier', ['nama' => 'Bengkel Baru'])->assertStatus(403);
        $this->postJson('/api/sparepart', ['nama' => 'Oli'])->assertStatus(403);
        $this->postJson('/api/interval-perawatan', ['interval_bulan' => 6])->assertStatus(403);
        $this->getJson('/api/sparepart/' . Str::uuid() . '/mutasi')->assertStatus(403);
    }

    public function test_tanpa_izin_perawatan_papan_unit_403(): void
    {
        $this->petugasPerawatan(false);

        $this->getJson('/api/perawatan-armada/papan-unit')->assertStatus(403);
        $this->getJson('/api/supplier')->assertStatus(403);
    }
}
