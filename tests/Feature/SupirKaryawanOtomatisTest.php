<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirKaryawanOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function jabatan(string $nama, bool $isSupir, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_jabatan' => 'J-' . Str::random(5), 'nama_jabatan' => $nama,
            'is_supir' => $isSupir ? 1 : 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function karyawan(string $nik, ?string $idPerusahaan = null, bool $dihapus = false): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID, 'nik' => $nik,
            'nama_karyawan' => 'Karyawan Lama', 'aktif' => 1, 'dibuat_pada' => now(),
            'dihapus_pada' => $dihapus ? now() : null,
        ]);

        return $id;
    }

    public function test_tambah_supir_tanpa_karyawan_membuat_karyawan_tertaut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Staf Gudang', false);
        $idJabatanSupir = $this->jabatan('Pengemudi', true);

        $data = $this->postJson('/api/supir', ['nama' => 'Dedi Kurniawan', 'telepon' => '0812999', 'jenis_sim' => 'B2'])
            ->assertStatus(201)->json('data');

        $this->assertNotNull($data['id_karyawan']);
        $karyawan = DB::table('karyawan')->where('id_karyawan', $data['id_karyawan'])->first();
        $this->assertSame('DRV-0001', $karyawan->nik);
        $this->assertSame('Dedi Kurniawan', $karyawan->nama_karyawan);
        $this->assertSame('0812999', $karyawan->telepon);
        $this->assertSame($idJabatanSupir, $karyawan->id_jabatan);
        $this->assertSame(1, (int) $karyawan->aktif);
        $this->assertSame(self::PERUSAHAAN_ID, $karyawan->id_perusahaan);
        $this->assertSame(1, DB::table('supir')->whereNull('dihapus_pada')->where('id_karyawan', $data['id_karyawan'])->count());
    }

    public function test_tambah_supir_dengan_karyawan_terpilih_tidak_membuat_karyawan_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->karyawan('K-001');

        $this->postJson('/api/supir', ['nama' => 'Karyawan Lama', 'id_karyawan' => $idKaryawan])
            ->assertStatus(201)->assertJsonPath('data.id_karyawan', $idKaryawan);

        $this->assertSame(1, DB::table('karyawan')->count());
    }

    public function test_nik_otomatis_melewati_nik_yang_sudah_terpakai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->karyawan('DRV-0001', (string) Str::uuid());
        $this->karyawan('DRV-0002', null, true);

        $idKaryawan = $this->postJson('/api/supir', ['nama' => 'Eko'])->assertStatus(201)->json('data.id_karyawan');

        $this->assertSame('DRV-0003', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik'));
    }

    public function test_jabatan_karyawan_otomatis_ikut_nama_jabatan_atau_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Supir', true, (string) Str::uuid());

        $tanpaJabatan = $this->postJson('/api/supir', ['nama' => 'Fajar'])->assertStatus(201)->json('data.id_karyawan');
        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $tanpaJabatan)->value('id_jabatan'));

        $idSupirTrailer = $this->jabatan('Supir Trailer', false);
        $denganJabatan = $this->postJson('/api/supir', ['nama' => 'Gilang'])->assertStatus(201)->json('data.id_karyawan');
        $this->assertSame($idSupirTrailer, DB::table('karyawan')->where('id_karyawan', $denganJabatan)->value('id_jabatan'));
    }

    public function test_supir_nonaktif_membuat_karyawan_nonaktif(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idKaryawan = $this->postJson('/api/supir', ['nama' => 'Hadi', 'status' => 'nonaktif'])->assertStatus(201)->json('data.id_karyawan');

        $this->assertSame(0, (int) DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('aktif'));
    }

    private function supirLama(string $nama, ?string $idKaryawan = null, string $status = 'aktif', bool $dihapus = false): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'nama' => $nama, 'telepon' => '0813' . random_int(1000, 9999), 'status' => $status,
            'dibuat_pada' => now()->addSeconds(DB::table('supir')->count()), 'dihapus_pada' => $dihapus ? now() : null,
        ]);

        return $id;
    }

    private function dokumenSupir(string $idSupir, string $jenis, ?string $nomor = null): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $id, 'id_karyawan' => null, 'id_supir' => $idSupir,
            'jenis_dokumen' => $jenis, 'nomor' => $nomor, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    public function test_migrasi_membuatkan_karyawan_untuk_supir_lama_tanpa_karyawan(): void
    {
        $migrasi = require database_path('migrations/2026_10_26_100001_karyawan_untuk_supir_tanpa_karyawan.php');
        $idJabatan = $this->jabatan('Pengemudi', true);
        $idKaryawanAda = $this->karyawan('K-LAMA');

        $tertaut  = $this->supirLama('Sudah Tertaut', $idKaryawanAda);
        $andi     = $this->supirLama('Andi');
        $budi     = $this->supirLama('Budi', null, 'nonaktif');
        $terhapus = $this->supirLama('Terhapus', null, 'aktif', true);
        $ktp      = $this->dokumenSupir($andi, 'KTP', '3201010101010001');
        $sim      = $this->dokumenSupir($andi, 'SIM', 'SIM-01');

        $migrasi->up();
        $migrasi->up();

        $this->assertSame($idKaryawanAda, DB::table('supir')->where('id_supir', $tertaut)->value('id_karyawan'));
        $this->assertNull(DB::table('supir')->where('id_supir', $terhapus)->value('id_karyawan'));
        $this->assertSame(3, DB::table('karyawan')->count());

        $supirAndi = DB::table('supir')->where('id_supir', $andi)->first();
        $karyawanAndi = DB::table('karyawan')->where('id_karyawan', $supirAndi->id_karyawan)->first();
        $this->assertSame('DRV-0001', $karyawanAndi->nik);
        $this->assertSame('Andi', $karyawanAndi->nama_karyawan);
        $this->assertSame($supirAndi->telepon, $karyawanAndi->telepon);
        $this->assertSame($idJabatan, $karyawanAndi->id_jabatan);
        $this->assertSame(1, (int) $karyawanAndi->aktif);
        $this->assertSame('3201010101010001', $karyawanAndi->nik_ktp);
        $this->assertSame(2, DB::table('dokumen_karyawan')->whereIn('id_dokumen_karyawan', [$ktp, $sim])->where('id_karyawan', $karyawanAndi->id_karyawan)->count());

        $karyawanBudi = DB::table('karyawan')->where('id_karyawan', DB::table('supir')->where('id_supir', $budi)->value('id_karyawan'))->first();
        $this->assertSame('DRV-0002', $karyawanBudi->nik);
        $this->assertSame(0, (int) $karyawanBudi->aktif);
        $this->assertNull($karyawanBudi->nik_ktp);

        $this->actingAsRole('SUPERADMIN');
        $idKaryawanBaru = $this->postJson('/api/supir', ['nama' => 'Cahya'])->assertStatus(201)->json('data.id_karyawan');
        $this->assertSame('DRV-0003', DB::table('karyawan')->where('id_karyawan', $idKaryawanBaru)->value('nik'));
    }

    public function test_gagal_validasi_tidak_meninggalkan_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/supir', ['nama' => 'Indra', 'id_pengguna' => (string) Str::uuid()])->assertStatus(404);

        $this->assertSame(0, DB::table('karyawan')->count());
    }
}
