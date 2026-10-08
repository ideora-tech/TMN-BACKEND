<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranNotifikasiOperasionalTest extends TestCase
{
    use RefreshDatabase;

    private function beriIzinTambahPenugasan(string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', '/penugasan')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => 'Penugasan', 'path' => '/penugasan', 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->updateOrInsert(
            ['id_perusahaan' => null, 'kode_peran' => $kodePeran, 'id_menu' => $idMenu, 'aksi' => 'tambah'],
            ['id_izin' => (string) Str::uuid(), 'diizinkan' => 1, 'dihapus_pada' => null, 'dibuat_pada' => now()],
        );
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

    private function makeJenis(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_rute' => 'RT-' . Str::random(6),
            'nama_rute' => 'Jakarta - Bandung', 'asal' => 'Jakarta', 'tujuan' => 'Bandung', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenawaran(array $ubah = []): string
    {
        $this->ensurePerusahaan();
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'PT Klien Ops', 'dibuat_pada' => now(),
        ]);

        $id = (string) Str::uuid();
        DB::table('penawaran')->insert(array_merge([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_penawaran' => 'PNW-OPS-1', 'judul' => 'Penawaran Ops', 'status' => 'draft',
            'tipe_harga' => 'per_rit', 'jumlah_hari' => 26, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $ubah));

        return $id;
    }

    private function tambahItem(string $idPenawaran, string $idRute, string $idJenis, ?int $jumlahUnit): void
    {
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_penawaran' => $idPenawaran, 'id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis,
            'harga_satuan' => 1000000, 'estimasi_ritase' => 1, 'subtotal' => 1000000,
            'jumlah_unit' => $jumlahUnit, 'dibuat_pada' => now(),
        ]);
    }

    private function notifikasiUntuk(string $idPengguna): array
    {
        return DB::table('notifikasi')
            ->where('id_pengguna', $idPengguna)
            ->where('tipe', 'penawaran_diajukan')
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function test_penawaran_diajukan_memberi_tahu_operasional_dengan_ringkasan_unit(): void
    {
        $this->beriIzinTambahPenugasan('DISPATCHER');
        $ops      = $this->buatPengguna('DISPATCHER');
        $keuangan = $this->buatPengguna('KEUANGAN');

        $idPenawaran = $this->makePenawaran();
        $idCdd    = $this->makeJenis('CDD');
        $idEngkel = $this->makeJenis('Engkel');
        $idRuteA  = $this->makeRute();
        $idRuteB  = $this->makeRute();
        $this->tambahItem($idPenawaran, $idRuteA, $idCdd, 8);
        $this->tambahItem($idPenawaran, $idRuteB, $idCdd, 2);
        $this->tambahItem($idPenawaran, $idRuteB, $idEngkel, null);

        $pengaju = $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/penawaran/{$idPenawaran}/ajukan-approval")->assertStatus(200);

        $notif = $this->notifikasiUntuk((string) $ops->id_pengguna);
        $this->assertCount(1, $notif);
        $this->assertSame('Penawaran baru PNW-OPS-1 dari Sales', $notif[0]->judul);
        $this->assertSame('Klien PT Klien Ops - CDD 10 unit, Engkel - 2 rute - 26 hari', $notif[0]->isi);
        $this->assertSame('/ketersediaan-vendor', $notif[0]->link);
        $this->assertSame('penawaran', $notif[0]->referensi_tipe);
        $this->assertSame($idPenawaran, $notif[0]->referensi_id);

        $this->assertCount(0, $this->notifikasiUntuk((string) $keuangan->id_pengguna));
        $this->assertCount(0, $this->notifikasiUntuk((string) $pengaju->id_pengguna));
        $this->assertStringNotContainsString('1000000', $notif[0]->isi);
        $this->assertStringNotContainsString('1.000.000', $notif[0]->isi);
    }

    public function test_penawaran_revisi_tidak_memberi_tahu_operasional(): void
    {
        $this->beriIzinTambahPenugasan('DISPATCHER');
        $ops = $this->buatPengguna('DISPATCHER');

        $idInduk  = $this->makePenawaran(['nomor_penawaran' => 'PNW-OPS-INDUK', 'status' => 'disetujui']);
        $idRevisi = $this->makePenawaran(['nomor_penawaran' => 'PNW-OPS-REV', 'id_penawaran_induk' => $idInduk]);
        $this->tambahItem($idRevisi, $this->makeRute(), $this->makeJenis('CDD'), 3);

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/penawaran/{$idRevisi}/ajukan-approval")->assertStatus(200);

        $this->assertCount(0, $this->notifikasiUntuk((string) $ops->id_pengguna));
    }
}
