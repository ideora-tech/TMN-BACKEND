<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $idPerusahaan, string $nik, string $nama = 'Karyawan Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => $idPerusahaan,
            'nik'                => $nik,
            'nama_karyawan'      => $nama,
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('karyawan')->where('id_karyawan', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_karyawan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/karyawan', [
            'nik'                => 'NIK-001',
            'nama_karyawan'      => 'Andi Wijaya',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 5000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_karyawan', 'Andi Wijaya')
            ->assertJsonPath('data.gaji_pokok', 5000000)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('karyawan', ['nik' => 'NIK-001', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_karyawan_override_bpjs_opsional(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resTanpaOverride = $this->postJson('/api/karyawan', [
            'nik' => 'NIK-OVR-01', 'nama_karyawan' => 'Tanpa Override',
        ]);
        $resTanpaOverride->assertStatus(201)
            ->assertJsonPath('data.override_persen_bpjs_kesehatan', null)
            ->assertJsonPath('data.override_persen_bpjs_jht', null)
            ->assertJsonPath('data.override_tunjangan_jabatan', null);

        $resDenganOverride = $this->postJson('/api/karyawan', [
            'nik' => 'NIK-OVR-02', 'nama_karyawan' => 'Dengan Override',
            'override_persen_bpjs_kesehatan' => 2,
            'override_persen_bpjs_jht'       => 3,
            'override_persen_bpjs_jp'        => 1.5,
            'override_plafon_bpjs_kesehatan' => 10000000,
            'override_tunjangan_jabatan'     => 750000,
        ]);
        $resDenganOverride->assertStatus(201)
            ->assertJsonPath('data.override_persen_bpjs_kesehatan', 2)
            ->assertJsonPath('data.override_persen_bpjs_jht', 3)
            ->assertJsonPath('data.override_persen_bpjs_jp', 1.5)
            ->assertJsonPath('data.override_plafon_bpjs_kesehatan', 10000000)
            ->assertJsonPath('data.override_tunjangan_jabatan', 750000);
    }

    public function test_membuat_karyawan_dengan_field_hr_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/karyawan', [
            'nik'                => 'NIK-HR-001',
            'nik_ktp'            => '3171012345678901',
            'nama_karyawan'      => 'Budi Santoso',
            'tempat_lahir'       => 'Jakarta',
            'tanggal_lahir'      => '1990-05-10',
            'alamat_ktp'         => 'Jl. Merdeka No. 1, Jakarta',
            'alamat_domisili'    => 'Jl. Sudirman No. 2, Jakarta',
            'status_pernikahan'  => 'menikah',
            'jumlah_tanggungan'  => 2,
            'status_ptkp'        => 'K/2',
            'npwp'               => '09.123.456.7-891.000',
            'nama_bank'          => 'BCA',
            'nomor_rekening'     => '1234567890',
            'atas_nama_rekening' => 'Budi Santoso',
            'ikut_bpjs_kesehatan'       => true,
            'no_bpjs_kesehatan'         => '0001234567890',
            'ikut_bpjs_ketenagakerjaan' => true,
            'no_bpjs_ketenagakerjaan'   => '1101234567890',
            'kontak_darurat_nama'     => 'Siti Aminah',
            'kontak_darurat_telepon'  => '081234567890',
            'kontak_darurat_hubungan' => 'Istri',
            'pendidikan_terakhir'     => 'S1',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nik_ktp', '3171012345678901')
            ->assertJsonPath('data.status_ptkp', 'K/2')
            ->assertJsonPath('data.jumlah_tanggungan', 2)
            ->assertJsonPath('data.npwp', '09.123.456.7-891.000')
            ->assertJsonPath('data.ikut_bpjs_kesehatan', true)
            ->assertJsonPath('data.no_bpjs_ketenagakerjaan', '1101234567890')
            ->assertJsonPath('data.kontak_darurat_nama', 'Siti Aminah');
    }

    public function test_menolak_status_ptkp_tidak_valid(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/karyawan', [
            'nik'           => 'NIK-HR-002',
            'nama_karyawan' => 'Coba PTKP',
            'status_ptkp'   => 'K/9',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['status_ptkp']);
    }

    public function test_ubah_jabatan_tercatat_di_riwayat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-MUT-01', 'Andi Mutasi');

        $idJabatanA = (string) Str::uuid();
        $idJabatanB = (string) Str::uuid();
        DB::table('jabatan')->insert([
            ['id_jabatan' => $idJabatanA, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_jabatan' => 'JB-A', 'nama_jabatan' => 'Staff Operasional', 'dibuat_pada' => now()],
            ['id_jabatan' => $idJabatanB, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_jabatan' => 'JB-B', 'nama_jabatan' => 'Supervisor Operasional', 'dibuat_pada' => now()],
        ]);

        // set jabatan pertama (null -> A), lalu promosi (A -> B)
        $this->putJson("/api/karyawan/{$karyawan->id_karyawan}", ['id_jabatan' => $idJabatanA])->assertStatus(200);
        $this->travel(2)->seconds();
        $this->putJson("/api/karyawan/{$karyawan->id_karyawan}", ['id_jabatan' => $idJabatanB])->assertStatus(200);
        // update tanpa ganti jabatan tidak menambah riwayat
        $this->putJson("/api/karyawan/{$karyawan->id_karyawan}", ['telepon' => '0811111111', 'id_jabatan' => $idJabatanB])->assertStatus(200);

        $res = $this->getJson("/api/karyawan/{$karyawan->id_karyawan}/riwayat-jabatan");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Supervisor Operasional', $data[0]['jabatan_baru']);
        $this->assertSame('Staff Operasional', $data[0]['jabatan_lama']);
        $this->assertNull($data[1]['jabatan_lama']);
        $this->assertSame('Staff Operasional', $data[1]['jabatan_baru']);
    }

    public function test_menolak_nik_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DUP');

        $res = $this->postJson('/api/karyawan', [
            'nik'                => 'NIK-DUP',
            'nama_karyawan'      => 'Duplikat',
            'status_kepegawaian' => 'tetap',
        ]);

        $res->assertStatus(422);
    }

    public function test_list_karyawan_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-A', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeKaryawan($idLain, 'NIK-B', 'Milik Lain');

        $res = $this->getJson('/api/karyawan');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_karyawan']);
    }

    public function test_show_karyawan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-SHOW');

        $res = $this->getJson("/api/karyawan/{$item->id_karyawan}");

        $res->assertStatus(200)->assertJsonPath('data.id_karyawan', $item->id_karyawan);
    }

    public function test_show_karyawan_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->getJson('/api/karyawan/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_karyawan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-UPD');

        $res = $this->putJson("/api/karyawan/{$item->id_karyawan}", [
            'nama_karyawan' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_karyawan', 'Nama Diperbarui');
        $this->assertDatabaseHas('karyawan', ['id_karyawan' => $item->id_karyawan, 'nama_karyawan' => 'Nama Diperbarui']);
    }

    public function test_hapus_karyawan_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DEL');

        $res = $this->deleteJson("/api/karyawan/{$item->id_karyawan}");
        $res->assertStatus(200);

        $row = DB::table('karyawan')->where('id_karyawan', $item->id_karyawan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
