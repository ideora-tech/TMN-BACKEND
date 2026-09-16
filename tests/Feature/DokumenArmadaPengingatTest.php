<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenArmadaPengingatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        $this->travelTo(now()->setTime(7, 0));
    }

    private function makeDokumen(int $sisaHari, array $extra = [], string $nopol = 'B 9001 TMN'): string
    {
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol, 'merk' => 'Hino']);
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert(array_merge([
            'id_dokumen_armada' => $id,
            'id_armada'         => $armada->id_armada,
            'jenis_dokumen'     => 'STNK',
            'berlaku_sampai'    => now()->addDays($sisaHari)->toDateString(),
            'aktif'             => 1,
            'dibuat_pada'       => now(),
        ], $extra));
        return $id;
    }

    private function notifikasiDokumen(string $idDokumen)
    {
        return NotifikasiModel::where('referensi_tipe', 'dokumen_armada')
            ->where('referensi_id', $idDokumen)
            ->orderBy('dibuat_pada')
            ->get();
    }

    private function notifLama(string $idDokumen, int $hariLalu): void
    {
        DB::table('notifikasi')->insert([
            'id_notifikasi'  => (string) Str::uuid(),
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'judul'          => 'Notifikasi lama',
            'isi'            => 'Notifikasi lama',
            'tipe'           => 'alert_dokumen',
            'referensi_id'   => $idDokumen,
            'referensi_tipe' => 'dokumen_armada',
            'dibaca'         => 0,
            'dibuat_pada'    => now()->subDays($hariLalu),
        ]);
    }

    public function test_pengingat_dikirim_tujuh_hari_sebelum_habis_sekali_saja_dengan_tautan(): void
    {
        $idDokumen = $this->makeDokumen(7);

        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);
        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);

        $notif = $this->notifikasiDokumen($idDokumen);
        $this->assertCount(1, $notif);
        $this->assertStringContainsString('STNK B 9001 TMN', $notif[0]->judul);
        $this->assertStringContainsString('7 hari', $notif[0]->judul);
        $this->assertSame('/dokumen-armada/' . $idDokumen, $notif[0]->link);

        $this->travel(1)->days();
        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);
        $this->assertCount(1, $this->notifikasiDokumen($idDokumen));
    }

    public function test_dokumen_di_luar_tujuh_hari_riwayat_dan_armada_terhapus_tidak_diingatkan(): void
    {
        $jauh = $this->makeDokumen(8, [], 'B 1 A');
        $riwayat = $this->makeDokumen(3, ['aktif' => 0], 'B 2 A');
        $armadaTerhapus = $this->makeDokumen(3, [], 'B 3 A');
        DB::table('armada')->where('nopol', 'B 3 A')->update(['dihapus_pada' => now()]);

        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);

        $this->assertCount(0, $this->notifikasiDokumen($jauh));
        $this->assertCount(0, $this->notifikasiDokumen($riwayat));
        $this->assertCount(0, $this->notifikasiDokumen($armadaTerhapus));
    }

    public function test_notifikasi_lama_sebelum_jendela_tujuh_hari_tidak_menghalangi_pengingat(): void
    {
        $diingatkanLama = $this->makeDokumen(3, [], 'B 1 A');
        $this->notifLama($diingatkanLama, 20);

        $sudahDiingatkan = $this->makeDokumen(3, [], 'B 2 A');
        $this->notifLama($sudahDiingatkan, 2);

        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);

        $this->assertCount(2, $this->notifikasiDokumen($diingatkanLama));
        $this->assertCount(1, $this->notifikasiDokumen($sudahDiingatkan));
    }

    public function test_jumlah_dokumen_segera_habis_untuk_badge_sidebar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeDokumen(0, [], 'B 1 A');
        $this->makeDokumen(7, [], 'B 2 A');
        $this->makeDokumen(8, [], 'B 3 A');
        $this->makeDokumen(-1, [], 'B 4 A');
        $this->makeDokumen(3, ['aktif' => 0], 'B 5 A');
        $this->makeDokumen(2, [], 'B 6 A');
        DB::table('armada')->where('nopol', 'B 6 A')->update(['dihapus_pada' => now()]);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $armadaLain = ArmadaModel::create(['id_perusahaan' => $idPerusahaanLain, 'nopol' => 'D 9 Z', 'merk' => 'Hino']);
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => (string) Str::uuid(), 'id_armada' => $armadaLain->id_armada,
            'jenis_dokumen' => 'KIR', 'berlaku_sampai' => now()->addDays(2)->toDateString(), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->makeDokumen(-40, [], 'B 7 A');
        $this->makeDokumen(-2, ['aktif' => 0], 'B 8 A');

        $this->getJson('/api/dokumen-armada/jumlah-segera-habis')
            ->assertStatus(200)
            ->assertJsonPath('data.jumlah', 4)
            ->assertJsonPath('data.segera', 2)
            ->assertJsonPath('data.habis', 2)
            ->assertJsonPath('data.hari', 7);
    }

    public function test_pengingat_kedua_di_hari_masa_berlaku_habis(): void
    {
        $idDokumen = $this->makeDokumen(0);
        $this->notifLama($idDokumen, 7);

        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);
        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);

        $notif = $this->notifikasiDokumen($idDokumen);
        $this->assertCount(2, $notif);
        $this->assertStringContainsString('hari ini', $notif[1]->judul);
    }
}
