<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Notifikasi\NotifikasiModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekNotifikasiOperasionalTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN_OPERASIONAL = 'OPS_TEST';
    private const PERAN_PENGAMAT    = 'PENGAMAT_TEST';

    private function setIzin(string $kodePeran, string $aksi, int $diizinkan): void
    {
        $idMenu = DB::table('menu')->where('path', '/penugasan')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => 'penugasan', 'path' => '/penugasan', 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
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

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Test', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function siapkanPenerima(): array
    {
        $this->setIzin(self::PERAN_OPERASIONAL, 'lihat', 1);
        $this->setIzin(self::PERAN_OPERASIONAL, 'tambah', 1);
        $this->setIzin(self::PERAN_PENGAMAT, 'lihat', 1);

        return [
            $this->buatPengguna(self::PERAN_OPERASIONAL),
            $this->buatPengguna(self::PERAN_PENGAMAT),
        ];
    }

    public function test_proyek_baru_aktif_memberi_notifikasi_ke_operasional_saja(): void
    {
        [$operasional, $pengamat] = $this->siapkanPenerima();
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $this->makeKlien(),
            'nama_proyek' => 'Proyek Notif Ops',
            'status'      => 'aktif',
        ]);
        $res->assertStatus(201);

        $notif = NotifikasiModel::where('tipe', 'proyek_aktif')
            ->where('referensi_id', $res->json('data.id_proyek'))
            ->get();

        $this->assertTrue($notif->contains('id_pengguna', $operasional->id_pengguna));
        $this->assertFalse($notif->contains('id_pengguna', $pengamat->id_pengguna));
        $this->assertSame('/penugasan', $notif->first()->link);
    }

    public function test_proyek_draft_tidak_memberi_notifikasi(): void
    {
        [$operasional] = $this->siapkanPenerima();
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/proyek', [
            'id_klien'    => $this->makeKlien(),
            'nama_proyek' => 'Proyek Draft',
            'status'      => 'draft',
        ])->assertStatus(201);

        $this->assertFalse(
            NotifikasiModel::where('tipe', 'proyek_aktif')->where('id_pengguna', $operasional->id_pengguna)->exists()
        );
    }

    public function test_ubah_status_draft_ke_aktif_memberi_notifikasi_sekali(): void
    {
        [$operasional] = $this->siapkanPenerima();
        $this->actingAsRole('SUPERADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-NOTIF-1',
            'nama_proyek'   => 'Proyek Draft Ke Aktif',
            'status'        => 'draft',
        ]);

        $this->patchJson("/api/proyek/{$proyek->id_proyek}/status", ['status' => 'aktif'])->assertOk();
        $this->patchJson("/api/proyek/{$proyek->id_proyek}/status", ['status' => 'aktif'])->assertOk();

        $this->assertSame(
            1,
            NotifikasiModel::where('tipe', 'proyek_aktif')
                ->where('referensi_id', $proyek->id_proyek)
                ->where('id_pengguna', $operasional->id_pengguna)
                ->count()
        );
    }
}
