<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GabungKaryawanDuplikatTest extends TestCase
{
    use RefreshDatabase;

    private const NIK_PANJANG = '3601101408890001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function karyawan(string $nik, string $nama, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => $nik,
            'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function supir(string $idKaryawan, string $nama = 'Dede Supendi'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'nama' => $nama, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function dokumen(string $idKaryawan, string $jenis, string $nomor): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $id, 'id_karyawan' => $idKaryawan, 'jenis_dokumen' => $jenis,
            'nomor' => $nomor, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    /** Pola data Dede Supendi: DRV tipis memegang supir + dokumen, kembarannya lengkap tanpa relasi. */
    private function pasanganDede(): array
    {
        $drv = $this->karyawan('DRV-0006', 'Dede Supendi', ['nik_ktp' => self::NIK_PANJANG, 'telepon' => '085860952477', 'status_kepegawaian' => 'tetap']);
        $supir = $this->supir($drv);
        $dokKtp = $this->dokumen($drv, 'KTP', self::NIK_PANJANG);
        $dokSim = $this->dokumen($drv, 'SIM', '13218905000189');
        $kembar = $this->karyawan(self::NIK_PANJANG, 'Dede Supendi', [
            'nik_ktp' => self::NIK_PANJANG, 'email' => 'bedi09757@gmail.com', 'telepon' => '085860952477',
            'tempat_lahir' => 'Jakarta', 'tanggal_lahir' => '1989-09-05', 'status_pernikahan' => 'menikah',
            'jumlah_tanggungan' => 2, 'status_ptkp' => 'K/2', 'pendidikan_terakhir' => 'SMP',
            'alamat_ktp' => 'Kp Pasir Jati', 'tanggal_masuk' => '2026-01-07', 'gaji_pokok' => 2500000,
            'nama_bank' => 'BNI', 'nomor_rekening' => '2098859157', 'status_kepegawaian' => 'kontrak',
        ]);

        return compact('drv', 'supir', 'dokKtp', 'dokSim', 'kembar');
    }

    public function test_simulasi_tidak_mengubah_data(): void
    {
        $p = $this->pasanganDede();

        $this->artisan('karyawan:gabung-duplikat')
            ->expectsOutputToContain('Simulasi: 1 pasangan')
            ->assertExitCode(0);

        $this->assertSame('DRV-0006', DB::table('karyawan')->where('id_karyawan', $p['drv'])->value('nik'));
        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $p['kembar'])->value('dihapus_pada'));
        $this->assertSame(self::NIK_PANJANG, DB::table('karyawan')->where('id_karyawan', $p['kembar'])->value('nik'));
    }

    public function test_commit_mempertahankan_pemegang_supir_dan_dokumen_lalu_memberi_nik_panjang(): void
    {
        $p = $this->pasanganDede();

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])->assertExitCode(0);

        $kept = DB::table('karyawan')->where('id_karyawan', $p['drv'])->first();
        $this->assertNull($kept->dihapus_pada);
        $this->assertSame(self::NIK_PANJANG, $kept->nik);
        $this->assertSame('tetap', $kept->status_kepegawaian);
        $this->assertSame('bedi09757@gmail.com', $kept->email);
        $this->assertSame('1989-09-05', substr((string) $kept->tanggal_lahir, 0, 10));
        $this->assertSame(2, (int) $kept->jumlah_tanggungan);
        $this->assertEquals(2500000, (float) $kept->gaji_pokok);
        $this->assertSame('2098859157', $kept->nomor_rekening);
        $this->assertSame('BNI', $kept->nama_bank);

        $buang = DB::table('karyawan')->where('id_karyawan', $p['kembar'])->first();
        $this->assertNotNull($buang->dihapus_pada);
        $this->assertStringStartsWith('DUP-', $buang->nik);

        $this->assertSame($p['drv'], DB::table('supir')->where('id_supir', $p['supir'])->value('id_karyawan'));
        $this->assertSame(2, DB::table('dokumen_karyawan')->where('id_karyawan', $p['drv'])->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('karyawan')->whereNull('dihapus_pada')->where('nik', self::NIK_PANJANG)->count());
    }

    public function test_nilai_yang_sudah_ada_di_record_dipertahankan_tidak_ditimpa(): void
    {
        $drv = $this->karyawan('DRV-0001', 'Muhamad Toha', ['nik_ktp' => '3602142712900003', 'email' => 'lama@toha.id', 'gaji_pokok' => 3000000]);
        $this->supir($drv, 'Muhamad Toha');
        $this->karyawan('3602142712900003', 'Muhamad Toha', ['email' => 'baru@toha.id', 'gaji_pokok' => 9999999, 'npwp' => '123']);

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])->assertExitCode(0);

        $kept = DB::table('karyawan')->where('id_karyawan', $drv)->first();
        $this->assertSame('lama@toha.id', $kept->email);
        $this->assertEquals(3000000, (float) $kept->gaji_pokok);
        $this->assertSame('123', $kept->npwp);
    }

    public function test_bila_record_nik_panjang_memegang_supir_cukup_hapus_yang_tidak_lengkap(): void
    {
        $drv = $this->karyawan('DRV-0018', 'RIfki Abdul Gani');
        $panjang = $this->karyawan('3172041911910003', 'Rifki Abdul Gani', ['email' => 'rifki@x.id', 'status_kepegawaian' => 'kontrak']);
        $supir = $this->supir($panjang, 'Rifki Abdul Gani');

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])->assertExitCode(0);

        $this->assertNotNull(DB::table('karyawan')->where('id_karyawan', $drv)->value('dihapus_pada'));
        $kept = DB::table('karyawan')->where('id_karyawan', $panjang)->first();
        $this->assertNull($kept->dihapus_pada);
        $this->assertSame('3172041911910003', $kept->nik);
        $this->assertSame('tetap', $kept->status_kepegawaian);
        $this->assertSame('Rifki Abdul Gani', $kept->nama_karyawan);
        $this->assertSame($panjang, DB::table('supir')->where('id_supir', $supir)->value('id_karyawan'));
    }

    public function test_nik_17_digit_dianggap_tidak_lengkap_dan_diganti_nik_16_digit(): void
    {
        $salah = $this->karyawan('32132000301900006', 'Maryana');
        $this->supir($salah, 'Maryana');
        $benar = $this->karyawan('3213200030190006', 'Maryana', ['email' => 'Amarmiharja@gmail.com']);

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])->assertExitCode(0);

        $kept = DB::table('karyawan')->where('id_karyawan', $salah)->first();
        $this->assertNull($kept->dihapus_pada);
        $this->assertSame('3213200030190006', $kept->nik);
        $this->assertSame('Amarmiharja@gmail.com', $kept->email);
        $this->assertNotNull(DB::table('karyawan')->where('id_karyawan', $benar)->value('dihapus_pada'));
    }

    public function test_kedua_record_memegang_relasi_dilewati(): void
    {
        $drv = $this->karyawan('DRV-0003', 'Martohon Situmorang');
        $this->supir($drv, 'Martohon Situmorang');
        $panjang = $this->karyawan('1205880903003598', 'Martohon Situmorang');
        $this->dokumen($panjang, 'KTP', '1205880903003598');

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])
            ->expectsOutputToContain('Dilewati')
            ->assertExitCode(0);

        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $drv)->value('dihapus_pada'));
        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $panjang)->value('dihapus_pada'));
        $this->assertSame('DRV-0003', DB::table('karyawan')->where('id_karyawan', $drv)->value('nik'));
    }

    public function test_dua_nik_16_digit_dilewati_karena_bisa_jadi_orang_berbeda(): void
    {
        $a = $this->karyawan('3175071903770008', 'Budi Santoso');
        $b = $this->karyawan('3175071903770099', 'Budi Santoso');

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])->assertExitCode(0);

        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $a)->value('dihapus_pada'));
        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $b)->value('dihapus_pada'));
    }

    public function test_hanya_nama_sama_persis_yang_digabung(): void
    {
        $this->karyawan('DRV-0010', 'Haris Himarwanto');
        $this->karyawan('3175071903770010', 'Haris Himar');

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])
            ->expectsOutputToContain('Tidak ada pasangan')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('karyawan')->whereNull('dihapus_pada')->count());
    }

    public function test_filter_nama_membatasi_pasangan_yang_dijalankan(): void
    {
        $a = $this->pasanganDede();
        $drvLain = $this->karyawan('DRV-0099', 'Yunaidi');
        $this->supir($drvLain, 'Yunaidi');
        $panjangLain = $this->karyawan('3175032406930000', 'Yunaidi');

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true, '--nama' => ['dede']])->assertExitCode(0);

        $this->assertNotNull(DB::table('karyawan')->where('id_karyawan', $a['kembar'])->value('dihapus_pada'));
        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $panjangLain)->value('dihapus_pada'));
        $this->assertSame('DRV-0099', DB::table('karyawan')->where('id_karyawan', $drvLain)->value('nik'));
    }

    public function test_record_yang_sudah_dihapus_dan_beda_perusahaan_tidak_ikut(): void
    {
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $this->karyawan('DRV-0005', 'Supriyatna');
        $this->karyawan('3209706000113333', 'Supriyatna', ['id_perusahaan' => $lain]);
        $this->karyawan('3209706000114444', 'Supriyatna', ['dihapus_pada' => now()]);

        $this->artisan('karyawan:gabung-duplikat', ['--commit' => true])
            ->expectsOutputToContain('Tidak ada pasangan')
            ->assertExitCode(0);
    }

    public function test_status_tidak_valid_ditolak(): void
    {
        $this->artisan('karyawan:gabung-duplikat', ['--status' => 'asal'])->assertExitCode(2);
    }
}
