<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArusKasOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa4444-0000-4000-8000-000000000001';

    private function actingAsSupir(string $nama = 'Supir Mulai Saya'): object
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'supir_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $idSupir = $this->makeSupir($nama);
        DB::table('supir')->where('id_supir', $idSupir)->update(['id_pengguna' => $pengguna->id_pengguna]);

        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin'     => (string) Str::uuid(),
                'kode_peran'  => 'SUPIR',
                'id_menu'     => self::ID_MENU_TRIP,
                'aksi'        => $aksi,
                'diizinkan'   => 1,
                'dibuat_pada' => now(),
            ]);
        }

        Sanctum::actingAs($pengguna, ['*']);

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function absenHadir(string $idSupir): void
    {
        DB::table('absensi_supir')->insert([
            'id_absensi'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_supir'      => $idSupir,
            'tanggal'       => now()->toDateString(),
            'status'        => 'hadir',
            'dibuat_pada'   => now(),
        ]);
    }

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Arus Kas Otomatis',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenugasan(string $namaSupir = 'Budi Uang Jalan'): PenugasanModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Arus Kas Otomatis',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' AK',
            'merk'          => 'Hino',
        ])->id_armada;

        return PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir($namaSupir),
            'status'    => 'aktif',
        ]);
    }

    private function mulaiTripDenganUangJalan(float $alokasi, string $namaSupir = 'Budi Uang Jalan'): string
    {
        $penugasan = $this->makePenugasan($namaSupir);

        $res = $this->postJson('/api/v1/trip/mulai', [
            'id_penugasan'       => $penugasan->id_penugasan,
            'uang_jalan_alokasi' => $alokasi,
        ]);
        $res->assertStatus(201);

        return (string) $res->json('data.id_trip');
    }

    public function test_mulai_trip_dengan_uang_jalan_tidak_lagi_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idTrip = $this->mulaiTripDenganUangJalan(500000, 'Budi Uang Jalan');

        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
        $this->assertDatabaseHas('trip', ['id_trip' => $idTrip, 'uang_jalan_alokasi' => 500000]);
    }

    public function test_update_uang_jalan_tetap_jalan_tanpa_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTrip = $this->mulaiTripDenganUangJalan(500000);

        $this->putJson("/api/v1/trip/{$idTrip}/uang-jalan", ['uang_jalan_alokasi' => 750000])
            ->assertStatus(200);

        $this->assertDatabaseHas('trip', ['id_trip' => $idTrip, 'uang_jalan_alokasi' => 750000]);
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }
}
