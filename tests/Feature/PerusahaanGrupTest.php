<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerusahaanGrupTest extends TestCase
{
    use RefreshDatabase;

    private function tenantLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        return $id;
    }

    private function buatArmada(string $nopol, string $idGrup): string
    {
        return $this->postJson('/api/armada', ['nopol' => $nopol, 'id_perusahaan_grup' => $idGrup])
            ->assertStatus(201)
            ->json('data.id_armada');
    }

    public function test_crud_dan_singkatan_unik_tanpa_beda_huruf(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $id = $this->postJson('/api/perusahaan-grup', ['singkatan' => ' TMN ', 'nama_lengkap' => 'PT TMN Transport'])
            ->assertStatus(201)
            ->assertJsonPath('data.singkatan', 'TMN')
            ->json('data.id_perusahaan_grup');

        $this->postJson('/api/perusahaan-grup', ['singkatan' => 'tmn'])->assertStatus(409);
        $this->postJson('/api/perusahaan-grup', ['singkatan' => 'SULITA'])->assertStatus(201);
        $this->putJson("/api/perusahaan-grup/{$id}", ['singkatan' => 'Sulita'])->assertStatus(409);
        $this->putJson("/api/perusahaan-grup/{$id}", ['singkatan' => 'TMN', 'aktif' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.aktif', false);

        $opsi = collect($this->getJson('/api/perusahaan-grup/opsi-aktif')->assertStatus(200)->json('data'))->pluck('singkatan')->all();
        $this->assertSame(['SULITA'], $opsi);
        $this->assertSame(2, $this->getJson('/api/perusahaan-grup')->assertStatus(200)->json('meta.total'));
    }

    public function test_hapus_ditolak_bila_dipakai_armada_dan_data_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSulita = $this->perusahaanGrupId('SULITA');
        $idKosong = $this->perusahaanGrupId('KOSONG');
        $this->buatArmada('B 1000 GRP', $idSulita);
        $idLain = $this->perusahaanGrupId('LAIN', $this->tenantLain());

        $this->deleteJson("/api/perusahaan-grup/{$idSulita}")->assertStatus(422);
        $this->deleteJson("/api/perusahaan-grup/{$idKosong}")->assertStatus(200);
        $this->getJson("/api/perusahaan-grup/{$idLain}")->assertStatus(404);
        $this->putJson("/api/perusahaan-grup/{$idLain}", ['singkatan' => 'X'])->assertStatus(404);
    }

    public function test_armada_wajib_pemilik_aktif_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idNonaktif = $this->perusahaanGrupId('LAMA');
        DB::table('perusahaan_grup')->where('id_perusahaan_grup', $idNonaktif)->update(['aktif' => 0]);
        $idLain = $this->perusahaanGrupId('LAIN', $this->tenantLain());

        $this->postJson('/api/armada', ['nopol' => 'B 2000 GRP'])->assertStatus(422)
            ->assertJsonPath('errors.id_perusahaan_grup.0', 'Perusahaan pemilik wajib dipilih');
        $this->postJson('/api/armada', ['nopol' => 'B 2001 GRP', 'id_perusahaan_grup' => $idNonaktif])->assertStatus(422);
        $this->postJson('/api/armada', ['nopol' => 'B 2002 GRP', 'id_perusahaan_grup' => $idLain])->assertStatus(422);
        $this->assertSame(0, DB::table('armada')->where('nopol', 'like', 'B 200% GRP')->count());
    }

    public function test_armada_lama_tanpa_pemilik_tetap_bisa_diubah_dan_diberi_pemilik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTmn = $this->perusahaanGrupId('TMN');
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 3000 LMA',
            'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/armada/{$idArmada}", ['merk' => 'Hino'])->assertStatus(200);
        $this->putJson("/api/armada/{$idArmada}", ['id_perusahaan_grup' => $idTmn])->assertStatus(200);

        $this->getJson("/api/armada/{$idArmada}")->assertStatus(200)
            ->assertJsonPath('data.id_perusahaan_grup', $idTmn)
            ->assertJsonPath('data.singkatan_perusahaan_grup', 'TMN');
    }

    public function test_pencarian_armada_mencakup_nomor_rangka(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idGrup = $this->perusahaanGrupId();
        $this->postJson('/api/armada', ['nopol' => 'B 5101 RGK', 'nomor_rangka' => 'MHFXW42G5N0099887', 'id_perusahaan_grup' => $idGrup])->assertStatus(201);
        $this->postJson('/api/armada', ['nopol' => 'B 5102 RGK', 'nomor_rangka' => 'MHFXW42G5N0011223', 'id_perusahaan_grup' => $idGrup])->assertStatus(201);

        $hasil = collect($this->getJson('/api/armada?search=N009988')->assertStatus(200)->json('data'))->pluck('nopol')->all();

        $this->assertSame(['B 5101 RGK'], $hasil);
    }

    public function test_daftar_armada_menampilkan_singkatan_dan_bisa_disaring_per_pemilik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTmn = $this->perusahaanGrupId('TMN');
        $idSulita = $this->perusahaanGrupId('SULITA');
        $this->buatArmada('B 4001 TMN', $idTmn);
        $this->buatArmada('B 4002 SLT', $idSulita);
        DB::table('armada')->insert([
            'id_armada' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 4003 LMA',
            'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $semua = collect($this->getJson('/api/armada?limit=50')->assertStatus(200)->json('data'))->pluck('singkatan_perusahaan_grup', 'nopol');
        $this->assertSame('TMN', $semua['B 4001 TMN']);
        $this->assertSame('SULITA', $semua['B 4002 SLT']);
        $this->assertNull($semua['B 4003 LMA']);

        $tmn = collect($this->getJson("/api/armada?limit=50&id_perusahaan_grup={$idTmn}")->json('data'))->pluck('nopol')->all();
        $kosong = collect($this->getJson('/api/armada?limit=50&id_perusahaan_grup=kosong')->json('data'))->pluck('nopol')->all();
        $this->assertSame(['B 4001 TMN'], $tmn);
        $this->assertSame(['B 4003 LMA'], $kosong);
    }
}
