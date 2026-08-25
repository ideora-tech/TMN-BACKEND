<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\KodeOtomatis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PengaturanKodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_default_tanpa_baris_pengaturan_kode(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/pengaturan-kode');

        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('entitas');

        $this->assertSame('PRJ', $data['proyek']['prefix']);
        $this->assertSame(4, $data['proyek']['panjang_digit']);
        $this->assertSame('tahunan', $data['proyek']['reset']);
        $this->assertFalse($data['proyek']['tersimpan']);

        $this->assertSame('RT', $data['rute']['prefix']);
        $this->assertSame(4, $data['rute']['panjang_digit']);
        $this->assertSame('tidak', $data['rute']['reset']);
        $this->assertFalse($data['rute']['tersimpan']);

        $this->assertSame('PNW', $data['penawaran']['prefix']);
        $this->assertSame(4, $data['penawaran']['panjang_digit']);
        $this->assertSame('bulanan', $data['penawaran']['reset']);
        $this->assertFalse($data['penawaran']['tersimpan']);
    }

    public function test_update_prefix_rute_tersimpan_dan_dipakai_kode_otomatis(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->putJson('/api/pengaturan-kode/rute', [
            'prefix'        => 'RUTE',
            'panjang_digit' => 4,
            'reset'         => 'tidak',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.entitas', 'rute')
            ->assertJsonPath('data.prefix', 'RUTE')
            ->assertJsonPath('data.tersimpan', true);

        $getRes = $this->getJson('/api/pengaturan-kode');
        $data = collect($getRes->json('data'))->keyBy('entitas');
        $this->assertSame('RUTE', $data['rute']['prefix']);
        $this->assertTrue($data['rute']['tersimpan']);

        $kode = KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute');
        $this->assertSame('RUTE-0001', $kode);
    }

    public function test_update_reset_invalid_dapat_422(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->putJson('/api/pengaturan-kode/rute', [
            'prefix'        => 'RT',
            'panjang_digit' => 4,
            'reset'         => 'mingguan',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('reset');
    }

    public function test_update_panjang_digit_di_luar_rentang_dapat_422(): void
    {
        $this->actingAsRole('ADMIN');

        $resKecil = $this->putJson('/api/pengaturan-kode/proyek', [
            'prefix'        => 'PRJ',
            'panjang_digit' => 2,
            'reset'         => 'tahunan',
        ]);
        $resKecil->assertStatus(422);
        $resKecil->assertJsonValidationErrors('panjang_digit');

        $resBesar = $this->putJson('/api/pengaturan-kode/proyek', [
            'prefix'        => 'PRJ',
            'panjang_digit' => 9,
            'reset'         => 'tahunan',
        ]);
        $resBesar->assertStatus(422);
        $resBesar->assertJsonValidationErrors('panjang_digit');
    }

    public function test_update_entitas_tidak_valid_dapat_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->putJson('/api/pengaturan-kode/armada', [
            'prefix'        => 'ARM',
            'panjang_digit' => 4,
            'reset'         => 'tidak',
        ]);

        $res->assertStatus(404);
    }

    public function test_update_dua_kali_idempoten_satu_baris_nilai_terakhir_menang(): void
    {
        $this->actingAsRole('ADMIN');

        $this->putJson('/api/pengaturan-kode/rute', [
            'prefix'        => 'RT1',
            'panjang_digit' => 4,
            'reset'         => 'tidak',
        ])->assertStatus(200);

        $res = $this->putJson('/api/pengaturan-kode/rute', [
            'prefix'        => 'RT2',
            'panjang_digit' => 5,
            'reset'         => 'bulanan',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.prefix', 'RT2')
            ->assertJsonPath('data.panjang_digit', 5)
            ->assertJsonPath('data.reset', 'bulanan');

        $this->assertSame(1, DB::table('pengaturan_kode')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('entitas', 'rute')
            ->count());

        $row = DB::table('pengaturan_kode')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('entitas', 'rute')
            ->first();
        $this->assertSame('RT2', $row->prefix);
        $this->assertSame(5, (int) $row->panjang_digit);
        $this->assertSame('bulanan', $row->reset);
    }

    public function test_role_keuangan_ditolak_akses_pengaturan_kode(): void
    {
        $this->actingAsRole('KEUANGAN');

        $resGet = $this->getJson('/api/pengaturan-kode');
        $resGet->assertStatus(403);

        $resPut = $this->putJson('/api/pengaturan-kode/rute', [
            'prefix'        => 'RT',
            'panjang_digit' => 4,
            'reset'         => 'tidak',
        ]);
        $resPut->assertStatus(403);
    }

    public function test_tenant_baris_perusahaan_lain_tidak_terbaca(): void
    {
        $this->actingAsRole('ADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Pengaturan Kode',
            'dibuat_pada'   => now(),
        ]);
        DB::table('pengaturan_kode')->insert([
            'id_pengaturan_kode' => (string) Str::uuid(),
            'id_perusahaan'      => $idPerusahaanLain,
            'entitas'            => 'rute',
            'prefix'             => 'LAIN',
            'panjang_digit'      => 5,
            'reset'              => 'tidak',
            'dibuat_pada'        => now(),
        ]);

        $res = $this->getJson('/api/pengaturan-kode');
        $data = collect($res->json('data'))->keyBy('entitas');

        $this->assertSame('RT', $data['rute']['prefix']);
        $this->assertFalse($data['rute']['tersimpan']);
    }
}
