<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Siklus hidup status armada mengikuti siklus hidup penugasan internal:
 * create mengunci armada ('digunakan'); delete, atau status berubah jadi
 * selesai/batal, melepaskannya ('tersedia'); reaktivasi menguncinya lagi.
 * Lihat juga ArmadaStatusTest untuk skenario create dasar & PenugasanVendorTest
 * untuk jalur sumber vendor (yang tidak disentuh perubahan ini).
 */
class PenugasanArmadaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $status = 'tersedia'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
            'status'        => $status,
        ]);
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Lifecycle Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Lifecycle Test',
        ]);
    }

    // (a) create mengunci armada; delete melepaskannya
    public function test_create_mengunci_armada_dan_delete_melepaskannya(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);
        $resCreate->assertStatus(201);
        $this->assertSame('digunakan', $armada->fresh()->status);

        $idPenugasan = $resCreate->json('data.id_penugasan');
        $resDelete = $this->deleteJson("/api/v1/penugasan/{$idPenugasan}");
        $resDelete->assertStatus(200);

        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    // (b) update ganti armada A -> B
    public function test_update_ganti_armada_melepaskan_lama_dan_mengunci_baru(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaLama = $this->makeArmada('tersedia');
        $armadaBaru = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armadaLama->id_armada,
        ]);
        $resCreate->assertStatus(201);
        $idPenugasan = $resCreate->json('data.id_penugasan');

        $resUpdate = $this->putJson("/api/v1/penugasan/{$idPenugasan}", [
            'id_armada' => $armadaBaru->id_armada,
        ]);
        $resUpdate->assertStatus(200);

        $this->assertSame('tersedia', $armadaLama->fresh()->status);
        $this->assertSame('digunakan', $armadaBaru->fresh()->status);
    }

    public function test_update_ganti_ke_armada_yang_sedang_digunakan_pihak_lain_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaLama = $this->makeArmada('tersedia');
        $armadaDipakaiPihakLain = $this->makeArmada('digunakan');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armadaLama->id_armada,
        ]);
        $idPenugasan = $resCreate->json('data.id_penugasan');

        $resUpdate = $this->putJson("/api/v1/penugasan/{$idPenugasan}", [
            'id_armada' => $armadaDipakaiPihakLain->id_armada,
        ]);

        $resUpdate->assertStatus(422);
        // Update gagal -> armada lama tidak boleh berubah statusnya.
        $this->assertSame('digunakan', $armadaLama->fresh()->status);
    }

    // (c) status penugasan selesai/batal melepaskan; balik ke aktif mengunci ulang
    public function test_update_status_menjadi_selesai_melepaskan_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ]);
        $idPenugasan = $resCreate->json('data.id_penugasan');
        $this->assertSame('digunakan', $armada->fresh()->status);

        $resUpdate = $this->putJson("/api/v1/penugasan/{$idPenugasan}", [
            'status' => 'selesai',
        ]);
        $resUpdate->assertStatus(200);

        $this->assertSame('tersedia', $armada->fresh()->status);

        // Armada tetap terpasang di kolom id_armada, cuma statusnya dibebaskan.
        $penugasanSetelah = PenugasanModel::find($idPenugasan);
        $this->assertSame($armada->id_armada, $penugasanSetelah->id_armada);
    }

    public function test_update_status_menjadi_batal_melepaskan_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);
        $idPenugasan = $resCreate->json('data.id_penugasan');

        $resUpdate = $this->putJson("/api/v1/penugasan/{$idPenugasan}", [
            'status' => 'batal',
        ]);
        $resUpdate->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_update_status_kembali_aktif_dari_selesai_mengunci_ulang_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);
        $idPenugasan = $resCreate->json('data.id_penugasan');

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'selesai'])
            ->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);

        $resReaktivasi = $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'aktif']);
        $resReaktivasi->assertStatus(200);
        $this->assertSame('digunakan', $armada->fresh()->status);
    }

    public function test_update_status_kembali_aktif_saat_armada_sudah_dipakai_pihak_lain_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);
        $idPenugasan = $resCreate->json('data.id_penugasan');

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'selesai'])
            ->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);

        // Armada dipakai pihak lain sementara penugasan A masih 'selesai'.
        $armada->update(['status' => 'digunakan']);

        $resReaktivasi = $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'aktif']);
        $resReaktivasi->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('tidak tersedia', (string) $resReaktivasi->json('message'));
    }

    // (d) guard dua penugasan pada satu armada + delete tidak membebaskan
    // armada bila masih ada penugasan aktif lain yang memakainya.
    public function test_dua_penugasan_tidak_bisa_memakai_armada_yang_sama(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ])->assertStatus(201);

        $resDuplikat = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);

        $resDuplikat->assertStatus(422);
    }

    public function test_delete_tidak_membebaskan_armada_bila_masih_ada_penugasan_aktif_lain_yang_memakainya(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $resCreate = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);
        $idPenugasanPertama = $resCreate->json('data.id_penugasan');
        $this->assertSame('digunakan', $armada->fresh()->status);

        // Seed manual: penugasan kedua yang (secara teoritis) memakai armada
        // yang sama, melewati guard create() di service — untuk memastikan
        // helper "lepaskan armada" benar-benar memeriksa penugasan aktif
        // lain, bukan cuma andalkan guard create.
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(),
            'id_proyek'    => $proyek->id_proyek,
            'id_armada'    => $armada->id_armada,
            'status'       => 'aktif',
            'sumber'       => 'internal',
            'dibuat_pada'  => now(),
        ]);

        $resDelete = $this->deleteJson("/api/v1/penugasan/{$idPenugasanPertama}");
        $resDelete->assertStatus(200);

        $this->assertSame('digunakan', $armada->fresh()->status);
    }
}
