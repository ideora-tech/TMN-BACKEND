<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(string $nama, ?string $idJabatanInduk = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'       => $id,
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'id_jabatan_induk' => $idJabatanInduk,
            'kode_jabatan'     => 'JBT-' . Str::random(6),
            'nama_jabatan'     => $nama,
            'aktif'            => 1,
            'dibuat_pada'      => now(),
        ]);
        return $id;
    }

    private function makePenggunaDenganJabatan(string $idJabatan, string $nama = 'Test User'): Pengguna
    {
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $idKaryawan,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(8),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'KARYAWAN',
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
    }

    private function makeEventType(string $modeResolusi, string $kode = 'test_dummy'): string
    {
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => $kode,
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => $modeResolusi,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_admin_bisa_buat_dan_daftar_event_type(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/approval-event-type', [
            'kode'          => 'test_dummy',
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => 'pinned',
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.kode', 'test_dummy')
            ->assertJsonPath('data.mode_resolusi', 'pinned');

        $idEventType = $res->json('data.id_event_type');

        $list = $this->getJson('/api/approval-event-type');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json('data'))->contains('id_event_type', $idEventType));
    }

    public function test_admin_bisa_tambah_dan_hapus_config_approver(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned');
        $idJabatan   = $this->makeJabatan('Direktur');

        $res = $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ]);
        $res->assertStatus(201);
        $idConfig = $res->json('data.id_config');

        $this->assertDatabaseHas('approval_config_approver', [
            'id_config'      => $idConfig,
            'id_event_type'  => $idEventType,
            'tipe'           => 'jabatan',
        ]);

        $this->deleteJson("/api/approval-event-type/{$idEventType}/approver/{$idConfig}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('approval_config_approver', [
            'id_config'    => $idConfig,
            'dihapus_pada' => null,
        ]);
    }

    public function test_non_admin_tidak_bisa_buat_event_type(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/approval-event-type', [
            'kode'          => 'test_dummy',
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => 'pinned',
        ])->assertStatus(403);
    }
}
