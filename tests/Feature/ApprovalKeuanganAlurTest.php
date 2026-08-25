<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalKeuanganAlurTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'kategori'          => 'uang_jalan',
            'nominal'           => 500000,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Budi Supir',
            'keterangan'        => 'Uang jalan trip Jakarta-Bandung',
        ], $override);
    }

    private function buatPengajuan(array $override = []): string
    {
        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload($override));
        return $res->json('data.id_pengajuan');
    }

    private function buatJabatan(string $nama = 'Manager Keuangan'): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan'  => 'JBT-' . Str::random(4),
            'nama_jabatan'  => $nama,
            'level'         => 1,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatKaryawan(string $idJabatan, string $nama = 'Budi Karyawan'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(6),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPengguna(string $username, ?string $idKaryawan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'MANAGER',
            'username'      => $username,
            'email'         => $username . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function tambahApproverPengguna(string $idPengguna): void
    {
        $this->postJson('/api/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPengguna,
        ])->assertStatus(201);
    }

    private function tambahApproverJabatan(string $idJabatan): void
    {
        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);
    }

    private function setBatas(float $batas): void
    {
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => $batas])->assertStatus(200);
    }

    private function actingAsPengguna(string $idPengguna): Pengguna
    {
        $pengguna = Pengguna::findOrFail($idPengguna);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function makeSupplierPembelian(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Toko Sparepart Approval',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSparepartPembelian(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_batas_nol_dua_approver_pengguna_menghasilkan_menunggu_approval_dan_notifikasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_satu');
        $idApprover2 = $this->buatPengguna('approver_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);

        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $res = $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek");
        $res->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get();
        $this->assertCount(2, $baris);
        foreach ($baris as $b) {
            $this->assertSame('menunggu', $b->status);
            $this->assertContains($b->id_pengguna, [$idApprover1, $idApprover2]);
        }

        $notif = DB::table('notifikasi')
            ->where('referensi_id', $id)
            ->where('referensi_tipe', 'pengajuan_pengeluaran')
            ->where('tipe', 'approval_keuangan')
            ->get();
        $this->assertCount(2, $notif);
        $idPenerimaNotif = $notif->pluck('id_pengguna')->all();
        $this->assertContains($idApprover1, $idPenerimaNotif);
        $this->assertContains($idApprover2, $idPenerimaNotif);
    }

    public function test_batas_lebih_besar_dari_nominal_langsung_disetujui_tanpa_baris_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(1000000);

        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $res = $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek");
        $res->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->assertSame(0, DB::table('pengajuan_approval')->where('id_pengajuan', $id)->count());
    }

    public function test_tanpa_approver_dengan_nominal_di_atas_batas_dikembalikan_422_dan_status_tetap_diajukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(422);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('diajukan', $row->status);
        $this->assertNull($row->dicek_oleh);
        $this->assertNull($row->dicek_pada);
        $this->assertSame(0, DB::table('pengajuan_approval')->where('id_pengajuan', $id)->count());
    }

    public function test_resolusi_approver_jabatan_menghasilkan_baris_approval_untuk_pengguna_berjabatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan('Manager Keuangan');
        $idKaryawan = $this->buatKaryawan($idJabatan, 'Budi Karyawan');
        $idPengguna = $this->buatPengguna('budi_manager', $idKaryawan);
        $this->tambahApproverJabatan($idJabatan);

        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get();
        $this->assertCount(1, $baris);
        $this->assertSame($idPengguna, $baris->first()->id_pengguna);
        $this->assertSame('menunggu', $baris->first()->status);
    }

    public function test_dedup_pengguna_dari_jabatan_dan_ditunjuk_langsung_hanya_satu_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan('Manager Keuangan');
        $idKaryawan = $this->buatKaryawan($idJabatan, 'Budi Karyawan');
        $idPengguna = $this->buatPengguna('budi_manager', $idKaryawan);
        $this->tambahApproverJabatan($idJabatan);
        $this->tambahApproverPengguna($idPengguna);

        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get();
        $this->assertCount(1, $baris);
        $this->assertSame($idPengguna, $baris->first()->id_pengguna);
    }

    public function test_approve_sebagian_tetap_menunggu_approve_semua_menjadi_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_satu');
        $idApprover2 = $this->buatPengguna('approver_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get()->keyBy('id_pengguna');
        $this->assertSame('disetujui', $baris[$idApprover1]->status);
        $this->assertSame('menunggu', $baris[$idApprover2]->status);

        $this->actingAsPengguna($idApprover2);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.disetujui_oleh', $idApprover2);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('disetujui', $row->status);
        $this->assertSame($idApprover2, $row->disetujui_oleh);
        $this->assertNotNull($row->disetujui_pada);
    }

    public function test_tolak_oleh_approver_wajib_catatan_dan_menolak_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_tolak');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'tolak'])
            ->assertStatus(422);

        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", [
            'keputusan' => 'tolak',
            'catatan'   => 'Nominal terlalu besar',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Nominal terlalu besar');

        $barisApproval = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->first();
        $this->assertSame('ditolak', $barisApproval->status);
        $this->assertSame('Nominal terlalu besar', $barisApproval->catatan);
    }

    public function test_approval_oleh_non_approver_dikembalikan_403(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_valid');
        $idBukanApprover = $this->buatPengguna('bukan_approver');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $this->actingAsPengguna($idBukanApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(403);
    }

    public function test_approve_dua_kali_oleh_approver_yang_sama_dikembalikan_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_ganda_satu');
        $idApprover2 = $this->buatPengguna('approver_ganda_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(409);
    }

    public function test_pengajuan_status_dicek_lama_lazy_snapshot_lalu_diproses_oleh_approver(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_lazy');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update([
            'status'     => 'dicek',
            'dicek_oleh' => $idApprover,
            'dicek_pada' => now(),
        ]);

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get();
        $this->assertCount(1, $baris);
        $this->assertSame('disetujui', $baris->first()->status);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('disetujui', $row->status);
        $this->assertSame($idApprover, $row->disetujui_oleh);
    }

    public function test_pengajuan_status_dicek_lama_di_bawah_batas_otomatis_disetujui_tanpa_proses_keputusan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(1000000);
        $idApprover = $this->buatPengguna('approver_lazy_auto');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'dicek']);

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->assertSame(0, DB::table('pengajuan_approval')->where('id_pengajuan', $id)->count());
    }

    public function test_dicek_lama_snapshot_terbentuk_lalu_non_approver_403_snapshot_tetap_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_snapshot');
        $idBukanApprover = $this->buatPengguna('bukan_approver_snapshot');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'dicek']);

        $this->actingAsPengguna($idBukanApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(403);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('menunggu_approval', $row->status);

        $baris = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->get();
        $this->assertCount(1, $baris);
        $this->assertSame($idApprover, $baris->first()->id_pengguna);
        $this->assertSame('menunggu', $baris->first()->status);
    }

    public function test_transfer_setelah_disetujui_via_approval_berhasil_200(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_transfer');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString()])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');
    }

    public function test_pembelian_sparepart_disetujui_penuh_via_approval_sinkron_disetujui_finance(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_sparepart');
        $this->tambahApproverPengguna($idApprover);

        $res = $this->postJson('/api/pembelian-sparepart', [
            'id_supplier'       => $this->makeSupplierPembelian(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepartPembelian('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        $idPembelian = $res->json('data.id_pembelian');
        $idPengajuan = DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->value('id_pengajuan');

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowPembelian->status);
    }

    public function test_race_approve_setelah_ditolak_approver_lain_dikembalikan_409_dan_pengajuan_tetap_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApproverA = $this->buatPengguna('approver_race_a');
        $idApproverB = $this->buatPengguna('approver_race_b');
        $this->tambahApproverPengguna($idApproverA);
        $this->tambahApproverPengguna($idApproverB);

        $res = $this->postJson('/api/pembelian-sparepart', [
            'id_supplier'       => $this->makeSupplierPembelian(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepartPembelian('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        $idPembelian = $res->json('data.id_pembelian');
        $idPengajuan = DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->value('id_pengajuan');

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        DB::table('pengajuan_approval')->where('id_pengajuan', $idPengajuan)->where('id_pengguna', $idApproverB)
            ->update(['status' => 'ditolak', 'catatan' => 'Ditolak approver B', 'waktu_aksi' => now()]);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update([
            'status'         => 'ditolak',
            'alasan_ditolak' => 'Ditolak approver B',
        ]);

        $this->actingAsPengguna($idApproverA);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(409);

        $rowPengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertSame('ditolak', $rowPengajuan->status);
        $this->assertSame('Ditolak approver B', $rowPengajuan->alasan_ditolak);

        $barisA = DB::table('pengajuan_approval')->where('id_pengajuan', $idPengajuan)->where('id_pengguna', $idApproverA)->first();
        $this->assertSame('menunggu', $barisA->status);

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertNotSame('disetujui_finance', $rowPembelian->status);
    }

    public function test_race_cek_status_berubah_sebelum_transaksi_dikembalikan_409_snapshot_tidak_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_race_cek_satu');
        $idApprover2 = $this->buatPengguna('approver_race_cek_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update([
            'status'     => 'menunggu_approval',
            'dicek_oleh' => $idApprover1,
            'dicek_pada' => now(),
        ]);
        DB::table('pengajuan_approval')->insert([
            ['id_approval' => (string) Str::uuid(), 'id_pengajuan' => $id, 'id_pengguna' => $idApprover1, 'status' => 'menunggu', 'dibuat_pada' => now()],
            ['id_approval' => (string) Str::uuid(), 'id_pengajuan' => $id, 'id_pengguna' => $idApprover2, 'status' => 'menunggu', 'dibuat_pada' => now()],
        ]);
        $jumlahSnapshot = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->count();
        $this->assertSame(2, $jumlahSnapshot);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(409);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('menunggu_approval', $row->status);

        $jumlahSnapshotAkhir = DB::table('pengajuan_approval')->where('id_pengajuan', $id)->count();
        $this->assertSame($jumlahSnapshot, $jumlahSnapshotAkhir);
    }

    private function makeKlienTrip(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Approval Trip',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupirTrip(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function mulaiTripDenganUangJalan(float $alokasi, string $namaSupir = 'Supir Approval Trip'): string
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlienTrip(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Approval Trip',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' AT',
            'merk'          => 'Hino',
        ])->id_armada;

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupirTrip($namaSupir),
            'status'    => 'aktif',
        ]);

        $res = $this->postJson('/api/trip/mulai', [
            'id_penugasan'       => $penugasan->id_penugasan,
            'uang_jalan_alokasi' => $alokasi,
        ]);
        $res->assertStatus(201);

        return (string) $res->json('data.id_trip');
    }

    public function test_show_dan_index_pengajuan_memuat_approval_progress_dan_bisa_approve(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_show_satu');
        $idApprover2 = $this->buatPengguna('approver_show_dua');
        $idBukanApprover = $this->buatPengguna('bukan_approver_show');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval')
            ->assertJsonPath('message', 'Pengajuan menunggu approval');

        $this->actingAsPengguna($idApprover1);
        $this->getJson("/api/arus-kas/pengajuan/{$id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.approval')
            ->assertJsonPath('data.approval_progress.disetujui', 0)
            ->assertJsonPath('data.approval_progress.total', 2)
            ->assertJsonPath('data.bisa_approve', true);

        $this->actingAsPengguna($idBukanApprover);
        $this->getJson("/api/arus-kas/pengajuan/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.bisa_approve', false);

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover2);
        $this->getJson("/api/arus-kas/pengajuan/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.approval_progress.disetujui', 1)
            ->assertJsonPath('data.approval_progress.total', 2)
            ->assertJsonPath('data.bisa_approve', true);

        $resIndex = $this->getJson('/api/arus-kas/pengajuan');
        $resIndex->assertStatus(200);
        $item = collect($resIndex->json('data'))->firstWhere('id_pengajuan', $id);
        $this->assertNotNull($item);
        $this->assertCount(2, $item['approval']);
        $this->assertSame(1, $item['approval_progress']['disetujui']);
        $this->assertSame(2, $item['approval_progress']['total']);
    }

    public function test_trip_pengajuan_menunggu_approval_riwayat_memuat_entri_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_trip_satu');
        $idApprover2 = $this->buatPengguna('approver_trip_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);

        $idTrip = $this->mulaiTripDenganUangJalan(500000);
        $idPengajuan = $this->buatPengajuan(['nominal' => 500000]);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['id_trip' => $idTrip]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson("/api/trip/{$idTrip}");
        $res->assertStatus(200)
            ->assertJsonPath('data.pengajuan_uang_jalan.status', 'menunggu_approval');

        $riwayat = $res->json('data.pengajuan_uang_jalan.riwayat');
        $entriApproval = collect($riwayat)->firstWhere('status', 'disetujui');
        $this->assertNotNull($entriApproval);
        $this->assertSame('approver_trip_satu', $entriApproval['oleh']);
        $this->assertNull($entriApproval['keterangan']);
    }

    public function test_riwayat_trip_urutan_benar_saat_semua_aksi_dalam_detik_yang_sama(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_cepat_satu');
        $idApprover2 = $this->buatPengguna('approver_cepat_dua');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);

        $idTrip = $this->mulaiTripDenganUangJalan(500000);
        $idPengajuan = $this->buatPengajuan(['nominal' => 500000]);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['id_trip' => $idTrip]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->actingAsPengguna($idApprover2);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", ['tanggal_transfer' => now()->toDateString()])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');

        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson("/api/trip/{$idTrip}");
        $res->assertStatus(200)->assertJsonPath('data.pengajuan_uang_jalan.status', 'ditransfer');

        $riwayat = $res->json('data.pengajuan_uang_jalan.riwayat');
        $statuses = array_column($riwayat, 'status');

        $this->assertSame(
            ['diajukan', 'dicek', 'disetujui', 'disetujui', 'disetujui', 'ditransfer'],
            $statuses
        );

        $olehPerOrang = [$riwayat[2]['oleh'], $riwayat[3]['oleh']];
        sort($olehPerOrang);
        $olehPerOrangHarapan = ['approver_cepat_dua', 'approver_cepat_satu'];
        sort($olehPerOrangHarapan);
        $this->assertSame($olehPerOrangHarapan, $olehPerOrang);

        $this->assertSame('approver_cepat_dua', $riwayat[4]['oleh']);
        $this->assertSame('ditransfer', end($statuses));
    }

    public function test_approve_baris_sudah_disetujui_langsung_di_db_dikembalikan_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('approver_double_db');
        $this->tambahApproverPengguna($idApprover);
        $id = $this->buatPengajuan(['nominal' => 500000]);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        DB::table('pengajuan_approval')->where('id_pengajuan', $id)->where('id_pengguna', $idApprover)
            ->update(['status' => 'disetujui', 'waktu_aksi' => now()]);

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(409);

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('menunggu_approval', $row->status);
    }
}
