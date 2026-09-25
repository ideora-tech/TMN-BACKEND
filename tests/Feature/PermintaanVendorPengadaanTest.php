<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use App\Models\Pengguna;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\PermintaanVendor\PermintaanVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanVendorPengadaanTest extends TestCase
{
    use RefreshDatabase;

    private const MENU_PERMINTAAN_VENDOR = 'm0000001-0000-4000-8000-000000000096';

    private function buatPengguna(string $kodePeran): Pengguna
    {
        $this->ensurePerusahaan();
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'username' => strtolower($kodePeran) . '_' . Str::random(6),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function makePermintaan(array $overrides = [], ?string $idPengaju = null): PermintaanVendorModel
    {
        $this->ensurePerusahaan();
        $record = PermintaanVendorModel::create(array_merge([
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'nomor_permintaan' => 'PMV-PGD-' . Str::random(6),
            'jumlah_unit'      => 2,
            'mekanisme'        => 'unit_only',
            'status'           => 'disetujui',
        ], $overrides));

        if ($idPengaju !== null) {
            DB::table('permintaan_vendor')->where('id_permintaan', $record->id_permintaan)->update(['dibuat_oleh' => $idPengaju]);
        }

        return $record->fresh();
    }

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Pengadaan Test',
        ]);
    }

    private function buatKontrakDariPermintaan(PermintaanVendorModel $permintaan, string $nomorKontrak): string
    {
        $vendor = $this->makeVendor();
        return $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => $nomorKontrak,
            'id_permintaan' => $permintaan->id_permintaan,
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
    }

    private function aktifkanApprovalPermintaanVendor(): void
    {
        $approver = $this->buatPengguna('MANAGER');
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'PROCMGR', 'nama_jabatan' => 'Procurement Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Procurement Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $approver->id_pengguna)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'permintaan_vendor', 'nama' => 'Permintaan Vendor', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function notifikasiUntuk(string $idPengguna): array
    {
        return DB::table('notifikasi')
            ->where('id_pengguna', $idPengguna)
            ->where('referensi_tipe', 'permintaan_vendor')
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function test_sales_tidak_boleh_memproses_permintaan(): void
    {
        $permintaan = $this->makePermintaan();
        $this->actingAsRole('SALES');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/proses")->assertStatus(403);
        $this->assertSame('disetujui', $permintaan->fresh()->status);
    }

    public function test_pengadaan_memproses_permintaan_disetujui(): void
    {
        $permintaan = $this->makePermintaan();
        $pengadaan = $this->actingAsRole('PENGADAAN');

        $res = $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/proses");

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'diproses')
            ->assertJsonPath('data.diproses_oleh', $pengadaan->id_pengguna)
            ->assertJsonPath('data.nama_diproses_oleh', $pengadaan->username);
        $this->assertNotNull($res->json('data.diproses_pada'));

        $segar = $permintaan->fresh();
        $this->assertSame('diproses', $segar->status);
        $this->assertSame($pengadaan->id_pengguna, $segar->diproses_oleh);
        $this->assertNotNull($segar->diproses_pada);
    }

    public function test_proses_dari_draft_dan_proses_ulang_422(): void
    {
        $this->actingAsRole('PENGADAAN');
        $draft = $this->makePermintaan(['status' => 'draft']);
        $this->patchJson("/api/permintaan-vendor/{$draft->id_permintaan}/proses")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya permintaan berstatus disetujui yang bisa diproses');

        $disetujui = $this->makePermintaan();
        $this->patchJson("/api/permintaan-vendor/{$disetujui->id_permintaan}/proses")->assertStatus(200);
        $this->patchJson("/api/permintaan-vendor/{$disetujui->id_permintaan}/proses")->assertStatus(422);
    }

    public function test_kontrak_dari_permintaan_diproses_berhasil_dan_pengaju_diberitahu(): void
    {
        $sales = $this->buatPengguna('SALES');
        $permintaan = $this->makePermintaan(['status' => 'diproses'], (string) $sales->id_pengguna);
        $this->actingAsRole('SUPERADMIN');

        $idKontrak = $this->buatKontrakDariPermintaan($permintaan, 'KV-PGD-1');

        $segar = $permintaan->fresh();
        $this->assertSame('dikontrakkan', $segar->status);
        $this->assertSame($idKontrak, $segar->id_kontrak_vendor);

        $notif = $this->notifikasiUntuk((string) $sales->id_pengguna);
        $this->assertCount(1, $notif);
        $this->assertSame("Permintaan vendor {$permintaan->nomor_permintaan} sudah dikontrakkan (KV-PGD-1)", $notif[0]->judul);
        $this->assertSame('/permintaan-vendor/' . $permintaan->id_permintaan, $notif[0]->link);
    }

    public function test_kontrak_dari_permintaan_dibatalkan_422(): void
    {
        $permintaan = $this->makePermintaan(['status' => 'dibatalkan']);
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-PGD-BATAL',
            'id_permintaan' => $permintaan->id_permintaan,
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Hanya permintaan berstatus disetujui atau diproses yang bisa dibuatkan kontrak');

        $this->assertDatabaseMissing('kontrak_vendor', ['nomor_kontrak' => 'KV-PGD-BATAL']);
        $this->assertSame('dibatalkan', $permintaan->fresh()->status);
    }

    public function test_kontrak_aktif_tanpa_event_type_menyelesaikan_permintaan(): void
    {
        $sales = $this->buatPengguna('SALES');
        $permintaan = $this->makePermintaan([], (string) $sales->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $idKontrak = $this->buatKontrakDariPermintaan($permintaan, 'KV-PGD-AKTIF-1');

        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'aktif');

        $this->assertSame('selesai', $permintaan->fresh()->status);

        $judul = array_map(fn ($n) => $n->judul, $this->notifikasiUntuk((string) $sales->id_pengguna));
        $this->assertContains("Permintaan vendor {$permintaan->nomor_permintaan} selesai, kontrak KV-PGD-AKTIF-1 aktif", $judul);
    }

    public function test_kontrak_aktif_lewat_keputusan_approval_menyelesaikan_permintaan(): void
    {
        $permintaan = $this->makePermintaan();
        $this->actingAsRole('SUPERADMIN');
        $idKontrak = $this->buatKontrakDariPermintaan($permintaan, 'KV-PGD-AKTIF-2');
        KontrakVendorModel::where('id_kontrak_vendor', $idKontrak)->update(['status' => 'menunggu_approval']);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $idKontrak,
            'disetujui',
            null,
        ));

        $this->assertSame('aktif', KontrakVendorModel::find($idKontrak)->status);
        $this->assertSame('selesai', $permintaan->fresh()->status);
    }

    public function test_kontrak_ditolak_tidak_mengubah_status_permintaan(): void
    {
        $permintaan = $this->makePermintaan();
        $this->actingAsRole('SUPERADMIN');
        $idKontrak = $this->buatKontrakDariPermintaan($permintaan, 'KV-PGD-TOLAK');
        KontrakVendorModel::where('id_kontrak_vendor', $idKontrak)->update(['status' => 'menunggu_approval']);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $idKontrak,
            'ditolak',
            'Harga belum cocok',
        ));

        $this->assertSame('dikontrakkan', $permintaan->fresh()->status);
    }

    public function test_batal_dari_menunggu_approval_membatalkan_pengajuan(): void
    {
        $this->aktifkanApprovalPermintaanVendor();
        $permintaan = $this->makePermintaan(['status' => 'draft']);
        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval');
        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $permintaan->id_permintaan, 'status' => 'menunggu']);

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/batal", ['alasan' => 'Proyek ditunda klien'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.alasan_batal', 'Proyek ditunda klien');

        $this->assertDatabaseMissing('approval_pengajuan', ['id_referensi' => $permintaan->id_permintaan, 'status' => 'menunggu']);
        $segar = $permintaan->fresh();
        $this->assertSame('dibatalkan', $segar->status);
        $this->assertSame('Proyek ditunda klien', $segar->alasan_batal);
    }

    public function test_batal_dikontrakkan_dan_tanpa_alasan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $dikontrakkan = $this->makePermintaan(['status' => 'dikontrakkan']);
        $this->patchJson("/api/permintaan-vendor/{$dikontrakkan->id_permintaan}/batal", ['alasan' => 'Coba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Permintaan tidak bisa dibatalkan pada status ini');

        $selesai = $this->makePermintaan(['status' => 'selesai']);
        $this->patchJson("/api/permintaan-vendor/{$selesai->id_permintaan}/batal", ['alasan' => 'Coba'])->assertStatus(422);

        $disetujui = $this->makePermintaan();
        $this->patchJson("/api/permintaan-vendor/{$disetujui->id_permintaan}/batal", [])->assertStatus(422);
        $this->assertSame('disetujui', $disetujui->fresh()->status);
    }

    public function test_sales_bisa_membatalkan_tanpa_izin_manual_namun_tetap_tidak_bisa_memproses(): void
    {
        $this->assertDatabaseHas('izin_peran', [
            'id_menu' => self::MENU_PERMINTAAN_VENDOR, 'kode_peran' => 'SALES', 'aksi' => 'ubah', 'diizinkan' => 1, 'id_perusahaan' => null,
        ]);
        $permintaan = $this->makePermintaan(['status' => 'draft']);
        $disetujui = $this->makePermintaan();
        $this->actingAsRole('SALES');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/batal", ['alasan' => 'Salah input unit'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibatalkan');

        $this->patchJson("/api/permintaan-vendor/{$disetujui->id_permintaan}/proses")->assertStatus(403);
        $this->assertSame('disetujui', $disetujui->fresh()->status);
    }

    public function test_batal_dari_status_diproses_berhasil(): void
    {
        $permintaan = $this->makePermintaan(['status' => 'diproses']);
        $this->actingAsRole('SUPERADMIN');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/batal", ['alasan' => 'Vendor tidak sanggup'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibatalkan');
        $this->assertSame('dibatalkan', $permintaan->fresh()->status);
    }

    public function test_proses_terhadap_dikontrakkan_dan_ditolak_422(): void
    {
        $this->actingAsRole('PENGADAAN');

        foreach (['dikontrakkan', 'ditolak'] as $status) {
            $pv = $this->makePermintaan(['status' => $status]);
            $this->patchJson("/api/permintaan-vendor/{$pv->id_permintaan}/proses")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Hanya permintaan berstatus disetujui yang bisa diproses');
            $this->assertSame($status, $pv->fresh()->status);
        }
    }

    public function test_permintaan_selesai_tetap_selesai_saat_kontrak_turun_ke_draft(): void
    {
        $permintaan = $this->makePermintaan();
        $this->actingAsRole('SUPERADMIN');
        $idKontrak = $this->buatKontrakDariPermintaan($permintaan, 'KV-PGD-TURUN');
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200)->assertJsonPath('data.status', 'aktif');
        $this->assertSame('selesai', $permintaan->fresh()->status);

        KontrakVendorModel::where('id_kontrak_vendor', $idKontrak)->update(['status' => 'menunggu_approval']);
        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $idKontrak,
            'ditolak',
            'Revisi komposisi unit',
        ));

        $this->assertSame('draft', KontrakVendorModel::find($idKontrak)->status);
        $this->assertSame('selesai', $permintaan->fresh()->status);
        $this->assertSame($idKontrak, $permintaan->fresh()->id_kontrak_vendor);
    }

    public function test_kontrak_dari_permintaan_dikontrakkan_atau_selesai_pesan_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKontrakLama = (string) Str::uuid();
        foreach (['dikontrakkan', 'selesai'] as $status) {
            $pv = $this->makePermintaan(['status' => $status]);
            DB::table('permintaan_vendor')->where('id_permintaan', $pv->id_permintaan)->update(['id_kontrak_vendor' => $idKontrakLama]);
            $vendor = $this->makeVendor();
            $this->postJson('/api/kontrak-vendor', [
                'id_vendor'     => $vendor->id_vendor,
                'mekanisme'     => 'unit_only',
                'nomor_kontrak' => 'KV-PGD-' . strtoupper($status),
                'id_permintaan' => $pv->id_permintaan,
            ])->assertStatus(422)
              ->assertJsonPath('message', 'Hanya permintaan berstatus disetujui atau diproses yang bisa dibuatkan kontrak');
        }
    }

    public function test_meta_ringkasan_dan_filter_status_koma(): void
    {
        $this->makePermintaan(['status' => 'draft']);
        $this->makePermintaan(['status' => 'disetujui']);
        $this->makePermintaan(['status' => 'disetujui']);
        $this->makePermintaan(['status' => 'diproses']);
        $this->makePermintaan(['status' => 'dikontrakkan']);
        $this->makePermintaan(['status' => 'dibatalkan']);
        $this->actingAsRole('SUPERADMIN');

        $semua = $this->getJson('/api/permintaan-vendor?limit=50')->assertStatus(200);
        $this->assertSame(
            ['dibatalkan' => 1, 'dikontrakkan' => 1, 'diproses' => 1, 'disetujui' => 2, 'draft' => 1],
            collect($semua->json('meta.ringkasan'))->sortKeys()->all(),
        );
        $this->assertSame(6, $semua->json('meta.total'));

        $filter = $this->getJson('/api/permintaan-vendor?status=disetujui,diproses&limit=50')->assertStatus(200);
        $status = collect($filter->json('data'))->pluck('status')->countBy()->all();
        $this->assertSame(['disetujui' => 2, 'diproses' => 1], $status);
        $this->assertSame(3, $filter->json('meta.total'));
        $this->assertSame(6, array_sum($filter->json('meta.ringkasan')));
    }

    public function test_proses_mengirim_notifikasi_ke_pengaju_kecuali_pelaku(): void
    {
        $sales = $this->buatPengguna('SALES');
        $permintaan = $this->makePermintaan([], (string) $sales->id_pengguna);
        $pengadaan = $this->actingAsRole('PENGADAAN');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/proses")->assertStatus(200);

        $notif = $this->notifikasiUntuk((string) $sales->id_pengguna);
        $this->assertCount(1, $notif);
        $this->assertSame("Permintaan vendor {$permintaan->nomor_permintaan} sedang diproses Pengadaan", $notif[0]->judul);
        $this->assertSame('/permintaan-vendor/' . $permintaan->id_permintaan, $notif[0]->link);
        $this->assertCount(0, $this->notifikasiUntuk((string) $pengadaan->id_pengguna));

        $milikSendiri = $this->makePermintaan([], (string) $pengadaan->id_pengguna);
        $this->patchJson("/api/permintaan-vendor/{$milikSendiri->id_permintaan}/proses")->assertStatus(200);
        $this->assertCount(0, $this->notifikasiUntuk((string) $pengadaan->id_pengguna));
    }

    public function test_proses_tanpa_pengaju_tidak_gagal(): void
    {
        $permintaan = $this->makePermintaan();
        $this->assertNull($permintaan->dibuat_oleh);
        $this->actingAsRole('PENGADAAN');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/proses")->assertStatus(200);
        $this->assertSame(0, DB::table('notifikasi')->where('referensi_id', $permintaan->id_permintaan)->count());
    }

    public function test_batal_mengirim_notifikasi_ke_pemilik_izin_kontrak_vendor_kecuali_pelaku(): void
    {
        $timPengadaan = $this->buatPengguna('PENGADAAN');
        $sales = $this->buatPengguna('SALES');
        $permintaan = $this->makePermintaan();
        $pelaku = $this->actingAsRole('SUPERADMIN');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/batal", ['alasan' => 'Klien mundur'])->assertStatus(200);

        $notif = $this->notifikasiUntuk((string) $timPengadaan->id_pengguna);
        $this->assertCount(1, $notif);
        $this->assertSame("Permintaan vendor {$permintaan->nomor_permintaan} dibatalkan", $notif[0]->judul);
        $this->assertSame('Klien mundur', $notif[0]->isi);
        $this->assertCount(0, $this->notifikasiUntuk((string) $pelaku->id_pengguna));
        $this->assertCount(0, $this->notifikasiUntuk((string) $sales->id_pengguna));
    }

    public function test_batal_oleh_pengadaan_tidak_memberitahu_dirinya_sendiri(): void
    {
        $rekan = $this->buatPengguna('PENGADAAN');
        $permintaan = $this->makePermintaan();
        $pelaku = $this->actingAsRole('PENGADAAN');

        $this->patchJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/batal", ['alasan' => 'Duplikat'])->assertStatus(200);

        $this->assertCount(1, $this->notifikasiUntuk((string) $rekan->id_pengguna));
        $this->assertCount(0, $this->notifikasiUntuk((string) $pelaku->id_pengguna));
    }

    public function test_proses_dan_batal_permintaan_tenant_lain_404(): void
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain PGD', 'dibuat_pada' => now()]);
        $lain = $this->makePermintaan(['id_perusahaan' => $idPerusahaanLain]);
        $this->actingAsRole('SUPERADMIN');

        $this->patchJson("/api/permintaan-vendor/{$lain->id_permintaan}/proses")->assertStatus(404);
        $this->patchJson("/api/permintaan-vendor/{$lain->id_permintaan}/batal", ['alasan' => 'Coba'])->assertStatus(404);
        $this->assertSame('disetujui', $lain->fresh()->status);
    }

    public function test_ringkasan_pengadaan_tanpa_izin_403(): void
    {
        $this->actingAsRole('SALES');
        $this->getJson('/api/pengadaan/ringkasan')->assertStatus(403);
    }

    public function test_ringkasan_pengadaan_bentuk_dan_urutan(): void
    {
        $pengaju = $this->buatPengguna('DISPATCHER');
        $this->makePermintaan(['status' => 'draft']);
        $this->makePermintaan(['status' => 'dikontrakkan']);
        for ($i = 1; $i <= 22; $i++) {
            $pv = $this->makePermintaan(['status' => $i % 2 === 0 ? 'disetujui' : 'diproses', 'nomor_permintaan' => sprintf('PMV-URUT-%02d', $i)]);
            DB::table('permintaan_vendor')->where('id_permintaan', $pv->id_permintaan)->update(['dibuat_pada' => now()->subHours(30 - $i)]);
        }
        foreach ([['PR-PGD-1', 'disetujui', 150000.5, '2026-09-20'], ['PR-PGD-2', 'diajukan', 99000, '2026-09-19'], ['PR-PGD-3', 'diproses', 25000, '2026-09-18']] as [$nomor, $status, $total, $tanggal]) {
            DB::table('permintaan_pembelian')->insert([
                'id_permintaan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_permintaan' => $nomor,
                'id_pengaju' => $pengaju->id_pengguna, 'tanggal_permintaan' => $tanggal, 'judul' => 'Judul ' . $nomor,
                'alasan' => 'Kebutuhan operasional', 'status' => $status, 'total_estimasi' => $total, 'dibuat_pada' => now(),
            ]);
        }
        $this->actingAsRole('PENGADAAN');

        $res = $this->getJson('/api/pengadaan/ringkasan')->assertStatus(200);

        $pr = $res->json('data.pr');
        $this->assertSame(['diajukan' => 1, 'diproses' => 1, 'disetujui' => 1], collect($pr['ringkasan'])->sortKeys()->all());
        $this->assertSame(['PR-PGD-3', 'PR-PGD-1'], array_column($pr['menunggu'], 'nomor_permintaan'));
        $this->assertSame($pengaju->username, $pr['menunggu'][0]['username_pengaju']);
        $this->assertSame(150000.5, $pr['menunggu'][1]['total_estimasi']);
        $this->assertSame('umum', $pr['menunggu'][0]['tipe']);
        $this->assertSame('Judul PR-PGD-3', $pr['menunggu'][0]['judul']);

        $pv = $res->json('data.permintaan_vendor');
        $this->assertSame(['dikontrakkan' => 1, 'diproses' => 11, 'disetujui' => 11, 'draft' => 1], collect($pv['ringkasan'])->sortKeys()->all());
        $this->assertCount(20, $pv['menunggu']);
        $this->assertSame(
            array_map(fn ($i) => sprintf('PMV-URUT-%02d', $i), range(1, 20)),
            array_column($pv['menunggu'], 'nomor_permintaan'),
        );
        $this->assertEmpty(array_diff(array_unique(array_column($pv['menunggu'], 'status')), ['disetujui', 'diproses']));
        $this->assertSame(2, $pv['menunggu'][0]['jumlah_unit']);
        $this->assertArrayHasKey('nama_proyek', $pv['menunggu'][0]);
        $this->assertArrayHasKey('periode_dari', $pv['menunggu'][0]);
        $this->assertArrayHasKey('dibuat_pada', $pv['menunggu'][0]);
    }
}
