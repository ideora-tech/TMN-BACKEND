<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifikasiServisJatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $idPerusahaan = self::PERUSAHAAN_ID, string $nopol = 'B 1234 XY'): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $id, 'id_perusahaan' => $idPerusahaan, 'nopol' => $nopol,
            'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerawatan(string $idArmada, string $tanggal, ?string $jadwal, string $jenis = 'Ganti Oli'): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => $jenis, 'biaya' => 100000, 'status' => 'selesai',
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_membuat_notifikasi_untuk_servis_dalam_30_hari(): void
    {
        $armada = $this->makeArmada();
        $this->makePerawatan($armada, '2026-01-01', now()->addDays(20)->toDateString());

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $this->assertSame(1, NotifikasiModel::where('tipe', 'alert_servis')->count());
        $notif = NotifikasiModel::where('tipe', 'alert_servis')->first();
        $this->assertStringNotContainsString('[SEGERA]', $notif->judul);
    }

    public function test_prefix_segera_untuk_jatuh_tempo_7_hari_atau_kurang(): void
    {
        $armada = $this->makeArmada();
        $this->makePerawatan($armada, '2026-01-01', now()->addDays(5)->toDateString());

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $notif = NotifikasiModel::where('tipe', 'alert_servis')->first();
        $this->assertStringStartsWith('[SEGERA]', $notif->judul);
    }

    public function test_servis_di_luar_30_hari_tidak_membuat_notifikasi(): void
    {
        $armada = $this->makeArmada();
        $this->makePerawatan($armada, '2026-01-01', now()->addDays(45)->toDateString());

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('tipe', 'alert_servis')->count());
    }

    public function test_hanya_servis_terbaru_per_armada_yang_dipertimbangkan(): void
    {
        $armada = $this->makeArmada();
        // servis lama: jadwal dalam window (harus DIABAIKAN karena bukan terbaru)
        $this->makePerawatan($armada, '2026-01-01', now()->addDays(10)->toDateString());
        // servis terbaru: jadwal di luar window
        $this->makePerawatan($armada, '2026-06-01', now()->addDays(90)->toDateString());

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('tipe', 'alert_servis')->count());
    }

    public function test_dedup_harian_tidak_membuat_notifikasi_dobel(): void
    {
        $armada = $this->makeArmada();
        $this->makePerawatan($armada, '2026-01-01', now()->addDays(10)->toDateString());

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);
        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $this->assertSame(1, NotifikasiModel::where('tipe', 'alert_servis')->count());
    }

    public function test_servis_tanpa_jadwal_tidak_membuat_notifikasi(): void
    {
        $armada = $this->makeArmada();
        $this->makePerawatan($armada, '2026-01-01', null);

        $this->artisan('notifikasi:servis-jatuh-tempo')->assertExitCode(0);

        $this->assertSame(0, NotifikasiModel::where('tipe', 'alert_servis')->count());
    }
}
