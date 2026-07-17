<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanJabatanLokasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(string $nama = 'Supervisor'): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'     => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'kode_jabatan'   => 'JBT-' . Str::random(4),
            'nama_jabatan'   => $nama,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return $id;
    }

    private function makeLokasi(string $nama = 'Kantor Pusat'): string
    {
        $id = (string) Str::uuid();
        DB::table('lokasi_kantor')->insert([
            'id_lokasi'      => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'kode_lokasi'    => 'LOK-' . Str::random(4),
            'nama_lokasi'    => $nama,
            'radius'         => 100,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return $id;
    }

    private function makeKaryawan(string $idJabatan, string $idLokasi): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'         => $id,
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'id_jabatan'          => $idJabatan,
            'id_lokasi'           => $idLokasi,
            'nik'                 => 'NIK-' . Str::random(6),
            'nama_karyawan'       => 'Budi Santoso',
            'status_kepegawaian'  => 'tetap',
            'gaji_pokok'          => 5000000,
            'aktif'               => 1,
            'dibuat_pada'         => now(),
        ]);
        return $id;
    }

    public function test_list_karyawan_menyertakan_nama_jabatan_dan_lokasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJabatan = $this->makeJabatan('Manager Operasional');
        $idLokasi = $this->makeLokasi('Gudang Cikarang');
        $this->makeKaryawan($idJabatan, $idLokasi);

        $res = $this->getJson('/api/v1/karyawan');

        $res->assertStatus(200);
        $data = $res->json('data')[0];
        $this->assertSame('Manager Operasional', $data['jabatan']['nama_jabatan']);
        $this->assertSame('Gudang Cikarang', $data['lokasi']['nama_lokasi']);
    }

    public function test_show_karyawan_menyertakan_nama_jabatan_dan_lokasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJabatan = $this->makeJabatan('Staff Admin');
        $idLokasi = $this->makeLokasi('Kantor Cabang');
        $idKaryawan = $this->makeKaryawan($idJabatan, $idLokasi);

        $res = $this->getJson("/api/v1/karyawan/{$idKaryawan}");

        $res->assertStatus(200)
            ->assertJsonPath('data.jabatan.nama_jabatan', 'Staff Admin')
            ->assertJsonPath('data.lokasi.nama_lokasi', 'Kantor Cabang');
    }

    public function test_karyawan_tanpa_jabatan_lokasi_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Tanpa Jabatan',
            'status_kepegawaian' => 'kontrak',
            'gaji_pokok'         => 3000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        $res = $this->getJson("/api/v1/karyawan/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.jabatan', null)
            ->assertJsonPath('data.lokasi', null);
    }
}
