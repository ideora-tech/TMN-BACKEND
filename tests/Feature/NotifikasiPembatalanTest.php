<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotifikasiPembatalanTest extends TestCase
{
    use RefreshDatabase;

    private function beriIzin(string $kodePeran, string $path, string $aksi): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function buatPengguna(string $kodePeran, ?string $idKaryawan = null): Pengguna
    {
        $this->ensurePerusahaan();
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'id_karyawan' => $idKaryawan, 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function penerima(string $kodePeran, string $path, string $aksi): Pengguna
    {
        $this->beriIzin($kodePeran, $path, $aksi);
        return $this->buatPengguna($kodePeran);
    }

    private function makeKlien(): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'PT Maju Bersama', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKaryawan(string $nama): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-' . Str::random(8), 'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisCuti(): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Tahunan', 'mengurangi_saldo' => 1, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyek(string $status): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->makeKlien(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Distribusi', 'status' => $status,
        ]);
    }

    private function makeFaktur(string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->makeKlien(),
            'nomor_faktur' => 'INV-' . Str::random(6), 'total' => 1500000, 'status' => $status,
            'tanggal_faktur' => '2026-10-01', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function notif(string $tipe, string $referensiId): Collection
    {
        return DB::table('notifikasi')->where('tipe', $tipe)->where('referensi_id', $referensiId)->get();
    }

    public function test_proyek_aktif_dibatalkan_memberi_notifikasi_ke_operasional_kecuali_pembatal(): void
    {
        $operasional = $this->penerima('OPS_TEST', '/penugasan', 'tambah');
        $pembatal = $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('aktif');

        $this->patchJson("/api/proyek/{$proyek->id_proyek}/status", ['status' => 'batal'])->assertOk();

        $notif = $this->notif('proyek_dibatalkan', $proyek->id_proyek);
        $this->assertSame(1, $notif->where('id_pengguna', $operasional->id_pengguna)->count());
        $this->assertSame('/penugasan', $notif->firstWhere('id_pengguna', $operasional->id_pengguna)->link);
        $this->assertSame(0, $notif->where('id_pengguna', $pembatal->id_pengguna)->count());
    }

    public function test_proyek_draft_dibatalkan_tidak_memberi_notifikasi(): void
    {
        $this->penerima('OPS_TEST', '/penugasan', 'tambah');
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('draft');

        $this->patchJson("/api/proyek/{$proyek->id_proyek}/status", ['status' => 'batal'])->assertOk();

        $this->assertCount(0, $this->notif('proyek_dibatalkan', $proyek->id_proyek));
    }

    public function test_invoice_terkirim_dibatalkan_memberi_notifikasi_ke_pemilik_izin_invoice(): void
    {
        $pemantau = $this->penerima('PEMANTAU_TEST', '/faktur', 'lihat');
        $pembatal = $this->actingAsRole('SUPERADMIN');
        $idFaktur = $this->makeFaktur('terkirim');

        $this->patchJson("/api/faktur/{$idFaktur}/status", ['status' => 'batal'])->assertOk();

        $notif = $this->notif('faktur_dibatalkan', $idFaktur);
        $milik = $notif->firstWhere('id_pengguna', $pemantau->id_pengguna);
        $this->assertNotNull($milik);
        $this->assertSame("/faktur/{$idFaktur}", $milik->link);
        $this->assertStringContainsString('1.500.000', $milik->isi);
        $this->assertSame(0, $notif->where('id_pengguna', $pembatal->id_pengguna)->count());
    }

    public function test_invoice_draft_dibatalkan_tidak_memberi_notifikasi(): void
    {
        $this->penerima('PEMANTAU_TEST', '/faktur', 'lihat');
        $this->actingAsRole('SUPERADMIN');
        $idFaktur = $this->makeFaktur('draft');

        $this->patchJson("/api/faktur/{$idFaktur}/status", ['status' => 'batal'])->assertOk();

        $this->assertCount(0, $this->notif('faktur_dibatalkan', $idFaktur));
    }

    public function test_perawatan_dibatalkan_memberi_notifikasi_ke_pemilik_izin_perawatan(): void
    {
        $teknisi = $this->penerima('TEKNISI_TEST', '/perawatan-armada', 'lihat');
        $this->actingAsRole('SUPERADMIN');
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 9001 TMN', 'merk' => 'Hino']);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $armada->id_armada, 'tanggal' => '2026-10-20',
            'jenis_perawatan' => 'Ganti Oli', 'biaya' => 250000, 'status' => 'terjadwal', 'dibuat_pada' => now(),
        ]);

        $this->postJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}/batal", ['alasan' => 'Unit dipakai proyek'])->assertOk();

        $milik = $this->notif('perawatan_dibatalkan', $idPerawatan)->firstWhere('id_pengguna', $teknisi->id_pengguna);
        $this->assertNotNull($milik);
        $this->assertSame("/perawatan-armada/{$idPerawatan}", $milik->link);
        $this->assertStringContainsString('B 9001 TMN', $milik->isi);
        $this->assertStringContainsString('Unit dipakai proyek', $milik->isi);
    }

    public function test_karyawan_membatalkan_cutinya_memberi_notifikasi_ke_pengelola_cuti(): void
    {
        $hr = $this->penerima('HR_TEST', '/cuti', 'tambah');
        $idJenis = $this->makeJenisCuti();
        $pemohon = $this->buatPengguna('STAF_TEST', $this->makeKaryawan('Rina Wati'));
        Sanctum::actingAs($pemohon, ['*']);

        $idPengajuan = $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-11', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->postJson("/api/pengajuan-cuti/saya/{$idPengajuan}/batalkan")->assertOk();

        $notif = $this->notif('cuti_dibatalkan', $idPengajuan);
        $milik = $notif->firstWhere('id_pengguna', $hr->id_pengguna);
        $this->assertNotNull($milik);
        $this->assertStringContainsString('Rina Wati', $milik->isi);
        $this->assertSame('/cuti', $milik->link);
        $this->assertSame(0, $notif->where('id_pengguna', $pemohon->id_pengguna)->count());
    }

    public function test_hr_membatalkan_cuti_memberi_notifikasi_ke_akun_karyawannya(): void
    {
        $idKaryawan = $this->makeKaryawan('Rina Wati');
        $akunKaryawan = $this->buatPengguna('STAF_TEST', $idKaryawan);
        $idJenis = $this->makeJenisCuti();
        $this->actingAsRole('SUPERADMIN');

        $idPengajuan = $this->postJson('/api/pengajuan-cuti', [
            'id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-11', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->postJson("/api/pengajuan-cuti/{$idPengajuan}/batalkan")->assertOk();

        $notif = $this->notif('cuti_dibatalkan', $idPengajuan);
        $this->assertCount(1, $notif);
        $this->assertSame($akunKaryawan->id_pengguna, $notif->first()->id_pengguna);
        $this->assertSame('Pengajuan cuti Anda dibatalkan', $notif->first()->judul);
    }

    public function test_batal_finalisasi_payroll_memberi_notifikasi_ke_pemilik_izin_payroll_dan_proses_pembayaran(): void
    {
        $penggaji = $this->penerima('PENGGAJI_TEST', '/payroll', 'lihat');
        $kasir = $this->penerima('KASIR_TEST', '/proses-pembayaran', 'lihat');
        $pembatal = $this->actingAsRole('SUPERADMIN');

        $idPeriode = $this->postJson('/api/payroll/periode', ['bulan' => '2026-09'])->assertStatus(201)->json('data.id_periode');
        DB::table('payroll_periode')->where('id_periode', $idPeriode)->update(['status' => 'final', 'difinalisasi_pada' => now()]);

        $this->postJson("/api/payroll/periode/{$idPeriode}/batal-finalisasi")->assertOk();

        $notif = $this->notif('payroll_batal_finalisasi', $idPeriode);
        $this->assertSame("/payroll/{$idPeriode}", $notif->firstWhere('id_pengguna', $penggaji->id_pengguna)->link);
        $this->assertSame('/proses-pembayaran', $notif->firstWhere('id_pengguna', $kasir->id_pengguna)->link);
        $this->assertSame(0, $notif->where('id_pengguna', $pembatal->id_pengguna)->count());
        $this->assertSame('draft', DB::table('payroll_periode')->where('id_periode', $idPeriode)->value('status'));
    }
}
