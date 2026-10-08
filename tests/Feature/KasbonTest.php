<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KasbonTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama = 'Rudi Hartono', array $extra = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-KSB-' . Str::random(6), 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => 5000000, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function payload(string $idKaryawan, array $override = []): array
    {
        return array_merge([
            'id_karyawan'         => $idKaryawan,
            'tanggal'             => now()->toDateString(),
            'nominal'             => 1500000,
            'cicilan_per_periode' => 500000,
            'mulai_potong'        => now()->format('Y-m'),
            'keperluan'           => 'Biaya sekolah anak',
            'nama_bank'           => 'BCA',
            'nomor_rekening'      => '2880705027',
        ], $override);
    }

    private function buatApprover(): Pengguna
    {
        $this->ensurePerusahaan();

        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPERADMIN',
            'username'      => 'approver_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
    }

    private function aturApproval(string $kode, string $idApprover, int $aktif = 1): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => ucfirst($kode), 'mode_resolusi' => 'pinned',
            'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    private function buatKasbon(string $idKaryawan, array $override = []): string
    {
        $res = $this->postJson('/api/kasbon', $this->payload($idKaryawan, $override));
        $res->assertStatus(201);

        return (string) $res->json('data.id_kasbon');
    }

    private function buatKasbonLama(string $idKaryawan, array $override = []): string
    {
        return $this->buatKasbon($idKaryawan, array_merge(['saldo_awal' => true], $override));
    }

    private function idPengajuan(string $idKasbon): string
    {
        return (string) DB::table('kasbon')->where('id_kasbon', $idKasbon)->value('id_pengajuan');
    }

    private function cairkan(string $idKasbon, string $tanggalTransfer): void
    {
        $idPengajuan = $this->idPengajuan($idKasbon);

        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggalTransfer,
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);
    }

    public function test_buat_kasbon_membuat_pengajuan_menunggu_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/kasbon', $this->payload($idKaryawan));

        $res->assertStatus(201)
            ->assertJsonPath('data.nama_karyawan', 'Rudi Hartono')
            ->assertJsonPath('data.nominal', 1500000)
            ->assertJsonPath('data.cicilan_per_periode', 500000)
            ->assertJsonPath('data.mulai_potong', now()->format('Y-m'))
            ->assertJsonPath('data.terbayar', 0)
            ->assertJsonPath('data.sisa', 1500000)
            ->assertJsonPath('data.sisa_potongan', 3)
            ->assertJsonPath('data.saldo_awal', false)
            ->assertJsonPath('data.status', 'menunggu_approval')
            ->assertJsonPath('data.status_pengajuan', 'menunggu_approval')
            ->assertJsonPath('data.bisa_diubah', true)
            ->assertJsonPath('data.bisa_ubah_cicilan', false)
            ->assertJsonPath('data.bisa_catat_pelunasan', false);

        $nomor = (string) $res->json('data.nomor_kasbon');
        $this->assertMatchesRegularExpression('/^KSB-\d{6}-0001$/', $nomor);

        $idPengajuan = $res->json('data.id_pengajuan');
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan,
            'id_kasbon'    => $res->json('data.id_kasbon'),
            'kategori'     => 'kasbon',
            'nominal'      => 1500000,
            'penerima'     => 'Rudi Hartono',
            'keterangan'   => "Kasbon {$nomor} — Biaya sekolah anak",
            'status'       => 'menunggu_approval',
        ]);
        $this->assertSame(1, DB::table('approval_keputusan as ak')
            ->join('approval_pengajuan as ap', 'ap.id_approval', '=', 'ak.id_approval')
            ->where('ap.id_referensi', $idPengajuan)
            ->where('ak.id_pengguna', $approver->id_pengguna)
            ->count());
    }

    public function test_tanpa_konfigurasi_approval_kasbon_langsung_menunggu_pencairan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/kasbon', $this->payload($idKaryawan))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'menunggu_pencairan')
            ->assertJsonPath('data.status_pengajuan', 'disetujui')
            ->assertJsonPath('data.bisa_diubah', false)
            ->assertJsonPath('data.bisa_ubah_cicilan', true)
            ->assertJsonPath('data.bisa_catat_pelunasan', false);
    }

    public function test_approval_kasbon_nonaktif_dilewati_dan_tanpa_jenis_kasbon_memakai_pengajuan_pengeluaran(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('pengajuan_pengeluaran', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/kasbon', $this->payload($idKaryawan))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'menunggu_approval');

        $this->aturApproval('kasbon', (string) $approver->id_pengguna, 0);

        $this->postJson('/api/kasbon', $this->payload($idKaryawan))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'menunggu_pencairan');
    }

    public function test_nomor_kasbon_berurutan_dan_boleh_lebih_dari_satu_per_karyawan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $pertama = $this->postJson('/api/kasbon', $this->payload($idKaryawan))->json('data.nomor_kasbon');
        $kedua   = $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['nominal' => 750000, 'cicilan_per_periode' => 250000]))
            ->assertStatus(201)->json('data.nomor_kasbon');

        $this->assertStringEndsWith('-0001', $pertama);
        $this->assertStringEndsWith('-0002', $kedua);
        $this->assertSame(2, DB::table('kasbon')->where('id_karyawan', $idKaryawan)->count());
    }

    public function test_validasi_isian_kasbon(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/kasbon', [])->assertStatus(422)->assertJsonValidationErrors([
            'id_karyawan', 'tanggal', 'nominal', 'cicilan_per_periode', 'mulai_potong', 'keperluan',
        ]);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['cicilan_per_periode' => 2000000]))
            ->assertStatus(422)->assertJsonValidationErrors(['cicilan_per_periode']);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['mulai_potong' => now()->subMonthNoOverflow()->format('Y-m')]))
            ->assertStatus(422)->assertJsonValidationErrors(['mulai_potong']);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['tanggal' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['nomor_rekening' => 'ABC-123']))
            ->assertStatus(422)->assertJsonValidationErrors(['nomor_rekening']);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan, ['nominal' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['nominal']);

        $this->assertSame(0, DB::table('kasbon')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_karyawan_perusahaan_lain_atau_nonaktif_ditolak(): void
    {
        $this->actingAsRole('KEUANGAN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $karyawanLain     = $this->makeKaryawan('Orang Lain', ['id_perusahaan' => $idPerusahaanLain]);
        $karyawanNonaktif = $this->makeKaryawan('Sudah Keluar', ['aktif' => 0]);

        $this->postJson('/api/kasbon', $this->payload($karyawanLain))
            ->assertStatus(422)->assertJsonValidationErrors(['id_karyawan']);
        $this->postJson('/api/kasbon', $this->payload($karyawanNonaktif))
            ->assertStatus(422)->assertJsonValidationErrors(['id_karyawan']);

        $this->assertSame(0, DB::table('kasbon')->count());
    }

    public function test_kasbon_lama_langsung_berjalan_tanpa_pengajuan(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/kasbon', $this->payload($idKaryawan, [
            'saldo_awal' => true, 'tanggal' => '2026-03-10', 'mulai_potong' => now()->format('Y-m'),
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.saldo_awal', true)
            ->assertJsonPath('data.status', 'berjalan')
            ->assertJsonPath('data.id_pengajuan', null)
            ->assertJsonPath('data.bisa_diubah', true)
            ->assertJsonPath('data.bisa_ubah_cicilan', true)
            ->assertJsonPath('data.bisa_catat_pelunasan', true);
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());

        $this->getJson('/api/kasbon/' . $res->json('data.id_kasbon') . '/riwayat')
            ->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_ubah_saat_menunggu_approval_memperbarui_pengajuan_dan_mengulang_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $idLain     = $this->makeKaryawan('Sari Dewi');
        $id         = $this->buatKasbon($idKaryawan);
        $idPengajuan = $this->idPengajuan($id);

        $res = $this->putJson("/api/kasbon/{$id}", $this->payload($idLain, [
            'nominal' => 2000000, 'cicilan_per_periode' => 400000, 'keperluan' => 'Renovasi rumah',
        ]));

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_karyawan', 'Sari Dewi')
            ->assertJsonPath('data.nominal', 2000000)
            ->assertJsonPath('data.sisa_potongan', 5)
            ->assertJsonPath('data.status', 'menunggu_approval');
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan, 'nominal' => 2000000, 'penerima' => 'Sari Dewi', 'status' => 'menunggu_approval',
        ]);
        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
    }

    public function test_ditolak_bisa_diperbaiki_lalu_diajukan_ulang(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $pembuat = $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $id = $this->buatKasbon($idKaryawan);
        $idPengajuan = $this->idPengajuan($id);

        Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'tolak', 'catatan' => 'Nominal terlalu besar'])
            ->assertStatus(200);

        Sanctum::actingAs($pembuat, ['*']);
        $this->getJson("/api/kasbon/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Nominal terlalu besar')
            ->assertJsonPath('data.bisa_diubah', true);

        $this->putJson("/api/kasbon/{$id}", $this->payload($idKaryawan, ['nominal' => 1000000]))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval')
            ->assertJsonPath('data.alasan_ditolak', null);

        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
    }

    public function test_setelah_disetujui_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $id = $this->buatKasbon($idKaryawan);

        $this->putJson("/api/kasbon/{$id}", $this->payload($idKaryawan))->assertStatus(409);
        $this->deleteJson("/api/kasbon/{$id}")->assertStatus(409);

        $this->assertNull(DB::table('kasbon')->where('id_kasbon', $id)->value('dihapus_pada'));
    }

    public function test_hapus_saat_menunggu_approval_menghapus_kasbon_pengajuan_dan_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbon($this->makeKaryawan());
        $idPengajuan = $this->idPengajuan($id);

        $this->deleteJson("/api/kasbon/{$id}")->assertStatus(200);

        $this->assertNotNull(DB::table('kasbon')->where('id_kasbon', $id)->value('dihapus_pada'));
        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));
        $this->assertSame(0, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
        $this->getJson("/api/kasbon/{$id}")->assertStatus(404);
    }

    public function test_pengajuan_kasbon_tidak_bisa_diubah_atau_dihapus_dari_proses_pembayaran(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatKasbon($this->makeKaryawan());
        $idPengajuan = $this->idPengajuan($id);

        $this->putJson("/api/arus-kas/pengajuan/{$idPengajuan}", ['nominal' => 1])->assertStatus(422);
        $this->deleteJson("/api/arus-kas/pengajuan/{$idPengajuan}")->assertStatus(422);

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan, 'nominal' => 1500000, 'dihapus_pada' => null]);
    }

    public function test_alur_approval_sampai_transfer_menjadikan_kasbon_berjalan_dan_tercatat_di_arus_kas(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $pembuat = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatKasbon($this->makeKaryawan());
        $idPengajuan = $this->idPengajuan($id);
        $hariIni = now()->toDateString();

        Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])->assertStatus(200);

        Sanctum::actingAs($pembuat, ['*']);
        $this->getJson("/api/kasbon/{$id}")->assertJsonPath('data.status', 'menunggu_pencairan');

        $this->cairkan($id, $hariIni);

        $this->getJson("/api/kasbon/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'berjalan')
            ->assertJsonPath('data.tanggal_transfer', $hariIni)
            ->assertJsonPath('data.sisa', 1500000)
            ->assertJsonPath('data.bisa_diubah', false)
            ->assertJsonPath('data.bisa_catat_pelunasan', true)
            ->assertJsonPath('data.pembayaran', []);

        $rekap = $this->getJson("/api/arus-kas?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200);
        $baris = collect($rekap->json('data.transaksi'))->where('kategori', 'kasbon')->values();
        $this->assertCount(1, $baris);
        $this->assertSame('keluar', $baris[0]['arah']);
        $this->assertEquals(1500000, $baris[0]['nominal']);

        $psak = app(\App\Modules\ArusKas\ArusKasService::class)->laporanPsak(self::PERUSAHAAN_ID, $hariIni, $hariIni);
        $kasbon = collect($psak['kelompok'][0]['baris'])->firstWhere('label', 'Pemberian kasbon karyawan');
        $this->assertEquals(1500000, $kasbon['nominal']);

        $this->getJson("/api/kasbon/{$id}/riwayat")
            ->assertStatus(200)
            ->assertJsonPath('data.kategori', 'kasbon')
            ->assertJsonPath('data.status', 'ditransfer');
    }

    public function test_pelunasan_manual_mengurangi_sisa_mencatat_pemasukan_dan_melunasi(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $id = $this->buatKasbonLama($idKaryawan, ['nominal' => 1000000, 'cicilan_per_periode' => 250000, 'tanggal' => '2026-03-10']);

        $res = $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 400000, 'keterangan' => 'Bayar tunai', 'catat_pemasukan' => true,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.terbayar', 400000)
            ->assertJsonPath('data.sisa', 600000)
            ->assertJsonPath('data.status', 'berjalan')
            ->assertJsonPath('data.bisa_diubah', false)
            ->assertJsonPath('data.pembayaran.0.sumber', 'manual')
            ->assertJsonPath('data.pembayaran.0.nominal', 400000)
            ->assertJsonPath('data.pembayaran.0.tercatat_pemasukan', true)
            ->assertJsonPath('data.pembayaran.0.bisa_dihapus', true);

        $nomor = DB::table('kasbon')->where('id_kasbon', $id)->value('nomor_kasbon');
        $this->assertDatabaseHas('pemasukan', [
            'id_perusahaan' => self::PERUSAHAAN_ID, 'kategori' => 'pengembalian_dana', 'nominal' => 400000,
            'sumber_dana' => 'Rudi Hartono', 'keterangan' => "Pelunasan kasbon {$nomor} — Bayar tunai", 'dihapus_pada' => null,
        ]);

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 600001])
            ->assertStatus(422)->assertJsonValidationErrors(['nominal']);
        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => '2026-03-09', 'nominal' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors(['tanggal']);

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 600000])
            ->assertStatus(422)->assertJsonValidationErrors(['keterangan']);

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 600000, 'keterangan' => 'Pemutihan sisa'])
            ->assertStatus(201)
            ->assertJsonPath('data.sisa', 0)
            ->assertJsonPath('data.status', 'lunas')
            ->assertJsonPath('data.bisa_catat_pelunasan', false)
            ->assertJsonPath('data.pembayaran.1.tercatat_pemasukan', false);
        $this->assertSame(1, DB::table('pemasukan')->count());

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 1000, 'keterangan' => 'Lagi'])
            ->assertStatus(409);
    }

    public function test_sisa_berdesimal_bisa_dilunasi_persis(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbonLama($this->makeKaryawan(), ['nominal' => 2367741.93, 'cicilan_per_periode' => 500000]);

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 2367741, 'catat_pemasukan' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.sisa', 0.93)
            ->assertJsonPath('data.status', 'berjalan');

        $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 0.93, 'catat_pemasukan' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.sisa', 0)
            ->assertJsonPath('data.status', 'lunas');
    }

    public function test_pelunasan_manual_hanya_boleh_dicatat_dan_dihapus_keuangan(): void
    {
        $keuangan = $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbonLama($this->makeKaryawan(), ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);
        $idPembayaran = $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 100000, 'catat_pemasukan' => true,
        ])->assertStatus(201)->json('data.pembayaran.0.id_kasbon_pembayaran');

        foreach (['ADMIN', 'MANAGER'] as $peran) {
            $this->actingAsRole($peran);
            $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 900000, 'keterangan' => 'Lunas'])
                ->assertStatus(403);
            $this->postJson("/api/kasbon/{$id}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 100000, 'catat_pemasukan' => true])
                ->assertStatus(403);
            $this->deleteJson("/api/kasbon/{$id}/pelunasan/{$idPembayaran}")->assertStatus(403);
            $this->getJson("/api/kasbon/{$id}")
                ->assertStatus(200)
                ->assertJsonPath('data.bisa_catat_pelunasan', false)
                ->assertJsonPath('data.pembayaran.0.bisa_dihapus', false);
        }

        Sanctum::actingAs($keuangan, ['*']);
        $this->getJson("/api/kasbon/{$id}")
            ->assertJsonPath('data.bisa_catat_pelunasan', true)
            ->assertJsonPath('data.pembayaran.0.bisa_dihapus', true);
        $this->assertSame(1, DB::table('kasbon_pembayaran')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('pemasukan')->whereNull('dihapus_pada')->count());
    }

    public function test_hapus_pelunasan_dengan_id_milik_kasbon_lain_ditolak(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $pertama = $this->buatKasbonLama($idKaryawan, ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);
        $kedua   = $this->buatKasbonLama($idKaryawan, ['nominal' => 500000, 'cicilan_per_periode' => 250000]);
        $idPembayaran = $this->postJson("/api/kasbon/{$pertama}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 100000, 'keterangan' => 'Tunai',
        ])->assertStatus(201)->json('data.pembayaran.0.id_kasbon_pembayaran');

        $this->deleteJson("/api/kasbon/{$kedua}/pelunasan/{$idPembayaran}")->assertStatus(404);

        $this->assertNull(DB::table('kasbon_pembayaran')->where('id_kasbon_pembayaran', $idPembayaran)->value('dihapus_pada'));
    }

    public function test_pelunasan_tidak_boleh_sebelum_tanggal_pencairan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatKasbon($this->makeKaryawan(), ['tanggal' => now()->subDays(5)->toDateString(), 'mulai_potong' => now()->format('Y-m')]);
        $this->cairkan($id, now()->toDateString());

        $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->subDays(2)->toDateString(), 'nominal' => 100000, 'catat_pemasukan' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 100000, 'catat_pemasukan' => true,
        ])->assertStatus(201);
    }

    public function test_hapus_pelunasan_manual_mengembalikan_sisa_dan_menghapus_pemasukan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbonLama($this->makeKaryawan(), ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);

        $idPembayaran = $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 400000, 'catat_pemasukan' => true,
        ])->assertStatus(201)->json('data.pembayaran.0.id_kasbon_pembayaran');

        $this->putJson("/api/kasbon/{$id}", $this->payload($this->makeKaryawan('Orang Baru'), ['saldo_awal' => true]))->assertStatus(409);
        $this->deleteJson("/api/kasbon/{$id}")->assertStatus(409);

        $this->deleteJson("/api/kasbon/{$id}/pelunasan/{$idPembayaran}")
            ->assertStatus(200)
            ->assertJsonPath('data.terbayar', 0)
            ->assertJsonPath('data.sisa', 1000000)
            ->assertJsonPath('data.bisa_diubah', true)
            ->assertJsonPath('data.pembayaran', []);

        $this->assertSame(0, DB::table('pemasukan')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('pemasukan')->count());
        $this->deleteJson("/api/kasbon/{$id}/pelunasan/{$idPembayaran}")->assertStatus(404);

        $this->deleteJson("/api/kasbon/{$id}")->assertStatus(200);
        $this->assertNotNull(DB::table('kasbon')->where('id_kasbon', $id)->value('dihapus_pada'));
    }

    public function test_pemasukan_pelunasan_kasbon_tidak_bisa_diubah_atau_dihapus_dari_arus_kas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatKasbonLama($this->makeKaryawan(), ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);
        $this->postJson("/api/kasbon/{$id}/pelunasan", [
            'tanggal' => now()->toDateString(), 'nominal' => 400000, 'catat_pemasukan' => true,
        ])->assertStatus(201);
        $idPemasukan = (string) DB::table('pemasukan')->value('id_pemasukan');

        $this->putJson("/api/arus-kas/pemasukan/{$idPemasukan}", [
            'kategori' => 'lainnya', 'nominal' => 1, 'tanggal' => now()->toDateString(), 'sumber_dana' => 'X',
        ])->assertStatus(422);
        $this->deleteJson("/api/arus-kas/pemasukan/{$idPemasukan}")->assertStatus(422);

        $this->assertDatabaseHas('pemasukan', ['id_pemasukan' => $idPemasukan, 'nominal' => 400000, 'dihapus_pada' => null]);

        $hariIni = now()->toDateString();
        $this->postJson('/api/arus-kas/pemasukan', [
            'kategori' => 'lainnya', 'nominal' => 50000, 'tanggal' => $hariIni, 'sumber_dana' => 'Kas kecil',
        ])->assertStatus(201);
        $baris = collect($this->getJson("/api/arus-kas/pemasukan?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200)->json('data'));
        $this->assertFalse($baris->firstWhere('id', $idPemasukan)['dapat_diubah']);
        $this->assertTrue($baris->firstWhere('sumber_dana', 'Kas kecil')['dapat_diubah']);
    }

    public function test_ubah_cicilan_hanya_untuk_kasbon_disetujui_yang_belum_lunas(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $menunggu = $this->buatKasbon($idKaryawan);
        $berjalan = $this->buatKasbonLama($idKaryawan, ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);
        $bulanDepan = now()->addMonthNoOverflow()->format('Y-m');

        $bulanIni = now()->format('Y-m');
        $alasan = ['alasan' => 'Permintaan karyawan'];

        $this->patchJson("/api/kasbon/{$menunggu}/cicilan", ['cicilan_per_periode' => 100000, 'mulai_potong' => $bulanDepan] + $alasan)
            ->assertStatus(409);
        $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 1000001, 'mulai_potong' => $bulanDepan] + $alasan)
            ->assertStatus(422)->assertJsonValidationErrors(['cicilan_per_periode']);
        $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 100000, 'mulai_potong' => $bulanDepan])
            ->assertStatus(422)->assertJsonValidationErrors(['alasan']);
        $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 250000, 'mulai_potong' => $bulanIni] + $alasan)
            ->assertStatus(422)->assertJsonValidationErrors(['cicilan_per_periode']);
        $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 100000, 'mulai_potong' => (now()->year + 3) . '-01'] + $alasan)
            ->assertStatus(422)->assertJsonValidationErrors(['mulai_potong']);
        $this->assertSame(0, DB::table('kasbon_riwayat_cicilan')->count());

        $res = $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 100000, 'mulai_potong' => $bulanDepan] + $alasan);
        $res->assertStatus(200)
            ->assertJsonPath('data.cicilan_per_periode', 100000)
            ->assertJsonPath('data.mulai_potong', $bulanDepan)
            ->assertJsonPath('data.sisa_potongan', 10)
            ->assertJsonPath('data.nominal', 1000000)
            ->assertJsonCount(1, 'data.perubahan_cicilan')
            ->assertJsonPath('data.perubahan_cicilan.0.cicilan_lama', 250000)
            ->assertJsonPath('data.perubahan_cicilan.0.cicilan_baru', 100000)
            ->assertJsonPath('data.perubahan_cicilan.0.mulai_potong_lama', $bulanIni)
            ->assertJsonPath('data.perubahan_cicilan.0.mulai_potong_baru', $bulanDepan)
            ->assertJsonPath('data.perubahan_cicilan.0.alasan', 'Permintaan karyawan');
        $this->assertNotEmpty($res->json('data.perubahan_cicilan.0.oleh'));

        $this->postJson("/api/kasbon/{$berjalan}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 1000000, 'keterangan' => 'Lunas tunai'])
            ->assertStatus(201)->assertJsonPath('data.status', 'lunas');
        $this->patchJson("/api/kasbon/{$berjalan}/cicilan", ['cicilan_per_periode' => 50000, 'mulai_potong' => $bulanDepan] + $alasan)
            ->assertStatus(409);
    }

    public function test_ubah_cicilan_menolak_bulan_mulai_potong_sebelum_bulan_kasbon(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbonLama($this->makeKaryawan(), ['tanggal' => now()->toDateString()]);

        $this->patchJson("/api/kasbon/{$id}/cicilan", [
            'cicilan_per_periode' => 100000, 'mulai_potong' => now()->subMonthNoOverflow()->format('Y-m'), 'alasan' => 'Coba mundur',
        ])->assertStatus(422)->assertJsonValidationErrors(['mulai_potong']);
    }

    public function test_kasbon_tidak_bisa_dicairkan_bila_karyawan_sudah_nonaktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();
        $id = $this->buatKasbon($idKaryawan);
        $idPengajuan = $this->idPengajuan($id);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        DB::table('karyawan')->where('id_karyawan', $idKaryawan)->update(['aktif' => 0]);

        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(409);

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan, 'status' => 'siap_transfer']);
        $this->getJson("/api/kasbon/{$id}")->assertJsonPath('data.status', 'menunggu_pencairan');
    }

    public function test_pratinjau_periode_gaji_bisa_dibaca_pemilik_izin_kasbon(): void
    {
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/payroll/preview-rentang?bulan=2026-07')
            ->assertStatus(200)
            ->assertJsonPath('data.tanggal_selesai', '2026-07-20');
    }

    public function test_ubah_cicilan_boleh_saat_menunggu_pencairan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->buatKasbon($this->makeKaryawan());

        $this->getJson("/api/kasbon/{$id}")->assertJsonPath('data.status', 'menunggu_pencairan');
        $this->patchJson("/api/kasbon/{$id}/cicilan", [
            'cicilan_per_periode' => 300000, 'mulai_potong' => now()->format('Y-m'), 'alasan' => 'Salah input cicilan',
        ])->assertStatus(200)->assertJsonPath('data.cicilan_per_periode', 300000);

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $this->idPengajuan($id), 'nominal' => 1500000, 'status' => 'disetujui']);
    }

    public function test_ubah_kasbon_baru_dengan_saldo_awal_true_tidak_melewati_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $id = $this->buatKasbon($idKaryawan);

        $this->putJson("/api/kasbon/{$id}", $this->payload($idKaryawan, ['saldo_awal' => true, 'nominal' => 2000000]))
            ->assertStatus(200)
            ->assertJsonPath('data.saldo_awal', false)
            ->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('kasbon', ['id_kasbon' => $id, 'saldo_awal' => 0]);
        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_kasbon' => $id, 'nominal' => 2000000, 'status' => 'menunggu_approval', 'dihapus_pada' => null]);
    }

    public function test_opsi_karyawan_memuat_karyawan_nonaktif_dengan_penanda(): void
    {
        $this->actingAsRole('KEUANGAN');
        $aktif    = $this->makeKaryawan('Aktif Orangnya');
        $nonaktif = $this->makeKaryawan('Sudah Keluar', ['aktif' => 0]);

        $opsi = collect($this->getJson('/api/kasbon/opsi/karyawan')->assertStatus(200)->json('data'));

        $this->assertTrue($opsi->firstWhere('id_karyawan', $aktif)['aktif']);
        $this->assertFalse($opsi->firstWhere('id_karyawan', $nonaktif)['aktif']);

        $this->postJson('/api/kasbon', $this->payload($nonaktif, ['saldo_awal' => true]))
            ->assertStatus(201)->assertJsonPath('data.status', 'berjalan');
    }

    public function test_karyawan_yang_punya_kasbon_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();
        $this->buatKasbonLama($idKaryawan);

        $this->deleteJson("/api/karyawan/{$idKaryawan}")->assertStatus(422);

        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('dihapus_pada'));
    }

    public function test_pembuat_kasbon_diberi_tahu_saat_dicairkan_dan_saat_ditolak_keuangan(): void
    {
        $pembuat = $this->actingAsRole('KEUANGAN');
        $idKaryawan = $this->makeKaryawan();
        $dicairkan = $this->buatKasbon($idKaryawan);
        $ditolak   = $this->buatKasbon($idKaryawan);

        $this->actingAsRole('SUPERADMIN');
        $this->cairkan($dicairkan, now()->toDateString());
        $this->patchJson('/api/arus-kas/pengajuan/' . $this->idPengajuan($ditolak) . '/tolak', ['alasan' => 'Dana belum tersedia'])
            ->assertStatus(200);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pembuat->id_pengguna, 'tipe' => 'kasbon_dicairkan',
            'referensi_id' => $dicairkan, 'link' => "/kasbon/{$dicairkan}",
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pembuat->id_pengguna, 'tipe' => 'kasbon_ditolak',
            'referensi_id' => $ditolak, 'link' => "/kasbon/{$ditolak}",
        ]);

        Sanctum::actingAs($pembuat, ['*']);
        $sendiri = $this->buatKasbon($idKaryawan);
        $this->cairkan($sendiri, now()->toDateString());
        $this->assertDatabaseMissing('notifikasi', ['tipe' => 'kasbon_dicairkan', 'referensi_id' => $sendiri]);
    }

    public function test_daftar_menyaring_status_pencarian_dan_ringkasan(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        $rudi = $this->makeKaryawan('Rudi Hartono');
        $sari = $this->makeKaryawan('Sari Dewi');

        $this->buatKasbon($rudi);
        $berjalan = $this->buatKasbonLama($rudi, ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);
        $lunas    = $this->buatKasbonLama($sari, ['nominal' => 300000, 'cicilan_per_periode' => 300000]);
        $this->postJson("/api/kasbon/{$berjalan}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 250000, 'keterangan' => 'Tunai'])->assertStatus(201);
        $this->postJson("/api/kasbon/{$lunas}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 300000, 'keterangan' => 'Tunai'])->assertStatus(201);

        $this->getJson('/api/kasbon')->assertStatus(200)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/kasbon?status=menunggu_approval')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/kasbon?status=berjalan')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_kasbon', $berjalan)
            ->assertJsonPath('data.0.sisa', 750000);
        $this->getJson('/api/kasbon?status=lunas')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_kasbon', $lunas);
        $this->getJson('/api/kasbon?status=ditolak')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/kasbon?search=sari')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/kasbon?id_karyawan={$rudi}")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/kasbon?search[]=x&status[]=y')->assertStatus(200)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/kasbon?limit=1&page=2')->assertJsonPath('meta.totalPages', 3)->assertJsonCount(1, 'data');

        $this->getJson('/api/kasbon/ringkasan')
            ->assertStatus(200)
            ->assertJsonPath('data.total_sisa', 750000)
            ->assertJsonPath('data.jumlah_berjalan', 1)
            ->assertJsonPath('data.jumlah_karyawan', 1)
            ->assertJsonPath('data.jumlah_menunggu', 1);

        $opsi = collect($this->getJson('/api/kasbon/opsi/karyawan')->assertStatus(200)->json('data'));
        $this->assertEquals(750000, $opsi->firstWhere('id_karyawan', $rudi)['sisa_kasbon']);
        $this->assertSame(1, $opsi->firstWhere('id_karyawan', $rudi)['jumlah_kasbon_berjalan']);
        $this->assertEquals(0, $opsi->firstWhere('id_karyawan', $sari)['sisa_kasbon']);
    }

    public function test_rincian_sumber_pengajuan_dan_rincian_approver_menampilkan_data_kasbon(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('kasbon', (string) $approver->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();
        $this->buatKasbonLama($idKaryawan, ['nominal' => 800000, 'cicilan_per_periode' => 200000]);
        $id = $this->buatKasbon($idKaryawan);
        $idPengajuan = $this->idPengajuan($id);
        $idApproval  = (string) DB::table('approval_pengajuan')->where('id_referensi', $idPengajuan)->value('id_approval');

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'kasbon')
            ->assertJsonPath('data.data.nama_karyawan', 'Rudi Hartono')
            ->assertJsonPath('data.data.nominal', 1500000)
            ->assertJsonPath('data.data.nomor_rekening', '2880705027')
            ->assertJsonPath('data.data.nama_bank', 'BCA')
            ->assertJsonPath('data.data.pembayaran', []);

        Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson("/api/approval-pengajuan/{$idApproval}/rincian");
        $res->assertStatus(200)->assertJsonPath('data.rincian.kode', 'kasbon');

        $info = collect($res->json('data.rincian.info'));
        $this->assertStringContainsString('Rudi Hartono', $info->firstWhere('label', 'Karyawan')['value']);
        $this->assertSame('Biaya sekolah anak', $info->firstWhere('label', 'Keperluan')['value']);
        $this->assertEquals(500000, $info->firstWhere('label', 'Cicilan per Periode Gaji')['value']);
        $this->assertEquals(800000, $info->firstWhere('label', 'Sisa Kasbon Lain yang Berjalan')['value']);
        $this->assertSame('2880705027', $info->firstWhere('label', 'Nomor Rekening')['value']);
    }

    public function test_kasbon_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $karyawanLain = $this->makeKaryawan('Orang Lain', ['id_perusahaan' => $idPerusahaanLain]);
        $idKasbon = (string) Str::uuid();
        DB::table('kasbon')->insert([
            'id_kasbon' => $idKasbon, 'id_perusahaan' => $idPerusahaanLain, 'nomor_kasbon' => 'KSB-LAIN-0001',
            'id_karyawan' => $karyawanLain, 'tanggal' => '2026-03-10', 'nominal' => 1000000,
            'cicilan_per_periode' => 250000, 'mulai_potong' => '2026-04-01', 'keperluan' => 'Milik perusahaan lain',
            'saldo_awal' => 1, 'dibuat_pada' => now(),
        ]);

        $this->getJson('/api/kasbon')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson("/api/kasbon/{$idKasbon}")->assertStatus(404);
        $this->getJson("/api/kasbon/{$idKasbon}/riwayat")->assertStatus(404);
        $this->putJson("/api/kasbon/{$idKasbon}", $this->payload($this->makeKaryawan()))->assertStatus(404);
        $this->deleteJson("/api/kasbon/{$idKasbon}")->assertStatus(404);
        $this->patchJson("/api/kasbon/{$idKasbon}/cicilan", ['cicilan_per_periode' => 1000, 'mulai_potong' => '2026-05', 'alasan' => 'Coba'])->assertStatus(404);
        $this->postJson("/api/kasbon/{$idKasbon}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 1000, 'keterangan' => 'Coba'])->assertStatus(404);
        $this->getJson('/api/kasbon/ringkasan')->assertJsonPath('data.total_sisa', 0);

        $this->assertNull(DB::table('kasbon')->where('id_kasbon', $idKasbon)->value('dihapus_pada'));
        $this->assertSame(0, DB::table('kasbon_pembayaran')->count());
    }

    public function test_peran_tanpa_izin_ditolak(): void
    {
        $idKaryawan = $this->makeKaryawan();

        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/kasbon')->assertStatus(403);
        $this->postJson('/api/kasbon', $this->payload($idKaryawan))->assertStatus(403);

        foreach (['KEUANGAN', 'MANAGER', 'ADMIN'] as $peran) {
            $this->actingAsRole($peran);
            $this->getJson('/api/kasbon')->assertStatus(200);
        }
    }

    public function test_export_excel_daftar_kasbon(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->buatKasbonLama($this->makeKaryawan(), ['nominal' => 1000000, 'cicilan_per_periode' => 250000]);

        $res = $this->get('/api/kasbon/export/excel?status=berjalan');

        $res->assertStatus(200);
        $this->assertStringContainsString('kasbon-', (string) $res->headers->get('content-disposition'));
    }
}
