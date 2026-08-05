<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LemburHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loginSebagaiKaryawan(): string
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-LEMBURHOLD-' . Str::random(4), 'nama_karyawan' => 'Staff Lembur Hold', 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $user->id_pengguna)->update(['id_karyawan' => $idKaryawan]);
        Sanctum::actingAs($user->fresh(), ['*']);
        return $idKaryawan;
    }

    private function setPengaturan(): void
    {
        $this->putJson('/api/v1/absensi/pengaturan', [
            'jam_masuk' => '08:00', 'jam_pulang' => '17:00', 'toleransi_terlambat_menit' => 15,
        ])->assertStatus(200);
    }

    public function test_pulang_mandiri_tidak_menghasilkan_lembur(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(200);

        $this->assertSame(1, (int) DB::table('absensi')->where('id_karyawan', $idKaryawan)->value('pulang_mandiri'));

        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->assertStatus(200)->json('data');
        $baris = collect($rekap)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(0, (int) $baris['lembur_menit']);
    }

    public function test_input_admin_menghasilkan_lembur(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => '2026-08-06',
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'hadir', 'jam_masuk' => '08:00', 'jam_pulang' => '20:00']],
        ])->assertStatus(200);

        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->json('data');
        $baris = collect($rekap)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(180, (int) $baris['lembur_menit']);
    }

    public function test_admin_menimpa_baris_mandiri_membuat_lembur_terhitung(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(200);
        Carbon::setTestNow();

        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => '2026-08-06',
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'hadir', 'jam_masuk' => '07:55', 'jam_pulang' => '20:00']],
        ])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('absensi')->where('id_karyawan', $idKaryawan)->value('pulang_mandiri'));
        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->json('data');
        $this->assertSame(180, (int) collect($rekap)->firstWhere('id_karyawan', $idKaryawan)['lembur_menit']);
    }
}
