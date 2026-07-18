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
        $this->actingAsRole('ADMIN');

        $idProyek = $this->makeProyek();
        $milik    = $this->makePenawaran($idProyek);
        $this->makePenawaran(null);
        $this->makePenawaran($this->makeProyek());

        $res = $this->getJson('/api/v1/penawaran?id_proyek=' . $idProyek);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($milik, $data[0]['id_penawaran']);
    }

    public function test_filter_id_proyek_bisa_digabung_dengan_status(): void
    {
        $this->actingAsRole('ADMIN');

        $idProyek = $this->makeProyek();
        $this->makePenawaran($idProyek, 'draft');
        $disetujui = $this->makePenawaran($idProyek, 'disetujui');

        $res = $this->getJson('/api/v1/penawaran?id_proyek=' . $idProyek . '&status=disetujui');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($disetujui, $data[0]['id_penawaran']);
    }

    public function test_tanpa_filter_id_proyek_mengembalikan_semua(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makePenawaran($this->makeProyek());
        $this->makePenawaran(null);

        $res = $this->getJson('/api/v1/penawaran');

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
    }
}
