<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PengeluaranSupirTest extends TestCase
{
    use RefreshDatabase;

    private string $idProyek = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function buatPengguna(string $kodePeran): Pengguna
    {
        $this->ensurePerusahaan();

        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function masuk(Pengguna $pengguna): void
    {
        Sanctum::actingAs($pengguna, ['*']);
    }

    private function buatArmada(string $nopol, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('armada')->insert(array_merge([
            'id_armada' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol,
            'merk' => 'Hino', 'model' => 'Dutro', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatArmadaVendor(string $nopol, string $namaVendor = 'PT Mitra Angkut'): string
    {
        $this->ensurePerusahaan();
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_vendor' => 'VND-' . Str::random(6),
            'nama_vendor' => $namaVendor, 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $id, 'id_vendor' => $idVendor, 'nopol' => $nopol, 'merk' => 'Isuzu', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    /** @return array{pengguna: Pengguna, id_supir: string} */
    private function buatSupir(?string $idArmadaDefault = null, array $override = []): array
    {
        $pengguna = $this->buatPengguna('SUPIR');
        $idSupir  = (string) Str::uuid();
        DB::table('supir')->insert(array_merge([
            'id_supir' => $idSupir, 'id_pengguna' => $pengguna->id_pengguna, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_armada_default' => $idArmadaDefault, 'nama' => 'Budi Supir', 'no_sim' => 'SIM-' . Str::random(6),
            'jenis_sim' => 'B2', 'status' => 'aktif', 'dibuat_pada' => now(),
        ], $override));

        return ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function idProyek(): string
    {
        if ($this->idProyek === '') {
            $this->idProyek = (string) Str::uuid();
            DB::table('proyek')->insert([
                'id_proyek' => $this->idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
                'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Uji', 'status' => 'aktif', 'dibuat_pada' => now(),
            ]);
        }

        return $this->idProyek;
    }

    private function tugaskan(string $idSupir, string $tanggal, ?string $idArmada, ?string $idArmadaVendor = null, string $status = 'aktif'): void
    {
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $this->idProyek(), 'id_supir' => $idSupir,
            'id_armada' => $idArmada, 'id_armada_vendor' => $idArmadaVendor, 'tanggal_tugas' => $tanggal,
            'status' => $status, 'dibuat_pada' => now(),
        ]);
    }

    private function aturApproval(string $kode, ?string $idApprover, int $aktif = 1): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => ucwords(str_replace('_', ' ', $kode)), 'mode_resolusi' => 'pinned',
            'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        if ($idApprover !== null) {
            DB::table('approval_config_approver')->insert([
                'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
                'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
            ]);
        }
    }

    private function aturBatasApproval(float $batas): void
    {
        DB::table('pengaturan')->insert([
            'id_pengaturan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kunci' => 'batas_approval_keuangan', 'nilai' => (string) $batas, 'dibuat_pada' => now(),
        ]);
    }

    private function foto(string $nama = 'nota.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($nama, 40, 'image/jpeg');
    }

    private function kirim(string $idArmada, array $override = []): TestResponse
    {
        return $this->post('/api/pengeluaran-supir/saya', array_merge([
            'jenis' => 'tambal_ban', 'id_armada' => $idArmada, 'tanggal' => now()->toDateString(),
            'nominal' => 25000, 'keterangan' => 'Tambal ban di rest area KM 57', 'foto' => [$this->foto()],
        ], $override), ['Accept' => 'application/json']);
    }

    private function idPengajuan(string $idPengeluaranSupir): string
    {
        return (string) DB::table('pengeluaran_supir')->where('id_pengeluaran_supir', $idPengeluaranSupir)->value('id_pengajuan');
    }

    public function test_opsi_berisi_jenis_unit_milik_supir_dan_rekening(): void
    {
        $hariIni   = now()->toDateString();
        $idDefault = $this->buatArmada('B 1111 AA');
        $idTugas   = $this->buatArmada('B 2222 BB');
        $idLama    = $this->buatArmada('B 3333 CC');
        $idBatal   = $this->buatArmada('B 4444 DD');
        $idGanti   = $this->buatArmada('B 6666 FF');
        $idVendor  = $this->buatArmadaVendor('B 5555 EE');

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => 'NIK-001',
            'nama_karyawan' => 'Budi Supir', 'nama_bank' => 'BCA', 'nomor_rekening' => '1234567890',
            'atas_nama_rekening' => 'Budi Santoso', 'dibuat_pada' => now(),
        ]);
        $supir = $this->buatSupir($idDefault, ['id_karyawan' => $idKaryawan]);

        $this->tugaskan($supir['id_supir'], now()->subDays(5)->toDateString(), $idTugas);
        $this->tugaskan($supir['id_supir'], now()->subDays(40)->toDateString(), $idLama);
        $this->tugaskan($supir['id_supir'], $hariIni, $idBatal, null, 'batal');
        $this->tugaskan($supir['id_supir'], $hariIni, null, $idVendor);
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(), 'id_proyek' => $this->idProyek(), 'id_shift' => (string) Str::uuid(),
            'id_supir' => $supir['id_supir'], 'tanggal' => now()->subDay()->toDateString(),
            'id_armada_override' => $idGanti, 'dibuat_pada' => now(),
        ]);

        $this->masuk($supir['pengguna']);
        $res = $this->getJson('/api/pengeluaran-supir/saya/opsi')->assertStatus(200);

        $this->assertSame(['tambal_ban', 'cuci_mobil'], array_column($res->json('data.jenis'), 'value'));
        $this->assertSame(['Tambal Ban', 'Cuci Mobil'], array_column($res->json('data.jenis'), 'label'));
        $this->assertSame(['B 1111 AA', 'B 2222 BB', 'B 6666 FF', 'B 5555 EE'], array_column($res->json('data.unit'), 'nopol'));
        $res->assertJsonPath('data.unit.0.id_armada', $idDefault)
            ->assertJsonPath('data.unit.0.kunci', 'armada:' . $idDefault)
            ->assertJsonPath('data.unit.0.keterangan', 'Hino Dutro')
            ->assertJsonPath('data.unit.0.unit_vendor', false)
            ->assertJsonPath('data.unit.3.id_armada', null)
            ->assertJsonPath('data.unit.3.id_armada_vendor', $idVendor)
            ->assertJsonPath('data.unit.3.unit_vendor', true)
            ->assertJsonPath('data.unit.3.keterangan', 'Unit vendor PT Mitra Angkut')
            ->assertJsonPath('data.rekening.nama_bank', 'BCA')
            ->assertJsonPath('data.rekening.nomor_rekening', '1234567890')
            ->assertJsonPath('data.rekening.atas_nama_rekening', 'Budi Santoso')
            ->assertJsonPath('data.tanggal_paling_awal', now()->subDays(30)->toDateString())
            ->assertJsonPath('data.nominal_maksimal', 10000000)
            ->assertJsonPath('data.maks_foto', 3);
    }

    public function test_hanya_supir_internal_yang_boleh_mengakses(): void
    {
        $this->getJson('/api/pengeluaran-supir/saya/opsi')->assertStatus(401);

        $this->masuk($this->buatPengguna('SUPIR_VENDOR'));
        $this->getJson('/api/pengeluaran-supir/saya/opsi')->assertStatus(403);
        $this->getJson('/api/pengeluaran-supir/saya')->assertStatus(403);
        $this->post('/api/pengeluaran-supir/saya', [
            'jenis' => 'tambal_ban', 'id_armada' => (string) Str::uuid(), 'tanggal' => now()->toDateString(),
            'nominal' => 25000, 'foto' => [$this->foto()],
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->masuk($this->buatPengguna('KEUANGAN'));
        $this->getJson('/api/pengeluaran-supir/saya')->assertStatus(403);
    }

    public function test_kirim_pengajuan_membuat_pengajuan_pengeluaran_dan_menyimpan_foto(): void
    {
        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $res = $this->kirim($idArmada, [
            'foto' => [$this->foto('nota.jpg'), $this->foto('ban.png')],
            'nama_bank' => 'Mandiri', 'nomor_rekening' => '900100200', 'atas_nama_rekening' => 'Budi S',
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.nomor', 'BSP-' . now()->format('Ym') . '-0001')
            ->assertJsonPath('data.jenis', 'tambal_ban')
            ->assertJsonPath('data.label_jenis', 'Tambal Ban')
            ->assertJsonPath('data.nopol', 'B 1111 AA')
            ->assertJsonPath('data.unit_vendor', false)
            ->assertJsonPath('data.status', 'menunggu_pembayaran')
            ->assertJsonPath('data.label_status', 'Menunggu Pembayaran')
            ->assertJsonPath('data.bisa_dibatalkan', false)
            ->assertJsonPath('data.nomor_rekening', '900100200')
            ->assertJsonCount(2, 'data.foto');
        $this->assertEquals(25000, $res->json('data.nominal'));
        $this->assertStringContainsString('/storage/pengeluaran-supir/', $res->json('data.foto.0'));

        $id = (string) $res->json('data.id_pengeluaran_supir');
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengeluaran_supir', $id)->first();
        $this->assertNotNull($pengajuan);
        $this->assertSame('tambal_ban', $pengajuan->kategori);
        $this->assertSame('Budi Supir', $pengajuan->penerima);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertEquals(25000, $pengajuan->nominal);
        $this->assertStringContainsString('B 1111 AA', (string) $pengajuan->keterangan);
        $this->assertStringContainsString('rest area KM 57', (string) $pengajuan->keterangan);
        $this->assertSame($pengajuan->id_pengajuan, $this->idPengajuan($id));

        $berkas = DB::table('pengeluaran_supir_foto')->where('id_pengeluaran_supir', $id)->orderBy('urutan')->pluck('url_foto');
        $this->assertCount(2, $berkas);
        foreach ($berkas as $url) {
            Storage::disk('public')->assertExists((string) $url);
        }

        $this->getJson("/api/pengeluaran-supir/saya/{$id}")->assertStatus(200)
            ->assertJsonPath('data.id_pengeluaran_supir', $id)
            ->assertJsonPath('data.nomor_pengajuan', $pengajuan->nomor_pengajuan);

        $this->assertSame('1', (string) DB::table('pengeluaran_supir')->count());
        $this->assertSame('cuci_mobil', $this->kirim($idArmada, ['jenis' => 'cuci_mobil', 'nominal' => 40000])
            ->assertStatus(201)->json('data.jenis'));
        $this->assertSame('900100200', $this->getJson('/api/pengeluaran-supir/saya/opsi')->json('data.rekening.nomor_rekening'));
    }

    public function test_dengan_jenis_approval_pengeluaran_supir_semua_pengajuan_wajib_disetujui_walau_di_bawah_batas(): void
    {
        $approver = $this->buatPengguna('SUPERADMIN');
        $this->aturApproval('pengeluaran_supir', (string) $approver->id_pengguna);
        $this->aturBatasApproval(1000000);

        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $res = $this->kirim($idArmada)->assertStatus(201)
            ->assertJsonPath('data.status', 'menunggu_persetujuan')
            ->assertJsonPath('data.bisa_dibatalkan', true);
        $id = (string) $res->json('data.id_pengeluaran_supir');

        $this->masuk($approver);
        $menunggu = $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)->json('data');
        $this->assertCount(1, $menunggu);

        $rincian = $this->getJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/rincian")->assertStatus(200);
        $info    = collect($rincian->json('data.rincian.info'))->keyBy('label');
        $this->assertSame('Budi Supir', $info['Supir']['value']);
        $this->assertSame('B 1111 AA', $info['Unit']['value']);
        $this->assertSame('Tunai (rekening tidak diisi)', $info['Rekening Tujuan']['value']);
        $this->assertSame('tautan', $info['Foto Nota 1']['tipe']);
        $this->assertStringContainsString('/storage/pengeluaran-supir/', $info['Foto Nota 1']['value']);

        $this->patchJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);

        $this->masuk($supir['pengguna']);
        $this->getJson("/api/pengeluaran-supir/saya/{$id}")
            ->assertJsonPath('data.status', 'menunggu_pembayaran')
            ->assertJsonPath('data.bisa_dibatalkan', false);
        $this->assertTrue(DB::table('notifikasi')
            ->where('id_pengguna', $supir['pengguna']->id_pengguna)
            ->where('tipe', 'pengeluaran_supir_disetujui')
            ->where('referensi_id', $id)
            ->exists());
    }

    public function test_tanpa_jenis_approval_khusus_mengikuti_batas_dan_approval_umum(): void
    {
        $approver = $this->buatPengguna('SUPERADMIN');
        $this->aturApproval('pengajuan_pengeluaran', (string) $approver->id_pengguna);
        $this->aturBatasApproval(1000000);

        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $this->kirim($idArmada, ['nominal' => 25000])->assertStatus(201)->assertJsonPath('data.status', 'menunggu_pembayaran');
        $this->kirim($idArmada, ['nominal' => 1500000])->assertStatus(201)->assertJsonPath('data.status', 'menunggu_persetujuan');

        $this->assertFalse(DB::table('notifikasi')
            ->where('id_pengguna', $supir['pengguna']->id_pengguna)
            ->where('tipe', 'like', 'pengeluaran_supir_%')
            ->exists());
    }

    public function test_jenis_approval_pengeluaran_supir_nonaktif_berarti_dilewati(): void
    {
        $approver = $this->buatPengguna('SUPERADMIN');
        $this->aturApproval('pengeluaran_supir', (string) $approver->id_pengguna, 0);
        $this->aturApproval('pengajuan_pengeluaran', (string) $approver->id_pengguna);

        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $this->kirim($idArmada, ['nominal' => 1500000])->assertStatus(201)->assertJsonPath('data.status', 'menunggu_pembayaran');
    }

    public function test_approver_belum_lengkap_memberi_pesan_yang_dimengerti_supir(): void
    {
        $this->aturApproval('pengeluaran_supir', null);

        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $res = $this->kirim($idArmada)->assertStatus(422);
        $this->assertStringContainsString('Hubungi admin kantor', (string) $res->json('message'));
        $this->assertSame(0, DB::table('pengeluaran_supir')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_validasi_pengajuan(): void
    {
        $idArmada = $this->buatArmada('B 1111 AA');
        $idLain   = $this->buatArmada('B 9999 ZZ');
        $idVendor = $this->buatArmadaVendor('B 5555 EE');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $this->kirim($idLain)->assertStatus(422)->assertJsonValidationErrors(['id_armada']);
        $this->kirim($idArmada, ['id_armada_vendor' => $idVendor])->assertStatus(422)->assertJsonValidationErrors(['id_armada']);
        $this->post('/api/pengeluaran-supir/saya', [
            'jenis' => 'tambal_ban', 'id_armada_vendor' => $idVendor, 'tanggal' => now()->toDateString(),
            'nominal' => 25000, 'foto' => [$this->foto()],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['id_armada_vendor']);
        $this->post('/api/pengeluaran-supir/saya', [
            'jenis' => 'tambal_ban', 'tanggal' => now()->toDateString(), 'nominal' => 25000, 'foto' => [$this->foto()],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['id_armada']);

        $this->kirim($idArmada, ['jenis' => 'beli_rokok'])->assertStatus(422)->assertJsonValidationErrors(['jenis']);
        $this->kirim($idArmada, ['tanggal' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->kirim($idArmada, ['tanggal' => now()->subDays(31)->toDateString()])->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->kirim($idArmada, ['nominal' => 0])->assertStatus(422)->assertJsonValidationErrors(['nominal']);
        $this->kirim($idArmada, ['nominal' => 10000001])->assertStatus(422)->assertJsonValidationErrors(['nominal']);
        $this->kirim($idArmada, ['foto' => []])->assertStatus(422)->assertJsonValidationErrors(['foto']);
        $this->kirim($idArmada, ['foto' => [$this->foto('1.jpg'), $this->foto('2.jpg'), $this->foto('3.jpg'), $this->foto('4.jpg')]])
            ->assertStatus(422)->assertJsonValidationErrors(['foto']);
        $this->kirim($idArmada, ['foto' => [UploadedFile::fake()->create('nota.pdf', 40, 'application/pdf')]])
            ->assertStatus(422)->assertJsonValidationErrors(['foto.0']);
        $this->kirim($idArmada, ['nomor_rekening' => '123'])->assertStatus(422)->assertJsonValidationErrors(['nama_bank']);

        $this->assertSame(0, DB::table('pengeluaran_supir')->count());

        DB::table('supir')->where('id_supir', $supir['id_supir'])->update(['status' => 'nonaktif']);
        $this->kirim($idArmada)->assertStatus(403);
    }

    public function test_unit_vendor_yang_ditugaskan_boleh_diajukan_dan_ditandai(): void
    {
        $idVendor = $this->buatArmadaVendor('B 5555 EE');
        $supir    = $this->buatSupir();
        $this->tugaskan($supir['id_supir'], now()->subDays(2)->toDateString(), null, $idVendor);
        $this->masuk($supir['pengguna']);

        $res = $this->post('/api/pengeluaran-supir/saya', [
            'jenis' => 'cuci_mobil', 'id_armada_vendor' => $idVendor, 'tanggal' => now()->subDays(2)->toDateString(),
            'nominal' => 50000, 'foto' => [$this->foto()],
        ], ['Accept' => 'application/json'])->assertStatus(201)
            ->assertJsonPath('data.unit_vendor', true)
            ->assertJsonPath('data.nopol', 'B 5555 EE');

        $keterangan = (string) DB::table('pengajuan_pengeluaran')
            ->where('id_pengeluaran_supir', $res->json('data.id_pengeluaran_supir'))->value('keterangan');
        $this->assertStringContainsString('B 5555 EE (unit vendor)', $keterangan);
        $this->assertStringContainsString('Cuci Mobil', $keterangan);
    }

    public function test_pengajuan_kembar_dalam_waktu_singkat_ditolak(): void
    {
        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $this->kirim($idArmada)->assertStatus(201);
        $kembar = $this->kirim($idArmada)->assertStatus(409);
        $this->assertStringContainsString('baru saja dikirim', (string) $kembar->json('message'));
        $this->kirim($idArmada, ['nominal' => 30000])->assertStatus(201);
        $this->kirim($idArmada, ['jenis' => 'cuci_mobil'])->assertStatus(201);

        DB::table('pengeluaran_supir')->update(['dibuat_pada' => now()->subMinutes(11)]);
        $this->kirim($idArmada)->assertStatus(201);

        $this->assertSame(4, DB::table('pengeluaran_supir')->count());
    }

    public function test_daftar_hanya_milik_sendiri_dengan_filter_status_dan_halaman(): void
    {
        $approver = $this->buatPengguna('SUPERADMIN');
        $idArmada = $this->buatArmada('B 1111 AA');
        $idLain   = $this->buatArmada('B 2222 BB');
        $supir    = $this->buatSupir($idArmada);
        $lain     = $this->buatSupir($idLain, ['nama' => 'Tono Supir']);

        $this->masuk($supir['pengguna']);
        $idDisetujui = (string) $this->kirim($idArmada, ['nominal' => 20000])->assertStatus(201)->json('data.id_pengeluaran_supir');
        $this->kirim($idArmada, ['nominal' => 21000])->assertStatus(201);

        $this->aturApproval('pengeluaran_supir', (string) $approver->id_pengguna);
        $idMenunggu = (string) $this->kirim($idArmada, ['nominal' => 22000])->assertStatus(201)->json('data.id_pengeluaran_supir');

        $this->masuk($lain['pengguna']);
        $this->kirim($idLain, ['nominal' => 99000])->assertStatus(201);
        $this->getJson('/api/pengeluaran-supir/saya')->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->getJson("/api/pengeluaran-supir/saya/{$idDisetujui}")->assertStatus(404);
        $this->deleteJson("/api/pengeluaran-supir/saya/{$idMenunggu}")->assertStatus(404);

        $this->masuk($supir['pengguna']);
        $semua = $this->getJson('/api/pengeluaran-supir/saya')->assertStatus(200)->assertJsonPath('meta.total', 3);
        $this->assertSame($idMenunggu, $semua->json('data.0.id_pengeluaran_supir'));
        $this->assertNotContains(99000, array_map('intval', array_column($semua->json('data'), 'nominal')));

        $this->getJson('/api/pengeluaran-supir/saya?status=menunggu_persetujuan')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_pengeluaran_supir', $idMenunggu);
        $this->getJson('/api/pengeluaran-supir/saya?status=menunggu_pembayaran')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/pengeluaran-supir/saya?status=dibayar')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/pengeluaran-supir/saya?status=asal')->assertStatus(422);
        $this->getJson('/api/pengeluaran-supir/saya?limit=2&page=2')
            ->assertJsonPath('meta.totalPages', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/pengeluaran-supir/saya?limit=500')->assertStatus(422);
    }

    public function test_batalkan_hanya_saat_menunggu_persetujuan_atau_ditolak(): void
    {
        $approver = $this->buatPengguna('SUPERADMIN');
        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);

        $idDisetujui = (string) $this->kirim($idArmada, ['nominal' => 20000])->assertStatus(201)->json('data.id_pengeluaran_supir');
        $this->deleteJson("/api/pengeluaran-supir/saya/{$idDisetujui}")->assertStatus(409);

        $this->aturApproval('pengeluaran_supir', (string) $approver->id_pengguna);
        $idMenunggu  = (string) $this->kirim($idArmada, ['nominal' => 22000])->assertStatus(201)->json('data.id_pengeluaran_supir');
        $idPengajuan = $this->idPengajuan($idMenunggu);

        $this->deleteJson("/api/pengeluaran-supir/saya/{$idMenunggu}")->assertStatus(200);
        $this->getJson("/api/pengeluaran-supir/saya/{$idMenunggu}")->assertStatus(404);
        $this->getJson('/api/pengeluaran-supir/saya')->assertJsonPath('meta.total', 1);
        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));
        $this->assertSame(0, DB::table('pengeluaran_supir_foto')
            ->where('id_pengeluaran_supir', $idMenunggu)->whereNull('dihapus_pada')->count());

        $this->masuk($approver);
        $this->assertCount(0, $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)->json('data'));
    }

    public function test_ditolak_dan_dibayar_keuangan_sampai_ke_supir(): void
    {
        $keuangan = $this->buatPengguna('SUPERADMIN');
        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);

        $this->masuk($supir['pengguna']);
        $idDitolak = (string) $this->kirim($idArmada, ['nominal' => 20000])->assertStatus(201)->json('data.id_pengeluaran_supir');
        $idDibayar = (string) $this->kirim($idArmada, ['jenis' => 'cuci_mobil', 'nominal' => 45000])->assertStatus(201)->json('data.id_pengeluaran_supir');

        $this->masuk($keuangan);
        $this->patchJson('/api/arus-kas/pengajuan/' . $this->idPengajuan($idDitolak) . '/tolak', ['alasan' => 'Nota tidak terbaca'])->assertStatus(200);
        $idPengajuan = $this->idPengajuan($idDibayar);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $this->masuk($supir['pengguna']);
        $this->getJson("/api/pengeluaran-supir/saya/{$idDitolak}")->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Nota tidak terbaca')
            ->assertJsonPath('data.bisa_dibatalkan', true)
            ->assertJsonPath('data.tanggal_dibayar', null);
        $dibayar = $this->getJson("/api/pengeluaran-supir/saya/{$idDibayar}")->assertStatus(200)
            ->assertJsonPath('data.status', 'dibayar')
            ->assertJsonPath('data.label_status', 'Sudah Dibayar')
            ->assertJsonPath('data.tanggal_dibayar', now()->toDateString())
            ->assertJsonPath('data.alasan_ditolak', null)
            ->assertJsonPath('data.bisa_dibatalkan', false);
        $this->assertStringContainsString('/storage/bukti-kas/', (string) $dibayar->json('data.url_bukti_bayar'));
        $this->getJson('/api/pengeluaran-supir/saya?status=dibayar')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/pengeluaran-supir/saya?status=ditolak')->assertJsonPath('meta.total', 1);

        $tipe = DB::table('notifikasi')->where('id_pengguna', $supir['pengguna']->id_pengguna)->pluck('referensi_id', 'tipe');
        $this->assertSame($idDitolak, $tipe['pengeluaran_supir_ditolak'] ?? null);
        $this->assertSame($idDibayar, $tipe['pengeluaran_supir_dibayar'] ?? null);
        $this->assertStringContainsString('Nota tidak terbaca', (string) DB::table('notifikasi')
            ->where('tipe', 'pengeluaran_supir_ditolak')->value('isi'));

        $this->deleteJson("/api/pengeluaran-supir/saya/{$idDibayar}")->assertStatus(409);
        $this->deleteJson("/api/pengeluaran-supir/saya/{$idDitolak}")->assertStatus(200);

        $this->masuk($keuangan);
        $hariIni = now()->toDateString();
        $laporan = $this->getJson("/api/laporan-pengeluaran?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200);
        $this->assertSame(['Cuci Mobil'], array_column($laporan->json('data.per_kategori'), 'label'));
        $this->assertEquals(45000, $laporan->json('data.ringkasan.total_pengeluaran'));
    }

    public function test_pengajuan_supir_tidak_bisa_diubah_dari_arus_kas_dan_rinciannya_tampil(): void
    {
        $keuangan = $this->buatPengguna('SUPERADMIN');
        $approver = $this->buatPengguna('MANAGER');
        $this->aturApproval('pengeluaran_supir', (string) $approver->id_pengguna);

        $idArmada = $this->buatArmada('B 1111 AA');
        $supir    = $this->buatSupir($idArmada);
        $this->masuk($supir['pengguna']);
        $id = (string) $this->kirim($idArmada, ['nama_bank' => 'BRI', 'nomor_rekening' => '555'])->assertStatus(201)->json('data.id_pengeluaran_supir');
        $idPengajuan = $this->idPengajuan($id);

        $this->masuk($keuangan);
        $this->putJson("/api/arus-kas/pengajuan/{$idPengajuan}", [
            'kategori' => 'lainnya', 'nominal' => 999, 'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Orang Lain',
        ])->assertStatus(422);
        $this->deleteJson("/api/arus-kas/pengajuan/{$idPengajuan}")->assertStatus(422);
        $this->assertSame('menunggu_approval', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('status'));

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")->assertStatus(200)
            ->assertJsonPath('data.tipe', 'pengeluaran_supir')
            ->assertJsonPath('data.data.id_pengeluaran_supir', $id)
            ->assertJsonPath('data.data.nama_supir', 'Budi Supir')
            ->assertJsonPath('data.data.nopol', 'B 1111 AA')
            ->assertJsonPath('data.data.nomor_rekening', '555')
            ->assertJsonCount(1, 'data.data.foto');

        $daftar = collect($this->getJson('/api/arus-kas/pengajuan')->assertStatus(200)->json('data'));
        $this->assertSame($id, $daftar->firstWhere('id_pengajuan', $idPengajuan)['id_pengeluaran_supir']);
    }
}
