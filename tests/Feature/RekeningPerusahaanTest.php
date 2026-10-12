<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RekeningPerusahaanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function tambah(array $isi = []): TestResponse
    {
        return $this->postJson('/api/rekening-perusahaan', array_merge([
            'nama_bank' => 'BCA', 'nomor_rekening' => '1234567890', 'atas_nama_rekening' => 'PT TMN Transport',
        ], $isi));
    }

    private function rekeningDiProfil(): array
    {
        $p = DB::table('perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->first(['nama_bank', 'nomor_rekening', 'atas_nama_rekening']);

        return [$p->nama_bank, $p->nomor_rekening, $p->atas_nama_rekening];
    }

    private function lewatiApprovalKasKecil(): void
    {
        DB::table('approval_event_type')->insert([
            'id_event_type' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengisian_kas_kecil', 'nama' => 'Pengisian Kas Kecil', 'mode_resolusi' => 'pinned',
            'aktif' => 0, 'dibuat_pada' => now(),
        ]);
    }

    private function ajukanPengisian(array $isi = []): TestResponse
    {
        return $this->postJson('/api/kas-kecil/pengisian', array_merge([
            'tanggal' => now()->toDateString(), 'nominal' => 2000000, 'keterangan' => 'Isi ulang kas kecil kantor',
        ], $isi));
    }

    public function test_rekening_pertama_otomatis_jadi_utama_dan_tersalin_ke_profil_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $pertama = $this->tambah(['nomor_rekening' => '  1234567890  ', 'atas_nama_rekening' => '  PT TMN Transport ']);
        $pertama->assertStatus(201)
            ->assertJsonPath('data.nama_bank', 'BCA')
            ->assertJsonPath('data.nomor_rekening', '1234567890')
            ->assertJsonPath('data.atas_nama_rekening', 'PT TMN Transport')
            ->assertJsonPath('data.utama', true);
        $this->assertSame(['BCA', '1234567890', 'PT TMN Transport'], $this->rekeningDiProfil());

        $kedua = $this->tambah(['nama_bank' => 'Mandiri', 'nomor_rekening' => '900-00-1', 'atas_nama_rekening' => null]);
        $kedua->assertStatus(201)->assertJsonPath('data.utama', false)->assertJsonPath('data.atas_nama_rekening', null);
        $this->assertSame(['BCA', '1234567890', 'PT TMN Transport'], $this->rekeningDiProfil());

        $this->getJson('/api/rekening-perusahaan')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.nama_bank', 'BCA')
            ->assertJsonPath('data.0.utama', true)
            ->assertJsonPath('data.1.nama_bank', 'Mandiri');
        $this->getJson('/api/rekening-perusahaan?search=900')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama_bank', 'Mandiri');
        $this->getJson('/api/rekening-perusahaan?search[]=x')->assertStatus(200)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/rekening-perusahaan/' . $kedua->json('data.id_rekening_perusahaan'))
            ->assertStatus(200)->assertJsonPath('data.nomor_rekening', '900-00-1');
    }

    public function test_ganti_rekening_utama_memindahkan_tanda_utama_dan_memperbarui_profil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPertama = $this->tambah()->json('data.id_rekening_perusahaan');
        $idKedua   = $this->tambah(['nama_bank' => 'Mandiri', 'nomor_rekening' => '9000001', 'atas_nama_rekening' => 'PT TMN'])->json('data.id_rekening_perusahaan');

        $this->putJson("/api/rekening-perusahaan/{$idPertama}", ['utama' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['utama']);

        $this->putJson("/api/rekening-perusahaan/{$idKedua}", ['utama' => true])
            ->assertStatus(200)->assertJsonPath('data.utama', true)->assertJsonPath('data.nama_bank', 'Mandiri');
        $this->assertSame(1, DB::table('rekening_perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->where('utama', 1)->whereNull('dihapus_pada')->count());
        $this->assertSame(['Mandiri', '9000001', 'PT TMN'], $this->rekeningDiProfil());

        $this->putJson("/api/rekening-perusahaan/{$idKedua}", ['nomor_rekening' => '9000002', 'atas_nama_rekening' => 'PT TMN Transport'])
            ->assertStatus(200)->assertJsonPath('data.utama', true);
        $this->assertSame(['Mandiri', '9000002', 'PT TMN Transport'], $this->rekeningDiProfil());

        $this->putJson("/api/rekening-perusahaan/{$idPertama}", ['nama_bank' => 'BCA KCP Sunter'])
            ->assertStatus(200)->assertJsonPath('data.utama', false);
        $this->assertSame(['Mandiri', '9000002', 'PT TMN Transport'], $this->rekeningDiProfil());
    }

    public function test_hapus_rekening_menjaga_rekening_utama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idUtama = $this->tambah()->json('data.id_rekening_perusahaan');
        $idLain  = $this->tambah(['nama_bank' => 'Mandiri', 'nomor_rekening' => '9000001'])->json('data.id_rekening_perusahaan');

        $this->deleteJson("/api/rekening-perusahaan/{$idUtama}")->assertStatus(422);
        $this->assertNull(DB::table('rekening_perusahaan')->where('id_rekening_perusahaan', $idUtama)->value('dihapus_pada'));

        $this->deleteJson("/api/rekening-perusahaan/{$idLain}")->assertStatus(200);
        $this->assertSame(['BCA', '1234567890', 'PT TMN Transport'], $this->rekeningDiProfil());

        $this->deleteJson("/api/rekening-perusahaan/{$idUtama}")->assertStatus(200);
        $this->assertSame([null, null, null], $this->rekeningDiProfil());
        $this->getJson('/api/rekening-perusahaan')->assertJsonPath('meta.total', 0);

        $this->tambah(['nama_bank' => 'BNI', 'nomor_rekening' => '555'])->assertStatus(201)->assertJsonPath('data.utama', true);
        $this->assertSame(['BNI', '555', 'PT TMN Transport'], $this->rekeningDiProfil());

        DB::table('rekening_perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->update(['utama' => 0]);
        $this->tambah(['nama_bank' => 'BRI', 'nomor_rekening' => '888', 'atas_nama_rekening' => null])
            ->assertStatus(201)->assertJsonPath('data.utama', true);
        $this->assertSame(['BRI', '888', null], $this->rekeningDiProfil());
    }

    public function test_validasi_dan_rekening_kembar(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/rekening-perusahaan', [])->assertStatus(422)->assertJsonValidationErrors(['nama_bank', 'nomor_rekening']);
        $this->tambah(['nomor_rekening' => 'ABC-123'])->assertStatus(422)->assertJsonValidationErrors(['nomor_rekening']);
        $this->tambah(['nama_bank' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors(['nama_bank']);
        $this->tambah(['nama_bank' => '   '])->assertStatus(422)->assertJsonValidationErrors(['nama_bank']);
        $this->assertSame(0, DB::table('rekening_perusahaan')->count());

        $id = $this->tambah()->assertStatus(201)->json('data.id_rekening_perusahaan');
        $this->tambah()->assertStatus(422)->assertJsonValidationErrors(['nomor_rekening']);
        $idLain = $this->tambah(['nama_bank' => 'Mandiri'])->assertStatus(201)->json('data.id_rekening_perusahaan');

        $this->putJson("/api/rekening-perusahaan/{$idLain}", ['nama_bank' => 'BCA'])->assertStatus(422)->assertJsonValidationErrors(['nomor_rekening']);
        $this->putJson("/api/rekening-perusahaan/{$id}", ['nama_bank' => 'BCA', 'nomor_rekening' => '1234567890'])->assertStatus(200);
        $this->putJson("/api/rekening-perusahaan/{$id}", ['nama_bank' => ''])->assertStatus(422)->assertJsonValidationErrors(['nama_bank']);
    }

    public function test_rekening_terisolasi_per_perusahaan_dan_mengikuti_izin_menu(): void
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idLain = (string) Str::uuid();
        DB::table('rekening_perusahaan')->insert([
            'id_rekening_perusahaan' => $idLain, 'id_perusahaan' => $idPerusahaanLain,
            'nama_bank' => 'BRI', 'nomor_rekening' => '777', 'utama' => 1, 'dibuat_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $id = $this->tambah()->assertStatus(201)->json('data.id_rekening_perusahaan');
        $this->tambah(['nama_bank' => 'BRI', 'nomor_rekening' => '777'])->assertStatus(201);

        $this->getJson('/api/rekening-perusahaan')->assertStatus(200)->assertJsonPath('meta.total', 2);
        $this->getJson("/api/rekening-perusahaan/{$idLain}")->assertStatus(404);
        $this->putJson("/api/rekening-perusahaan/{$idLain}", ['utama' => true])->assertStatus(404);
        $this->deleteJson("/api/rekening-perusahaan/{$idLain}")->assertStatus(404);
        $this->assertSame(1, (int) DB::table('rekening_perusahaan')->where('id_rekening_perusahaan', $idLain)->value('utama'));

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/rekening-perusahaan')->assertStatus(200);
        $this->tambah(['nomor_rekening' => '111'])->assertStatus(403);
        $this->putJson("/api/rekening-perusahaan/{$id}", ['nama_bank' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/rekening-perusahaan/{$id}")->assertStatus(403);

        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/rekening-perusahaan')->assertStatus(403);
    }

    public function test_migrasi_menyalin_rekening_profil_lama_ke_master(): void
    {
        DB::table('perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->update([
            'nama_bank' => ' BSI ', 'nomor_rekening' => '7285139591', 'atas_nama_rekening' => 'PT Sulita Logistik Indonesia',
        ]);
        $idTanpaRekening = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idTanpaRekening, 'nama' => 'Tanpa Rekening', 'nama_bank' => 'BCA', 'dibuat_pada' => now()]);

        $migrasi = require database_path('migrations/2026_10_12_400000_create_rekening_perusahaan_table.php');
        $migrasi->up();
        $migrasi->up();

        $baris = DB::table('rekening_perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->get();
        $this->assertCount(1, $baris);
        $this->assertSame('BSI', $baris[0]->nama_bank);
        $this->assertSame('7285139591', $baris[0]->nomor_rekening);
        $this->assertSame(1, (int) $baris[0]->utama);
        $this->assertSame(0, DB::table('rekening_perusahaan')->where('id_perusahaan', $idTanpaRekening)->count());
    }

    public function test_profil_perusahaan_tidak_lagi_bisa_mengubah_rekening_secara_langsung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->tambah()->assertStatus(201);

        $this->putJson('/api/perusahaan/' . self::PERUSAHAAN_ID, ['nama' => 'PT Baru', 'nama_bank' => 'Bank Lain', 'nomor_rekening' => '000'])
            ->assertStatus(200)
            ->assertJsonPath('data.nama', 'PT Baru')
            ->assertJsonPath('data.nama_bank', 'BCA')
            ->assertJsonPath('data.nomor_rekening', '1234567890');
    }

    public function test_pengisian_kas_kecil_memilih_rekening_sumber_dari_master(): void
    {
        $this->lewatiApprovalKasKecil();
        $this->actingAsRole('KEUANGAN');

        $this->ajukanPengisian()->assertStatus(201)
            ->assertJsonPath('data.id_rekening_perusahaan', null)
            ->assertJsonPath('data.nomor_rekening', null);
        $this->getJson('/api/kas-kecil/ringkasan')
            ->assertJsonPath('data.rekening_sumber', null)
            ->assertJsonPath('data.daftar_rekening', []);

        $idUtama = $this->tambah()->json('data.id_rekening_perusahaan');
        $idLain  = $this->tambah(['nama_bank' => 'Mandiri', 'nomor_rekening' => '9000001', 'atas_nama_rekening' => 'PT TMN'])->json('data.id_rekening_perusahaan');

        $this->getJson('/api/kas-kecil/ringkasan')
            ->assertJsonPath('data.rekening_sumber.id_rekening_perusahaan', $idUtama)
            ->assertJsonCount(2, 'data.daftar_rekening')
            ->assertJsonPath('data.daftar_rekening.0.utama', true)
            ->assertJsonPath('data.daftar_rekening.1.id_rekening_perusahaan', $idLain)
            ->assertJsonPath('data.daftar_rekening.1.utama', false);

        $this->ajukanPengisian()->assertStatus(201)
            ->assertJsonPath('data.id_rekening_perusahaan', $idUtama)
            ->assertJsonPath('data.nama_bank', 'BCA')
            ->assertJsonPath('data.nomor_rekening', '1234567890');

        $this->ajukanPengisian(['id_rekening_perusahaan' => $idLain])->assertStatus(201)
            ->assertJsonPath('data.id_rekening_perusahaan', $idLain)
            ->assertJsonPath('data.nama_bank', 'Mandiri')
            ->assertJsonPath('data.nomor_rekening', '9000001')
            ->assertJsonPath('data.atas_nama_rekening', 'PT TMN');

        $this->ajukanPengisian(['id_rekening_perusahaan' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors(['id_rekening_perusahaan']);
        $this->ajukanPengisian(['id_rekening_perusahaan' => 'bukan-uuid'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_rekening_perusahaan']);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idMilikLain = (string) Str::uuid();
        DB::table('rekening_perusahaan')->insert([
            'id_rekening_perusahaan' => $idMilikLain, 'id_perusahaan' => $idPerusahaanLain,
            'nama_bank' => 'BRI', 'nomor_rekening' => '777', 'utama' => 1, 'dibuat_pada' => now(),
        ]);
        $this->ajukanPengisian(['id_rekening_perusahaan' => $idMilikLain])
            ->assertStatus(422)->assertJsonValidationErrors(['id_rekening_perusahaan']);
    }

    public function test_ubah_pengisian_bisa_mengganti_rekening_dan_riwayat_tidak_ikut_berubah(): void
    {
        $approver = \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'SUPERADMIN',
            'username' => 'approver_' . Str::random(8), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengisian_kas_kecil', 'nama' => 'Pengisian Kas Kecil', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $idUtama = $this->tambah()->json('data.id_rekening_perusahaan');
        $idLain  = $this->tambah(['nama_bank' => 'Mandiri', 'nomor_rekening' => '9000001'])->json('data.id_rekening_perusahaan');

        $id = $this->ajukanPengisian()->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval')->json('data.id_pengisian');
        $isi = ['tanggal' => now()->toDateString(), 'nominal' => 2500000, 'keterangan' => 'Isi ulang kas kecil kantor'];

        $this->putJson("/api/kas-kecil/pengisian/{$id}", $isi)
            ->assertStatus(200)
            ->assertJsonPath('data.id_rekening_perusahaan', $idUtama)
            ->assertJsonPath('data.nama_bank', 'BCA');

        $this->putJson("/api/kas-kecil/pengisian/{$id}", $isi + ['id_rekening_perusahaan' => $idLain])
            ->assertStatus(200)
            ->assertJsonPath('data.id_rekening_perusahaan', $idLain)
            ->assertJsonPath('data.nama_bank', 'Mandiri')
            ->assertJsonPath('data.nomor_rekening', '9000001');

        $this->putJson("/api/rekening-perusahaan/{$idLain}", ['nomor_rekening' => '9999999'])->assertStatus(200);
        $this->deleteJson("/api/rekening-perusahaan/{$idLain}")->assertStatus(200);
        $this->getJson("/api/kas-kecil/pengisian/{$id}")
            ->assertJsonPath('data.nama_bank', 'Mandiri')
            ->assertJsonPath('data.nomor_rekening', '9000001');

        $this->putJson("/api/kas-kecil/pengisian/{$id}", $isi + ['id_rekening_perusahaan' => $idLain])
            ->assertStatus(422)->assertJsonValidationErrors(['id_rekening_perusahaan']);
    }
}
