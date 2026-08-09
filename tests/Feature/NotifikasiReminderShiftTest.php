<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifikasiReminderShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->ensurePerusahaan();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSupir(bool $denganAkun = true): array
    {
        $idPengguna = null;
        if ($denganAkun) {
            $pengguna = Pengguna::create([
                'id_pengguna'   => (string) Str::uuid(),
                'id_perusahaan' => self::PERUSAHAAN_ID,
                'kode_peran'    => 'SUPIR',
                'username'      => 'supir_' . Str::random(8),
                'email'         => Str::random(8) . '@test.id',
                'kata_sandi'    => bcrypt('Password123!'),
                'aktif'         => 1,
            ]);
            $idPengguna = $pengguna->id_pengguna;
        }

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Reminder Test',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        return ['id_supir' => $idSupir, 'id_pengguna' => $idPengguna];
    }

    private function makeJadwal(string $idSupir, string $tanggal, string $jamMulai): string
    {
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek'     => $idProyek,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Reminder Test',
            'dibuat_pada'   => now(),
        ]);

        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Pagi',
            'jam_mulai'     => $jamMulai,
            'jam_selesai'   => '17:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => $idJadwal,
            'id_proyek'       => $idProyek,
            'id_shift'        => $idShift,
            'id_supir'        => $idSupir,
            'tanggal'         => $tanggal,
            'dibuat_pada'     => now(),
        ]);

        return $idJadwal;
    }

    public function test_reminder_terkirim_30_menit_sebelum_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 07:30:00'));
        $supir = $this->makeSupir();
        $idJadwal = $this->makeJadwal($supir['id_supir'], '2026-08-12', '08:00:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);

        $notif = NotifikasiModel::where('referensi_id', $idJadwal)->first();
        $this->assertNotNull($notif);
        $this->assertSame('reminder_shift', $notif->tipe);
        $this->assertSame($supir['id_pengguna'], $notif->id_pengguna);
        $this->assertStringContainsString('08:00', $notif->judul);
    }

    public function test_reminder_belum_terkirim_90_menit_sebelum_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 06:30:00'));
        $supir = $this->makeSupir();
        $this->makeJadwal($supir['id_supir'], '2026-08-12', '08:00:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('tipe', 'reminder_shift')->count());
    }

    public function test_reminder_tidak_terkirim_setelah_shift_mulai(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 08:05:00'));
        $supir = $this->makeSupir();
        $this->makeJadwal($supir['id_supir'], '2026-08-12', '08:00:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('tipe', 'reminder_shift')->count());
    }

    public function test_jalan_dua_kali_tidak_dobel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 07:30:00'));
        $supir = $this->makeSupir();
        $idJadwal = $this->makeJadwal($supir['id_supir'], '2026-08-12', '08:00:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);
        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);

        $this->assertSame(1, NotifikasiModel::where('referensi_id', $idJadwal)->count());
    }

    public function test_shift_lewat_tengah_malam_terkirim_dan_tidak_dobel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 23:30:00'));
        $supir = $this->makeSupir();
        $idJadwal = $this->makeJadwal($supir['id_supir'], '2026-08-13', '00:15:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);
        $this->assertSame(1, NotifikasiModel::where('referensi_id', $idJadwal)->count());

        Carbon::setTestNow(Carbon::parse('2026-08-13 00:05:00'));
        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);
        $this->assertSame(1, NotifikasiModel::where('referensi_id', $idJadwal)->count());
    }

    public function test_supir_tanpa_akun_dilewati(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 07:30:00'));
        $supir = $this->makeSupir(denganAkun: false);
        $this->makeJadwal($supir['id_supir'], '2026-08-12', '08:00:00');

        $this->artisan('notifikasi:reminder-shift')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::count());
    }
}
