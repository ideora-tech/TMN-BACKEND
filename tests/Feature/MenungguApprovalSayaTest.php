<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenungguApprovalSayaTest extends TestCase
{
    use RefreshDatabase;

    private function buatPengguna(string $username): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'MANAGER',
            'username'      => $username,
            'email'         => $username . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function actingAsPengguna(string $idPengguna): Pengguna
    {
        $pengguna = Pengguna::findOrFail($idPengguna);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function idEventTypePengajuanPengeluaran(): string
    {
        $id = DB::table('approval_event_type')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('kode', 'pengajuan_pengeluaran')->value('id_event_type');
        if ($id !== null) {
            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran',
            'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    /** @return array{0: string, 1: string, 2: string} [idPengajuan, idApprover1, idApprover2] */
    private function siapkanPengajuanMenungguApproval(float $nominal = 500000): array
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('approver_saya_1');
        $idApprover2 = $this->buatPengguna('approver_saya_2');
        $idEventType = $this->idEventTypePengajuanPengeluaran();
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover2, 'dibuat_pada' => now(),
        ]);

        $res = $this->postJson('/api/arus-kas/pengajuan', [
            'kategori'          => 'uang_jalan',
            'nominal'           => $nominal,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Budi Supir',
            'keterangan'        => 'Uang jalan trip',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');
        $idPengajuan = $res->json('data.id_pengajuan');

        return [$idPengajuan, $idApprover1, $idApprover2];
    }

    public function test_antrean_muncul_untuk_approver_dan_kosong_untuk_pengguna_lain(): void
    {
        [$idPengajuan, $idApprover1] = $this->siapkanPengajuanMenungguApproval(750000);

        $this->actingAsPengguna($idApprover1);
        $res = $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya');
        $res->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 1)
            ->assertJsonPath('data.pengajuan.0.id_pengajuan', $idPengajuan)
            ->assertJsonPath('data.pengajuan.0.bisa_approve', true);
        $this->assertEquals(750000, $res->json('data.ringkasan.total_nominal'));

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 0)
            ->assertJsonPath('data.pengajuan', []);
    }

    public function test_antrean_hilang_setelah_setuju_tapi_masih_ada_untuk_approver_lain(): void
    {
        [$idPengajuan, $idApprover1, $idApprover2] = $this->siapkanPengajuanMenungguApproval();

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);

        $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 0);

        $this->actingAsPengguna($idApprover2);
        $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 1);
    }

    public function test_riwayat_pengajuan_bisa_diambil_by_id(): void
    {
        [$idPengajuan, $idApprover1] = $this->siapkanPengajuanMenungguApproval();

        $this->actingAsPengguna($idApprover1);
        $res = $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/riwayat");
        $res->assertStatus(200)
            ->assertJsonPath('data.id_pengajuan', $idPengajuan)
            ->assertJsonPath('data.riwayat.0.status', 'diajukan');
        $this->assertNotNull($res->json('data.riwayat.0.oleh'));
    }

    public function test_antrean_kosong_semua_setelah_ditolak(): void
    {
        [$idPengajuan, $idApprover1, $idApprover2] = $this->siapkanPengajuanMenungguApproval();

        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", [
            'keputusan' => 'tolak',
            'catatan'   => 'Nominal tidak sesuai',
        ])->assertStatus(200);

        $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 0);

        $this->actingAsPengguna($idApprover2);
        $this->getJson('/api/arus-kas/pengajuan/menunggu-approval-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.jumlah', 0);
    }
}
