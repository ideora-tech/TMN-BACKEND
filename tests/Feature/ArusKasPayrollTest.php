<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasPayrollTest extends TestCase
{
    use RefreshDatabase;

    private function pengaturanPayload(array $override = []): array
    {
        return array_merge([
            'tanggal_mulai_cutoff'       => 1,
            'hari_kerja_per_bulan'       => 25,
            'persen_bpjs_kesehatan'      => 1,
            'persen_bpjs_jht'            => 2,
            'persen_bpjs_jp'             => 1,
            'plafon_gaji_bpjs_kesehatan' => 12000000,
        ], $override);
    }

    private function makeKaryawan(string $nama, string $nik, float $gaji): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPeriode(string $bulan = null): string
    {
        $bulan = $bulan ?? now()->format('Y-m');
        $res = $this->postJson('/api/v1/payroll/periode', ['bulan' => $bulan]);
        $res->assertStatus(201);
        return $res->json('data.id_periode');
    }

    private function siapkanPeriodeDenganSlip(string $bulan, array $gajiList): string
    {
        $this->putJson('/api/v1/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);
        foreach ($gajiList as $i => $gaji) {
            $this->makeKaryawan("Karyawan {$i}", 'NIK-ARP-' . Str::random(6), $gaji);
        }
        $idPeriode = $this->buatPeriode($bulan);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        return $idPeriode;
    }

    private function pengajuanAktifPeriode(string $idPeriode): ?object
    {
        return DB::table('pengajuan_pengeluaran')
            ->where('id_periode', $idPeriode)
            ->whereNull('dihapus_pada')
            ->first();
    }

    private function totalGajiBersih(string $idPeriode): float
    {
        return (float) DB::table('payroll_slip')
            ->where('id_periode', $idPeriode)
            ->whereNull('dihapus_pada')
            ->sum('gaji_bersih');
    }

    public function test_finalisasi_periode_ber_slip_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->siapkanPeriodeDenganSlip('2026-08', [5000000, 6000000]);
        $totalGajiBersih = $this->totalGajiBersih($idPeriode);
        $this->assertGreaterThan(0, $totalGajiBersih);

        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $pengajuan = $this->pengajuanAktifPeriode($idPeriode);
        $this->assertNotNull($pengajuan);
        $this->assertSame('penggajian', $pengajuan->kategori);
        $this->assertEquals($totalGajiBersih, (float) $pengajuan->nominal);
        $this->assertSame($idPeriode, $pengajuan->id_periode);
        $this->assertSame('diajukan', $pengajuan->status);
        $this->assertSame('Seluruh karyawan', $pengajuan->penerima);
        $this->assertNotNull($pengajuan->nomor_pengajuan);
        $this->assertSame(self::PERUSAHAAN_ID, $pengajuan->id_perusahaan);
    }

    public function test_dedup_id_periode_finalisasi_batal_finalisasi_ulang_hanya_satu_pengajuan_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->siapkanPeriodeDenganSlip('2026-08', [5000000]);

        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(200);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('pengajuan_pengeluaran')->where('id_periode', $idPeriode)->whereNull('dihapus_pada')->count()
        );
        $this->assertSame(
            2,
            DB::table('pengajuan_pengeluaran')->where('id_periode', $idPeriode)->count()
        );
    }

    public function test_batal_finalisasi_sebelum_ditransfer_soft_delete_pengajuan_dan_periode_kembali_draft(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->siapkanPeriodeDenganSlip('2026-08', [5000000]);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $idPengajuan = $this->pengajuanAktifPeriode($idPeriode)->id_pengajuan;

        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(200);

        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan]);

        $periode = DB::table('payroll_periode')->where('id_periode', $idPeriode)->first();
        $this->assertSame('draft', $periode->status);
        $this->assertNull($periode->difinalisasi_pada);
        $this->assertNull($periode->difinalisasi_oleh);
    }

    public function test_batal_finalisasi_setelah_ditransfer_dikembalikan_409_dan_periode_tetap_final(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->siapkanPeriodeDenganSlip('2026-08', [5000000]);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $idPengajuan = $this->pengajuanAktifPeriode($idPeriode)->id_pengajuan;

        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/setujui")->assertStatus(200);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => '2026-08-20',
        ])->assertStatus(200);

        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(409);

        $periode = DB::table('payroll_periode')->where('id_periode', $idPeriode)->first();
        $this->assertSame('final', $periode->status);
        $this->assertNotNull($periode->difinalisasi_pada);

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertSame('ditransfer', $pengajuan->status);
        $this->assertNull($pengajuan->dihapus_pada);
    }

    public function test_rekap_payroll_bukan_lagi_sumber_langsung_pengajuan_penggajian_ditransfer_muncul(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->siapkanPeriodeDenganSlip('2026-08', [5000000]);
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $pengajuan = $this->pengajuanAktifPeriode($idPeriode);

        $rekapSebelum = $this->getJson('/api/v1/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $rekapSebelum->assertStatus(200);
        $this->assertCount(0, collect($rekapSebelum->json('data.transaksi'))->where('sumber', 'payroll_periode'));
        $this->assertCount(0, collect($rekapSebelum->json('data.transaksi'))->where('kategori', 'penggajian'));

        $this->patchJson("/api/v1/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/cek")->assertStatus(200);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/setujui")->assertStatus(200);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => '2026-08-20',
        ])->assertStatus(200);

        $rekapSesudah = $this->getJson('/api/v1/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $rekapSesudah->assertStatus(200);

        $this->assertCount(0, collect($rekapSesudah->json('data.transaksi'))->where('sumber', 'payroll_periode'));

        $rows = collect($rekapSesudah->json('data.transaksi'))
            ->where('sumber', 'pengajuan_pengeluaran')
            ->where('kategori', 'penggajian')
            ->values();
        $this->assertCount(1, $rows);
        $this->assertSame('keluar', $rows[0]['arah']);
        $this->assertEquals((float) $pengajuan->nominal, $rows[0]['nominal']);
        $this->assertSame('2026-08-20', $rows[0]['tanggal']);
    }
}
