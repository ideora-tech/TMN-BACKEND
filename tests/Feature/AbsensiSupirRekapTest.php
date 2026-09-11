<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbsensiSupirRekapTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';
    private const MIGRASI_SALIN = 'migrations/2026_09_11_100001_salin_absensi_supir_ke_absensi.php';

    private function makeKaryawan(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'SPR-' . Str::random(8), 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => 0, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupir(?string $idKaryawan): object
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

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_pengguna'   => $pengguna->id_pengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'nama'          => 'Supir Rekap',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        DB::table('menu')->insertOrIgnore([
            'id_menu' => self::ID_MENU_TRIP, 'nama_menu' => 'Trip Monitor', 'path' => '/trip', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin' => (string) Str::uuid(), 'kode_peran' => 'SUPIR', 'id_menu' => self::ID_MENU_TRIP,
                'aksi' => $aksi, 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function absenSebagai(object $supir, array $data): void
    {
        Sanctum::actingAs($supir->pengguna, ['*']);
        $this->postJson('/api/absensi-supir', $data)->assertStatus(201);
    }

    private function absensiKaryawanHariIni(string $idKaryawan): array
    {
        return DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->whereDate('tanggal', now()->toDateString())
            ->get()
            ->all();
    }

    public function test_absen_hadir_dari_aplikasi_supir_masuk_rekap_karyawan_tertaut(): void
    {
        $idKaryawan = $this->makeKaryawan('Ahmad Supir');
        $supir = $this->makeSupir($idKaryawan);

        $this->absenSebagai($supir, ['status' => 'hadir']);

        $baris = $this->absensiKaryawanHariIni($idKaryawan);
        $this->assertCount(1, $baris);
        $this->assertSame('hadir', $baris[0]->status);
        $this->assertNotNull($baris[0]->jam_masuk);

        $this->actingAsRole('SUPERADMIN');
        $rekap = collect($this->getJson('/api/absensi/rekap?bulan=' . now()->format('Y-m'))->json('data'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(1, $rekap['hadir']);

        $harian = collect($this->getJson('/api/absensi/harian?tanggal=' . now()->toDateString())->json('data'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame('hadir', $harian['status']);
    }

    public function test_berhalangan_tercatat_izin_dan_absen_ulang_menimpa_tanpa_duplikat(): void
    {
        $idKaryawan = $this->makeKaryawan('Ahmad Supir');
        $supir = $this->makeSupir($idKaryawan);

        $this->absenSebagai($supir, ['status' => 'berhalangan', 'keterangan' => 'Ban pecah di tol']);

        $baris = $this->absensiKaryawanHariIni($idKaryawan);
        $this->assertCount(1, $baris);
        $this->assertSame('izin', $baris[0]->status);
        $this->assertSame('Ban pecah di tol', $baris[0]->keterangan);
        $this->assertNull($baris[0]->jam_masuk);

        $this->absenSebagai($supir, ['status' => 'hadir']);

        $baris = $this->absensiKaryawanHariIni($idKaryawan);
        $this->assertCount(1, $baris);
        $this->assertSame('hadir', $baris[0]->status);
    }

    public function test_supir_tanpa_karyawan_tertaut_tetap_bisa_absen(): void
    {
        $supir = $this->makeSupir(null);

        $this->absenSebagai($supir, ['status' => 'hadir']);

        $this->assertSame(0, DB::table('absensi')->count());
        $this->assertSame(1, DB::table('absensi_supir')->where('id_supir', $supir->id_supir)->count());
    }

    public function test_absen_supir_tidak_menimpa_hari_cuti(): void
    {
        $idKaryawan = $this->makeKaryawan('Ahmad Supir');
        $supir = $this->makeSupir($idKaryawan);

        $idJenis = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Tahunan', 'mengurangi_saldo' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => now()->toDateString(), 'tanggal_selesai' => now()->toDateString(),
            'jumlah_hari' => 1, 'status' => 'disetujui', 'dibuat_pada' => now(),
        ]);

        $this->absenSebagai($supir, ['status' => 'hadir']);

        $this->assertCount(0, $this->absensiKaryawanHariIni($idKaryawan));
    }

    public function test_migrasi_menyalin_absen_supir_lama_ke_absensi_tanpa_menimpa_dan_idempoten(): void
    {
        $idKaryawan = $this->makeKaryawan('Ahmad Supir');
        $supir = $this->makeSupir($idKaryawan);
        $supirTanpaKaryawan = $this->makeSupir(null);

        $salin = fn (string $idSupir, string $tanggal, string $status, ?string $keterangan = null) => DB::table('absensi_supir')->insert([
            'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_supir' => $idSupir,
            'tanggal' => $tanggal, 'status' => $status, 'keterangan' => $keterangan, 'dibuat_pada' => $tanggal . ' 06:45:00',
        ]);
        $salin($supir->id_supir, '2026-09-01', 'hadir');
        $salin($supir->id_supir, '2026-09-02', 'berhalangan', 'Mogok');
        $salin($supir->id_supir, '2026-09-03', 'hadir');
        $salin($supirTanpaKaryawan->id_supir, '2026-09-01', 'hadir');

        DB::table('absensi')->insert([
            'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'tanggal' => '2026-09-03', 'status' => 'sakit', 'dibuat_pada' => now(),
        ]);

        $migrasi = require database_path(self::MIGRASI_SALIN);
        $migrasi->up();
        $migrasi->up();

        $hasil = DB::table('absensi')->where('id_karyawan', $idKaryawan)->orderBy('tanggal')->get();
        $this->assertCount(3, $hasil);
        $this->assertSame(['hadir', 'izin', 'sakit'], $hasil->pluck('status')->all());
        $this->assertSame('06:45:00', $hasil[0]->jam_masuk);
        $this->assertSame('Mogok', $hasil[1]->keterangan);
        $this->assertSame(3, DB::table('absensi')->count());

        $migrasi->down();
        $this->assertSame(['sakit'], DB::table('absensi')->pluck('status')->all());
    }
}
