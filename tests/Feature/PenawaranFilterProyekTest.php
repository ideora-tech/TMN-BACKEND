<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Filter id_proyek pada list penawaran — dipakai dialog Tambah Penugasan
 * untuk mengambil estimasi biaya dari item penawaran proyek. Lihat
 * docs/superpowers/specs/2026-07-17-estimasi-penugasan-otomatis-design.md
 */
class PenawaranFilterProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): string
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Filter Test',
            'dibuat_pada'   => now(),
        ]);

        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek'     => $idProyek,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Filter Test',
            'dibuat_pada'   => now(),
        ]);
        return $idProyek;
    }

    private function makePenawaran(?string $idProyek = null, string $status = 'disetujui'): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Filter Test',
            'status'          => $status,
            'id_proyek'       => $idProyek,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    public function test_filter_id_proyek_hanya_mengembalikan_penawaran_proyek_itu(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idProyek = $this->makeProyek();
        $milik    = $this->makePenawaran($idProyek);
        $this->makePenawaran(null);
        $this->makePenawaran($this->makeProyek());

        $res = $this->getJson('/api/penawaran?id_proyek=' . $idProyek);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($milik, $data[0]['id_penawaran']);
    }

    public function test_filter_id_proyek_bisa_digabung_dengan_status(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idProyek = $this->makeProyek();
        $this->makePenawaran($idProyek, 'draft');
        $disetujui = $this->makePenawaran($idProyek, 'disetujui');

        $res = $this->getJson('/api/penawaran?id_proyek=' . $idProyek . '&status=disetujui');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($disetujui, $data[0]['id_penawaran']);
    }

    public function test_tanpa_filter_id_proyek_mengembalikan_semua(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->makePenawaran($this->makeProyek());
        $this->makePenawaran(null);

        $res = $this->getJson('/api/penawaran');

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_daftar_penawaran_menyertakan_kode_nama_dan_status_proyek_tanpa_menimpa_status_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idProyek = $this->makeProyek();
        DB::table('proyek')->where('id_proyek', $idProyek)->update(['status' => 'aktif']);
        $kode        = DB::table('proyek')->where('id_proyek', $idProyek)->value('kode_proyek');
        $idPenawaran = $this->makePenawaran($idProyek, 'disetujui');

        $res = $this->getJson('/api/penawaran');

        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_penawaran', $idPenawaran);
        $this->assertSame($idProyek, $baris['id_proyek']);
        $this->assertSame($kode, $baris['kode_proyek']);
        $this->assertSame('Proyek Filter Test', $baris['nama_proyek']);
        $this->assertSame('aktif', $baris['proyek_status']);
        $this->assertSame('disetujui', $baris['status']);
    }

    public function test_daftar_penawaran_tanpa_proyek_mengembalikan_data_proyek_null(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPenawaran = $this->makePenawaran(null, 'draft');

        $res = $this->getJson('/api/penawaran');

        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_penawaran', $idPenawaran);
        foreach (['id_proyek', 'kode_proyek', 'nama_proyek', 'proyek_status'] as $kunci) {
            $this->assertArrayHasKey($kunci, $baris);
            $this->assertNull($baris[$kunci], $kunci);
        }
    }

    public function test_detail_penawaran_menyertakan_nama_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idProyek    = $this->makeProyek();
        $idPenawaran = $this->makePenawaran($idProyek, 'disetujui');

        $res = $this->getJson("/api/penawaran/{$idPenawaran}");

        $res->assertStatus(200)->assertJsonPath('data.nama_proyek', 'Proyek Filter Test');
    }
}
