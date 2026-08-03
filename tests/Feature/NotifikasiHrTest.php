<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifikasiHrTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama = 'Karyawan HR', string $nik = 'NIK-NOTIF-01'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama, ?string $tglKadaluarsaSim, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-'.substr($id, 0, 8), 'jenis_sim' => 'B2',
            'tgl_kadaluarsa_sim' => $tglKadaluarsaSim, 'status' => $status, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKontrak(string $idKaryawan, ?string $tanggalSelesai): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_karyawan')->insert([
            'id_kontrak' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'jenis_kontrak' => 'pkwt', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => $tanggalSelesai, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_tandai_dibaca_notifikasi_yang_punya_link_referensi(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idTrip  = (string) Str::uuid();
        $idNotif = (string) Str::uuid();
        DB::table('notifikasi')->insert([
            'id_notifikasi' => $idNotif, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'judul' => 'Trip belum selesai', 'isi' => 'Trip berjalan lebih dari 24 jam',
            'tipe' => 'trip', 'referensi_id' => $idTrip, 'referensi_tipe' => 'trip',
            'dibaca' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $res = $this->putJson("/api/v1/notifikasi/{$idNotif}/baca");

        $res->assertStatus(200)->assertJsonPath('data.link', "/trip/{$idTrip}");

        $baris = DB::table('notifikasi')->where('id_notifikasi', $idNotif)->first();
        $this->assertSame(1, (int) $baris->dibaca);
        $this->assertNotNull($baris->dibaca_pada);
        $this->assertNotNull($baris->diubah_pada);
    }

    public function test_dokumen_karyawan_kadaluarsa_membuat_notifikasi(): void
    {
        $idKaryawan = $this->makeKaryawan();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => (string) Str::uuid(),
            'id_karyawan' => $idKaryawan, 'jenis_dokumen' => 'Kontrak Kerja',
            'berlaku_sampai' => now()->addDays(10)->toDateString(), 'dibuat_pada' => now(),
        ]);

        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);

        $notif = NotifikasiModel::where('referensi_tipe', 'dokumen_karyawan')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Karyawan HR', $notif->judul);
    }

    public function test_sim_kadaluarsa_dalam_window_membuat_notifikasi(): void
    {
        $this->makeSupir('Supir Segera', now()->addDays(5)->toDateString());
        $this->makeSupir('Supir Nanti', now()->addDays(20)->toDateString());
        $this->makeSupir('Supir Aman', now()->addDays(90)->toDateString());

        $this->artisan('notifikasi:sim-kadaluarsa')->assertExitCode(0);

        $this->assertSame(2, NotifikasiModel::where('referensi_tipe', 'supir')->count());
        $segera = NotifikasiModel::where('referensi_tipe', 'supir')
            ->where('judul', 'like', '%Supir Segera%')->first();
        $this->assertStringStartsWith('[SEGERA]', $segera->judul);
    }

    public function test_sim_sudah_lewat_tetap_dinotifikasi(): void
    {
        $this->makeSupir('Supir Telat', now()->subDays(3)->toDateString());

        $this->artisan('notifikasi:sim-kadaluarsa')->assertExitCode(0);

        $notif = NotifikasiModel::where('referensi_tipe', 'supir')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('sudah kadaluarsa', $notif->judul);
    }

    public function test_sim_supir_nonaktif_tidak_dinotifikasi(): void
    {
        $this->makeSupir('Supir Pensiun', now()->addDays(5)->toDateString(), 'nonaktif');

        $this->artisan('notifikasi:sim-kadaluarsa')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('referensi_tipe', 'supir')->count());
    }

    public function test_sim_dedup_harian(): void
    {
        $this->makeSupir('Supir Dedup', now()->addDays(5)->toDateString());

        $this->artisan('notifikasi:sim-kadaluarsa')->assertExitCode(0);
        $this->artisan('notifikasi:sim-kadaluarsa')->assertExitCode(0);

        $this->assertSame(1, NotifikasiModel::where('referensi_tipe', 'supir')->count());
    }

    public function test_kontrak_berakhir_dalam_window_membuat_notifikasi(): void
    {
        $idKaryawan = $this->makeKaryawan('Budi Kontrak', 'NIK-NOTIF-02');
        $this->makeKontrak($idKaryawan, now()->addDays(14)->toDateString());

        $this->artisan('notifikasi:kontrak-berakhir')->assertExitCode(0);

        $notif = NotifikasiModel::where('referensi_tipe', 'kontrak_karyawan')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Budi Kontrak', $notif->judul);
        $this->assertStringContainsString('PKWT', $notif->judul);
    }

    public function test_kontrak_pkwtt_tanpa_tanggal_selesai_tidak_dinotifikasi(): void
    {
        $idKaryawan = $this->makeKaryawan('Tetap Selamanya', 'NIK-NOTIF-03');
        $this->makeKontrak($idKaryawan, null);

        $this->artisan('notifikasi:kontrak-berakhir')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('referensi_tipe', 'kontrak_karyawan')->count());
    }

    public function test_kontrak_di_luar_window_tidak_dinotifikasi(): void
    {
        $idKaryawan = $this->makeKaryawan('Masih Lama', 'NIK-NOTIF-04');
        $this->makeKontrak($idKaryawan, now()->addDays(60)->toDateString());

        $this->artisan('notifikasi:kontrak-berakhir')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('referensi_tipe', 'kontrak_karyawan')->count());
    }
}
