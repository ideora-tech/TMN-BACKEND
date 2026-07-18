<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(string $nama = 'Shift Pagi', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'       => $id,
            'id_perusahaan'  => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'           => $nama,
            'jam_mulai'      => '08:00',
            'jam_selesai'    => '16:00',
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return DB::table('shift')->where('id_shift', $id)->first();
    }

    public function test_create_shift_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/shift', [
            'nama'         => 'Shift Pagi',
            'jam_mulai'    => '08:00',
            'jam_selesai'  => '16:00',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Shift Pagi')
            ->assertJsonPath('data.aktif', true);

        // SQLite/MySQL TIME storage: value passes through as-is (confirmed "08:00", no
        // trailing seconds added) since ShiftResource does no reformatting. Prefix assert
        // kept for resilience in case a future driver normalizes to "H:i:s".
        $this->assertStringStartsWith('08:00', $res->json('data.jam_mulai'));
        $this->assertStringStartsWith('16:00', $res->json('data.jam_selesai'));

        $this->assertDatabaseHas('shift', [
            'nama'          => 'Shift Pagi',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeShift('Milik Sendiri');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeShift('Milik Orang', $idLain);

        $res = $this->getJson('/api/v1/shift');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Milik Sendiri', $res->json('data.0.nama'));
    }

    public function test_update_dan_show_shift(): void
    {
        $this->actingAsRole('ADMIN');
        $shift = $this->makeShift();

        $resUpdate = $this->putJson("/api/v1/shift/{$shift->id_shift}", [
            'nama'        => 'Shift Malam',
            'jam_selesai' => '04:00',
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.nama', 'Shift Malam');
        $this->assertStringStartsWith('04:00', $resUpdate->json('data.jam_selesai'));

        $resShow = $this->getJson("/api/v1/shift/{$shift->id_shift}");
        $resShow->assertStatus(200)->assertJsonPath('data.nama', 'Shift Malam');
    }

    public function test_delete_shift_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $shift = $this->makeShift();

        $res = $this->deleteJson("/api/v1/shift/{$shift->id_shift}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('shift', ['id_shift' => $shift->id_shift]);
        $this->getJson("/api/v1/shift/{$shift->id_shift}")->assertStatus(404);
    }
}
