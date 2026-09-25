<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotifikasiLinkTest extends TestCase
{
    use RefreshDatabase;

    private function buatNotif(string $idPengguna, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifikasi')->insert(array_merge([
            'id_notifikasi'  => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_pengguna'    => $idPengguna,
            'judul'          => 'Uji',
            'isi'            => 'Isi uji',
            'tipe'           => 'info',
            'referensi_id'   => null,
            'referensi_tipe' => null,
            'link'           => null,
            'dibaca'         => 0,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ], $override));
        return $id;
    }

    private function linkDariApi(string $idNotifikasi): ?string
    {
        $data = $this->getJson('/api/notifikasi?limit=50')->assertStatus(200)->json('data');
        foreach ($data as $n) {
            if ($n['id_notifikasi'] === $idNotifikasi) {
                return $n['link'];
            }
        }
        $this->fail("Notifikasi {$idNotifikasi} tidak ada di respons API");
    }

    private function buatEventTypeDenganApprover(string $kode, string $idPenggunaApprover): string
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'JB-' . Str::random(4), 'nama_jabatan' => 'Approver Uji', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Approver Uji', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPenggunaApprover)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => strtoupper($kode), 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        return $idEventType;
    }

    public function test_link_tersimpan_tidak_ditimpa_saat_dibaca_lewat_api(): void
    {
        $saya = $this->actingAsRole('SUPERADMIN');
        $idPermintaan = (string) Str::uuid();
        $id = $this->buatNotif((string) $saya->id_pengguna, [
            'referensi_id' => (string) Str::uuid(), 'referensi_tipe' => 'approval_pengajuan', 'link' => '/permintaan-vendor/' . $idPermintaan,
        ]);

        $this->assertSame('/permintaan-vendor/' . $idPermintaan, $this->linkDariApi($id));
    }

    public function test_notifikasi_approval_lama_tanpa_link_diarahkan_langsung_ke_pengajuannya(): void
    {
        $saya = $this->actingAsRole('SUPERADMIN');
        $idApproval = (string) Str::uuid();
        $id = $this->buatNotif((string) $saya->id_pengguna, [
            'referensi_id' => $idApproval, 'referensi_tipe' => 'approval_pengajuan',
        ]);

        $this->assertSame("/persetujuan-saya?id_approval={$idApproval}", $this->linkDariApi($id));
    }

    public function test_notifikasi_keputusan_lama_tanpa_link_diarahkan_ke_halaman_referensi(): void
    {
        $saya = $this->actingAsRole('SUPERADMIN');
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'penawaran', 'nama' => 'Penawaran', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idApproval = (string) Str::uuid();
        $idReferensi = (string) Str::uuid();
        DB::table('approval_pengajuan')->insert([
            'id_approval' => $idApproval, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_event_type' => $idEventType,
            'id_referensi' => $idReferensi, 'id_pengguna_pengaju' => (string) $saya->id_pengguna,
            'status' => 'disetujui', 'dibuat_pada' => now(),
        ]);
        $id = $this->buatNotif((string) $saya->id_pengguna, [
            'referensi_id' => $idApproval, 'referensi_tipe' => 'approval_keputusan',
        ]);

        $this->assertSame("/penawaran/{$idReferensi}", $this->linkDariApi($id));
    }

    public function test_notifikasi_tanpa_referensi_tetap_tanpa_link(): void
    {
        $saya = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatNotif((string) $saya->id_pengguna);

        $this->assertNull($this->linkDariApi($id));
    }

    public function test_pengajuan_baru_memberi_approver_link_langsung_dan_bisa_disaring_id_approval(): void
    {
        $this->ensurePerusahaan();
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'MANAGER', 'username' => 'apv_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->buatEventTypeDenganApprover('penawaran', (string) $approver->id_pengguna);
        $pengaju = $this->actingAsRole('SUPERADMIN');

        $idReferensi = (string) Str::uuid();
        $pengajuan = app(ApprovalService::class)->ajukan('penawaran', $idReferensi, (string) $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);
        $idApproval = (string) $pengajuan->id_approval;

        Sanctum::actingAs($approver, ['*']);
        $notif = $this->getJson('/api/notifikasi?limit=10')->assertStatus(200)->json('data');
        $this->assertCount(1, $notif);
        $this->assertSame("/persetujuan-saya?id_approval={$idApproval}", $notif[0]['link']);

        $this->getJson("/api/approval-pengajuan/menunggu-saya?id_approval={$idApproval}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_approval', $idApproval);

        $this->getJson('/api/approval-pengajuan/menunggu-saya?id_approval=' . Str::uuid())
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
