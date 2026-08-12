<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RekonsiliasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeFaktur(string $nomor = 'INV-REKON-1'): string
    {
        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur'     => $idFaktur,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur'  => $nomor,
            'total'         => 1000000,
            'status'        => 'terkirim',
            'dibuat_pada'   => now(),
        ]);
        return $idFaktur;
    }

    public function test_list_dan_detail_menyertakan_nomor_faktur(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idFaktur = $this->makeFaktur();

        $res = $this->postJson('/api/v1/rekonsiliasi', [
            'id_faktur'     => $idFaktur,
            'catatan_klien' => 'Klien hanya mengakui 40 rit',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.nomor_faktur', 'INV-REKON-1');
        $idRekonsiliasi = $res->json('data.id_rekonsiliasi');

        $this->getJson('/api/v1/rekonsiliasi')
            ->assertStatus(200)
            ->assertJsonPath('data.0.nomor_faktur', 'INV-REKON-1');

        $detail = $this->getJson("/api/v1/rekonsiliasi/{$idRekonsiliasi}");
        $detail->assertStatus(200)
            ->assertJsonPath('data.nomor_faktur', 'INV-REKON-1')
            ->assertJsonPath('data.status', 'pending');
        $this->assertNotNull($detail->json('data.dibuat_pada'));
    }

    public function test_tandai_selesai_mencatat_waktu_penyelesaian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idFaktur = $this->makeFaktur('INV-REKON-2');

        $id = $this->postJson('/api/v1/rekonsiliasi', ['id_faktur' => $idFaktur])
            ->assertStatus(201)
            ->json('data.id_rekonsiliasi');

        $res = $this->putJson("/api/v1/rekonsiliasi/{$id}", [
            'catatan_keuangan' => 'Bukti POD lengkap, klien setuju',
            'status'           => 'selesai',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.nomor_faktur', 'INV-REKON-2');
        $this->assertNotNull($res->json('data.diselesaikan_pada'));
    }
}
