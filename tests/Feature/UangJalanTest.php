<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UangJalanTest extends TestCase
{
    use RefreshDatabase;

    private ?array $master = null;

    private function master(): array
    {
        if ($this->master !== null) {
            return $this->master;
        }

        $this->ensurePerusahaan();

        $m = [
            'vendor'       => (string) Str::uuid(),
            'supir_vendor' => (string) Str::uuid(),
            'armada_vendor' => (string) Str::uuid(),
            'supir'        => (string) Str::uuid(),
            'armada'       => (string) Str::uuid(),
            'rute'         => (string) Str::uuid(),
        ];

        DB::table('vendor')->insert([
            'id_vendor' => $m['vendor'], 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(6), 'nama_vendor' => 'PT. Bayu Chandra Logistik',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $m['supir_vendor'], 'id_vendor' => $m['vendor'],
            'nama' => 'Al Wanton', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $m['armada_vendor'], 'id_vendor' => $m['vendor'],
            'nopol' => 'B 9550 FXY', 'merk' => 'Hino', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('supir')->insert([
            'id_supir' => $m['supir'], 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Budi Santoso', 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        DB::table('armada')->insert([
            'id_armada' => $m['armada'], 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B 1111 AAA', 'merk' => 'Hino', 'dibuat_pada' => now(),
        ]);
        DB::table('rute')->insert([
            'id_rute' => $m['rute'], 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'STR - BTS20 - STR', 'dibuat_pada' => now(),
        ]);

        return $this->master = $m;
    }

    private function buatProyek(string $idRute): array
    {
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Logistik STR',
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'id_jenis_kendaraan' => (string) Str::uuid(),
            'dibuat_pada' => now(),
        ]);

        return ['id_proyek' => $idProyek];
    }

    private function buatPenugasan(string $idProyek, array $override = []): string
    {
        $m = $this->master();
        $data = array_merge([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $idProyek,
            'id_armada' => $m['armada'], 'id_supir' => $m['supir'], 'id_rute' => $m['rute'],
            'tanggal_tugas' => '2026-10-02', 'status' => 'aktif', 'sumber' => 'internal',
            'dibuat_pada' => now(),
        ], $override);
        DB::table('penugasan')->insert($data);

        return (string) $data['id_penugasan'];
    }

    private function payload(array $override = []): array
    {
        $m = $this->master();

        return array_merge([
            'tanggal'             => '2026-10-02',
            'tipe_driver'         => 'vendor',
            'id_vendor'           => $m['vendor'],
            'id_supir_vendor'     => $m['supir_vendor'],
            'id_armada_vendor'    => $m['armada_vendor'],
            'id_rute'             => $m['rute'],
            'tol_per_trip'        => 230000,
            'bbm_per_trip'        => 0,
            'biaya_lain_per_trip' => 0,
            'jumlah_trip'         => 2,
            'nomor_rekening'      => '1630009820674',
            'nama_bank'           => 'Mandiri',
            'catatan'             => 'UJ untuk 2 Trip.',
        ], $override);
    }

    private function payloadInternal(array $override = []): array
    {
        $m = $this->master();

        return $this->payload(array_merge([
            'tipe_driver'      => 'internal',
            'id_supir'         => $m['supir'],
            'id_armada'        => $m['armada'],
            'id_vendor'        => null,
            'id_supir_vendor'  => null,
            'id_armada_vendor' => null,
        ], $override));
    }

    private function buatApprover(): Pengguna
    {
        $this->ensurePerusahaan();

        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPERADMIN',
            'username'      => 'approver_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
    }

    private function aturApproval(string $kode, string $idApprover, int $aktif = 1): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => ucfirst($kode), 'mode_resolusi' => 'pinned',
            'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    private function buatUangJalan(array $payload = []): string
    {
        $res = $this->postJson('/api/uang-jalan', $payload ?: $this->payload());
        $res->assertStatus(201);

        return (string) $res->json('data.id_uang_jalan');
    }

    public function test_simpan_menghitung_nominal_dan_membuat_pengajuan_menunggu_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $res = $this->postJson('/api/uang-jalan', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.nominal', 460000)
            ->assertJsonPath('data.uang_jalan_per_trip', 230000)
            ->assertJsonPath('data.jumlah_trip', 2)
            ->assertJsonPath('data.nama_driver', 'Al Wanton')
            ->assertJsonPath('data.nama_vendor', 'PT. Bayu Chandra Logistik')
            ->assertJsonPath('data.nopol', 'B 9550 FXY')
            ->assertJsonPath('data.rute', 'STR - BTS20 - STR')
            ->assertJsonPath('data.id_supir_vendor', $m['supir_vendor'])
            ->assertJsonPath('data.id_armada_vendor', $m['armada_vendor'])
            ->assertJsonPath('data.id_vendor', $m['vendor'])
            ->assertJsonPath('data.id_rute', $m['rute'])
            ->assertJsonPath('data.status_pengajuan', 'menunggu_approval')
            ->assertJsonPath('data.bisa_diubah', true);

        $this->assertMatchesRegularExpression('/^UJ-\d{6}-0001$/', $res->json('data.nomor_uang_jalan'));

        $idPengajuan = $res->json('data.id_pengajuan');
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'  => $idPengajuan,
            'id_uang_jalan' => $res->json('data.id_uang_jalan'),
            'kategori'      => 'uang_jalan',
            'nominal'       => 460000,
            'penerima'      => 'Al Wanton (PT. Bayu Chandra Logistik)',
            'status'        => 'menunggu_approval',
        ]);
        $this->assertSame(1, DB::table('approval_pengajuan')->where('id_referensi', $idPengajuan)->count());
        $this->assertSame(1, DB::table('approval_keputusan as ak')
            ->join('approval_pengajuan as ap', 'ap.id_approval', '=', 'ak.id_approval')
            ->where('ap.id_referensi', $idPengajuan)
            ->where('ak.id_pengguna', $approver->id_pengguna)
            ->count());
    }

    public function test_nomor_uang_jalan_berurutan(): void
    {
        $this->actingAsRole('DISPATCHER');

        $pertama = $this->postJson('/api/uang-jalan', $this->payload())->json('data.nomor_uang_jalan');
        $kedua   = $this->postJson('/api/uang-jalan', $this->payload())->json('data.nomor_uang_jalan');

        $this->assertStringEndsWith('-0001', $pertama);
        $this->assertStringEndsWith('-0002', $kedua);
    }

    public function test_driver_internal_memakai_master_supir_dan_unit(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $res = $this->postJson('/api/uang-jalan', $this->payloadInternal());

        $res->assertStatus(201)
            ->assertJsonPath('data.tipe_driver', 'internal')
            ->assertJsonPath('data.nama_driver', 'Budi Santoso')
            ->assertJsonPath('data.nopol', 'B 1111 AAA')
            ->assertJsonPath('data.nama_vendor', null)
            ->assertJsonPath('data.id_supir', $m['supir'])
            ->assertJsonPath('data.id_armada', $m['armada'])
            ->assertJsonPath('data.id_vendor', null)
            ->assertJsonPath('data.id_supir_vendor', null);
        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_uang_jalan' => $res->json('data.id_uang_jalan'), 'penerima' => 'Budi Santoso']);
    }

    public function test_id_cabang_lain_diabaikan_dan_dikosongkan(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $res = $this->postJson('/api/uang-jalan', $this->payloadInternal(['id_vendor' => $m['vendor'], 'id_supir_vendor' => $m['supir_vendor']]));

        $res->assertStatus(201)->assertJsonPath('data.id_vendor', null)->assertJsonPath('data.id_supir_vendor', null);
    }

    public function test_driver_vendor_wajib_vendor_driver_dan_unit(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/uang-jalan', $this->payload(['id_vendor' => null, 'id_supir_vendor' => null, 'id_armada_vendor' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_vendor', 'id_supir_vendor', 'id_armada_vendor']);

        $this->postJson('/api/uang-jalan', $this->payloadInternal(['id_supir' => null, 'id_armada' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_supir', 'id_armada']);
    }

    public function test_master_milik_perusahaan_atau_vendor_lain_ditolak(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $supirLain = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $supirLain, 'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Supir Lain',
            'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $ruteLain = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $ruteLain, 'id_perusahaan' => $idPerusahaanLain, 'kode_rute' => 'RT-' . Str::random(6),
            'nama_rute' => 'Rute Lain', 'dibuat_pada' => now(),
        ]);
        $vendorLain = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $vendorLain, 'id_perusahaan' => $idPerusahaanLain, 'kode_vendor' => 'VDR-' . Str::random(6),
            'nama_vendor' => 'Vendor Lain', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $supirVendorLain = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $supirVendorLain, 'id_vendor' => $vendorLain, 'nama' => 'Driver Vendor Lain',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/uang-jalan', $this->payloadInternal(['id_supir' => $supirLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_supir']);
        $this->postJson('/api/uang-jalan', $this->payload(['id_rute' => $ruteLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_rute']);
        $this->postJson('/api/uang-jalan', $this->payload(['id_vendor' => $vendorLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_vendor']);
        $this->postJson('/api/uang-jalan', $this->payload(['id_supir_vendor' => $supirVendorLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_supir_vendor']);
        $this->postJson('/api/uang-jalan', $this->payloadInternal(['id_armada' => $m['armada_vendor']]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_armada']);

        $this->assertSame(0, DB::table('uang_jalan')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_uang_jalan_per_trip_dihitung_dari_rincian_tol_bbm_dan_biaya_lain(): void
    {
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/uang-jalan', $this->payload([
            'tol_per_trip' => 100000, 'bbm_per_trip' => 50000, 'biaya_lain_per_trip' => 30000, 'jumlah_trip' => 2,
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.tol_per_trip', 100000)
            ->assertJsonPath('data.bbm_per_trip', 50000)
            ->assertJsonPath('data.biaya_lain_per_trip', 30000)
            ->assertJsonPath('data.uang_jalan_per_trip', 180000)
            ->assertJsonPath('data.nominal', 360000);
    }

    public function test_uang_jalan_per_trip_dari_klien_diabaikan_dan_dihitung_ulang_server(): void
    {
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/uang-jalan', $this->payload([
            'uang_jalan_per_trip' => 999999, 'tol_per_trip' => 10000, 'bbm_per_trip' => 0, 'biaya_lain_per_trip' => 0,
        ]));

        $res->assertStatus(201)->assertJsonPath('data.uang_jalan_per_trip', 10000);
    }

    public function test_validasi_field_wajib_dan_batas_angka(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/uang-jalan', [])->assertStatus(422)->assertJsonValidationErrors([
            'tanggal', 'tipe_driver', 'id_rute',
            'jumlah_trip', 'nomor_rekening', 'nama_bank',
        ]);

        $this->postJson('/api/uang-jalan', $this->payload(['tol_per_trip' => 0, 'bbm_per_trip' => 0, 'biaya_lain_per_trip' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['tol_per_trip']);
        $this->postJson('/api/uang-jalan', $this->payload(['jumlah_trip' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['jumlah_trip']);
        $this->postJson('/api/uang-jalan', $this->payload(['tipe_driver' => 'lainnya']))
            ->assertStatus(422)->assertJsonValidationErrors(['tipe_driver']);

        $this->assertSame(0, DB::table('uang_jalan')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_approval_nonaktif_pengajuan_langsung_disetujui(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna, 0);
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/uang-jalan', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.status_pengajuan', 'disetujui')
            ->assertJsonPath('data.bisa_diubah', false);
        $this->assertSame(0, DB::table('approval_pengajuan')->where('id_referensi', $res->json('data.id_pengajuan'))->count());
    }

    public function test_disetujui_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingAsRole('DISPATCHER');
        $id = $this->buatUangJalan();

        $this->putJson("/api/uang-jalan/{$id}", $this->payload())->assertStatus(409);
        $this->deleteJson("/api/uang-jalan/{$id}")->assertStatus(409);

        $this->assertNull(DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('dihapus_pada'));
    }

    public function test_ubah_saat_menunggu_approval_memperbarui_nominal_pengajuan_dan_mengulang_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('DISPATCHER');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');

        $res = $this->putJson("/api/uang-jalan/{$id}", $this->payloadInternal([
            'tol_per_trip' => 250000, 'bbm_per_trip' => 0, 'biaya_lain_per_trip' => 0,
            'jumlah_trip' => 3, 'nomor_rekening' => '9999', 'nama_bank' => 'BCA',
        ]));

        $res->assertStatus(200)
            ->assertJsonPath('data.nominal', 750000)
            ->assertJsonPath('data.nama_driver', 'Budi Santoso')
            ->assertJsonPath('data.nomor_rekening', '9999')
            ->assertJsonPath('data.status_pengajuan', 'menunggu_approval');
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan, 'nominal' => 750000, 'penerima' => 'Budi Santoso', 'status' => 'menunggu_approval',
        ]);
        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
    }

    public function test_ditolak_bisa_diperbaiki_lalu_diajukan_ulang(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $pembuat = $this->actingAsRole('DISPATCHER');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');

        Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'tolak', 'catatan' => 'Rekening salah'])
            ->assertStatus(200);

        Sanctum::actingAs($pembuat, ['*']);
        $this->getJson("/api/uang-jalan/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status_pengajuan', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Rekening salah')
            ->assertJsonPath('data.bisa_diubah', true);

        $this->putJson("/api/uang-jalan/{$id}", $this->payload(['nomor_rekening' => '1112223334']))
            ->assertStatus(200)
            ->assertJsonPath('data.status_pengajuan', 'menunggu_approval')
            ->assertJsonPath('data.alasan_ditolak', null);

        $this->assertNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('alasan_ditolak'));
        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'ditolak')->count());
    }

    public function test_nomor_rekening_dan_format_tanggal_divalidasi(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/uang-jalan', $this->payload(['nomor_rekening' => 'ABC-123']))
            ->assertStatus(422)->assertJsonValidationErrors(['nomor_rekening']);
        $this->postJson('/api/uang-jalan', $this->payload(['tanggal' => 'tomorrow']))
            ->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->postJson('/api/uang-jalan', $this->payload(['nomor_rekening' => '163-0009 8206.74']))
            ->assertStatus(201);
    }

    public function test_parameter_query_berbentuk_array_tidak_menyebabkan_error(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatUangJalan();

        $this->getJson('/api/uang-jalan?search[]=x&status[]=y')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_hapus_saat_menunggu_approval_menghapus_uang_jalan_pengajuan_dan_approval(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('DISPATCHER');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');

        $this->deleteJson("/api/uang-jalan/{$id}")->assertStatus(200);

        $this->assertNotNull(DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('dihapus_pada'));
        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));
        $this->assertSame(0, DB::table('approval_pengajuan')
            ->where('id_referensi', $idPengajuan)->where('status', 'menunggu')->whereNull('dihapus_pada')->count());
        $this->getJson("/api/uang-jalan/{$id}")->assertStatus(404);
    }

    public function test_pengajuan_uang_jalan_tidak_bisa_diubah_atau_dihapus_dari_proses_pembayaran(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');

        $this->putJson("/api/arus-kas/pengajuan/{$idPengajuan}", ['nominal' => 1])->assertStatus(422);
        $this->deleteJson("/api/arus-kas/pengajuan/{$idPengajuan}")->assertStatus(422);

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan, 'nominal' => 460000]);
    }

    public function test_rincian_sumber_pengajuan_menampilkan_data_uang_jalan_dan_rekening(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');

        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'uang_jalan_manual')
            ->assertJsonPath('data.data.nama_driver', 'Al Wanton')
            ->assertJsonPath('data.data.nama_vendor', 'PT. Bayu Chandra Logistik')
            ->assertJsonPath('data.data.nomor_rekening', '1630009820674')
            ->assertJsonPath('data.data.nama_bank', 'Mandiri')
            ->assertJsonPath('data.data.nominal', 460000);
    }

    public function test_approver_melihat_rincian_uang_jalan_di_persetujuan(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('DISPATCHER');
        $id = $this->buatUangJalan();
        $idPengajuan = (string) DB::table('uang_jalan')->where('id_uang_jalan', $id)->value('id_pengajuan');
        $idApproval  = (string) DB::table('approval_pengajuan')->where('id_referensi', $idPengajuan)->value('id_approval');

        Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson("/api/approval-pengajuan/{$idApproval}/rincian");

        $res->assertStatus(200)->assertJsonPath('data.rincian.kode', 'uang_jalan');

        $info = collect($res->json('data.rincian.info'));
        $this->assertSame('Al Wanton', $info->firstWhere('label', 'Nama Driver')['value']);
        $this->assertSame('Driver Vendor PT. Bayu Chandra Logistik', $info->firstWhere('label', 'Status')['value']);
        $this->assertSame('B 9550 FXY', $info->firstWhere('label', 'No. Polisi')['value']);
        $this->assertSame('STR - BTS20 - STR', $info->firstWhere('label', 'Rute')['value']);
        $this->assertSame('1630009820674', $info->firstWhere('label', 'Nomor Rekening')['value']);
        $this->assertSame('Mandiri', $info->firstWhere('label', 'Bank')['value']);
    }

    public function test_daftar_menyaring_status_dan_pencarian_serta_paginasi(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $this->buatUangJalan($this->payloadInternal());
        $this->buatUangJalan($this->payload());

        $this->getJson('/api/uang-jalan')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.page', 1);

        $this->getJson('/api/uang-jalan?search=Al Wanton')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama_driver', 'Al Wanton');
        $this->getJson('/api/uang-jalan?search=1111')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama_driver', 'Budi Santoso');
        $this->getJson('/api/uang-jalan?status=menunggu_approval')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/uang-jalan?status=ditransfer')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/uang-jalan?limit=1&page=2')->assertJsonPath('meta.totalPages', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/uang-jalan?dari=2026-11-01')->assertJsonPath('meta.total', 0);
    }

    public function test_riwayat_menampilkan_info_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatUangJalan();

        $this->getJson("/api/uang-jalan/{$id}/riwayat")
            ->assertStatus(200)
            ->assertJsonPath('data.kategori', 'uang_jalan')
            ->assertJsonPath('data.nominal', 460000)
            ->assertJsonPath('data.riwayat.0.status', 'diajukan');
    }

    public function test_opsi_internal_memuat_master_dan_rekening_karyawan(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => 'K-' . Str::random(6),
            'nama_karyawan' => 'Budi Karyawan', 'nama_bank' => 'BCA', 'nomor_rekening' => '5550001111', 'dibuat_pada' => now(),
        ]);
        DB::table('supir')->where('id_supir', $m['supir'])->update(['id_karyawan' => $idKaryawan]);

        $res = $this->getJson('/api/uang-jalan/opsi')->assertStatus(200);

        $res->assertJsonPath('data.supir.0.id_supir', $m['supir'])
            ->assertJsonPath('data.supir.0.nama', 'Budi Santoso')
            ->assertJsonPath('data.supir.0.nama_bank', 'BCA')
            ->assertJsonPath('data.supir.0.nomor_rekening', '5550001111')
            ->assertJsonPath('data.armada.0.nopol', 'B 1111 AAA')
            ->assertJsonPath('data.vendor.0.nama_vendor', 'PT. Bayu Chandra Logistik')
            ->assertJsonPath('data.rute.0.nama_rute', 'STR - BTS20 - STR');
    }

    public function test_opsi_menyertakan_pemegang_unit_untuk_isi_driver_otomatis(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $this->getJson('/api/uang-jalan/opsi')->assertStatus(200)
            ->assertJsonPath('data.supir.0.id_armada_default', null);
        $this->getJson("/api/uang-jalan/opsi/vendor/{$m['vendor']}")->assertStatus(200)
            ->assertJsonPath('data.armada_vendor.0.id_supir_vendor_default', null);

        DB::table('supir')->where('id_supir', $m['supir'])->update(['id_armada_default' => $m['armada']]);
        DB::table('armada_vendor')->where('id_armada_vendor', $m['armada_vendor'])
            ->update(['id_supir_vendor_default' => $m['supir_vendor']]);

        $this->getJson('/api/uang-jalan/opsi')->assertStatus(200)
            ->assertJsonPath('data.supir.0.id_armada_default', $m['armada']);
        $this->getJson("/api/uang-jalan/opsi/vendor/{$m['vendor']}")->assertStatus(200)
            ->assertJsonPath('data.armada_vendor.0.id_supir_vendor_default', $m['supir_vendor']);
    }

    public function test_opsi_vendor_memuat_driver_unit_dan_rekening_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        DB::table('rekening_vendor')->insert([
            'id_rekening_vendor' => (string) Str::uuid(), 'id_vendor' => $m['vendor'],
            'nama_bank' => 'Mandiri', 'nomor_rekening' => '1630009820674', 'atas_nama' => 'PT. Bayu Chandra Logistik',
            'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/uang-jalan/opsi/vendor/{$m['vendor']}")
            ->assertStatus(200)
            ->assertJsonPath('data.supir_vendor.0.id_supir_vendor', $m['supir_vendor'])
            ->assertJsonPath('data.armada_vendor.0.nopol', 'B 9550 FXY')
            ->assertJsonPath('data.rekening.0.nomor_rekening', '1630009820674');
    }

    public function test_opsi_vendor_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('DISPATCHER');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $vendorLain = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $vendorLain, 'id_perusahaan' => $idPerusahaanLain, 'kode_vendor' => 'VDR-' . Str::random(6),
            'nama_vendor' => 'Vendor Lain', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/uang-jalan/opsi/vendor/{$vendorLain}")->assertStatus(404);
    }

    public function test_data_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idLain = (string) Str::uuid();
        DB::table('uang_jalan')->insert([
            'id_uang_jalan' => $idLain, 'id_perusahaan' => $idPerusahaanLain, 'nomor_uang_jalan' => 'UJ-202610-0001',
            'tanggal' => '2026-10-02', 'nama_driver' => 'Orang Lain', 'tipe_driver' => 'internal', 'nopol' => 'B 1 X',
            'rute' => 'A - B', 'uang_jalan_per_trip' => 100000, 'jumlah_trip' => 1, 'nominal' => 100000,
            'nomor_rekening' => '123', 'nama_bank' => 'BRI', 'dibuat_pada' => now(),
        ]);

        $this->getJson('/api/uang-jalan')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson("/api/uang-jalan/{$idLain}")->assertStatus(404);
        $this->getJson("/api/uang-jalan/{$idLain}/riwayat")->assertStatus(404);
        $this->putJson("/api/uang-jalan/{$idLain}", $this->payload())->assertStatus(404);
        $this->deleteJson("/api/uang-jalan/{$idLain}")->assertStatus(404);
    }

    public function test_izin_menu_dispatcher_boleh_sales_ditolak(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/uang-jalan')->assertStatus(200);
        $this->getJson('/api/uang-jalan/opsi')->assertStatus(200);
        $this->postJson('/api/uang-jalan', $this->payload())->assertStatus(201);

        $this->actingAsRole('SALES');
        $this->getJson('/api/uang-jalan')->assertStatus(403);
        $this->getJson('/api/uang-jalan/opsi')->assertStatus(403);
        $this->postJson('/api/uang-jalan', $this->payload())->assertStatus(403);
    }

    public function test_proyek_opsional_tidak_mengganggu_simpan_tanpa_proyek(): void
    {
        $this->actingAsRole('DISPATCHER');

        $res = $this->postJson('/api/uang-jalan', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.id_proyek', null)
            ->assertJsonPath('data.id_penugasan', null);
    }

    public function test_simpan_dengan_proyek_menyimpan_snapshot_kode_dan_nama_proyek(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);

        $res = $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyek['id_proyek']]));

        $res->assertStatus(201)
            ->assertJsonPath('data.id_proyek', $proyek['id_proyek'])
            ->assertJsonPath('data.nama_proyek', 'Proyek Logistik STR');
        $this->assertDatabaseHas('uang_jalan', [
            'id_uang_jalan' => $res->json('data.id_uang_jalan'),
            'id_proyek'     => $proyek['id_proyek'],
            'kode_proyek'   => $res->json('data.kode_proyek'),
        ]);
    }

    public function test_rute_dibatasi_ke_rute_yang_terdaftar_pada_proyek(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);

        $ruteLuarProyek = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $ruteLuarProyek, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Tidak Dikontrak Proyek', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyek['id_proyek'], 'id_rute' => $ruteLuarProyek]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_rute']);

        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyek['id_proyek']]))
            ->assertStatus(201);
    }

    public function test_penugasan_wajib_milik_proyek_yang_sama_dan_proyek_wajib_diisi(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyekA = $this->buatProyek($m['rute']);
        $proyekB = $this->buatProyek($m['rute']);
        $penugasanDiProyekA = $this->buatPenugasan($proyekA['id_proyek']);

        $this->postJson('/api/uang-jalan', $this->payload(['id_penugasan' => $penugasanDiProyekA]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_proyek']);

        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyekB['id_proyek'], 'id_penugasan' => $penugasanDiProyekA]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_penugasan']);

        $res = $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyekA['id_proyek'], 'id_penugasan' => $penugasanDiProyekA]));
        $res->assertStatus(201)
            ->assertJsonPath('data.id_proyek', $proyekA['id_proyek'])
            ->assertJsonPath('data.id_penugasan', $penugasanDiProyekA);
    }

    public function test_penugasan_milik_proyek_perusahaan_lain_ditolak(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $proyekLain, 'id_perusahaan' => $idPerusahaanLain, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Lain', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyekLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_proyek']);

        $penugasanDiProyekLain = $this->buatPenugasan($proyekLain);
        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyek['id_proyek'], 'id_penugasan' => $penugasanDiProyekLain]))
            ->assertStatus(422)->assertJsonValidationErrors(['id_penugasan']);
    }

    public function test_opsi_proyek_dan_opsi_penugasan(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        $penugasanAktif = $this->buatPenugasan($proyek['id_proyek']);
        $this->buatPenugasan($proyek['id_proyek'], ['id_penugasan' => (string) Str::uuid(), 'status' => 'batal']);

        $this->getJson('/api/uang-jalan/opsi/proyek')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id_proyek', $proyek['id_proyek'])
            ->assertJsonPath('data.0.nama_proyek', 'Proyek Logistik STR');

        $res = $this->getJson("/api/uang-jalan/opsi/proyek/{$proyek['id_proyek']}/penugasan");
        $res->assertStatus(200)->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.id_penugasan', $penugasanAktif)
            ->assertJsonPath('data.0.sumber', 'internal')
            ->assertJsonPath('data.0.nama_driver', 'Budi Santoso')
            ->assertJsonPath('data.0.nopol', 'B 1111 AAA')
            ->assertJsonPath('data.0.nama_rute', 'STR - BTS20 - STR');
    }

    public function test_opsi_penugasan_vendor_menyertakan_id_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        $penugasanVendor = $this->buatPenugasan($proyek['id_proyek'], [
            'id_penugasan' => (string) Str::uuid(), 'id_armada' => null, 'id_supir' => null,
            'id_armada_vendor' => $m['armada_vendor'], 'id_supir_vendor' => $m['supir_vendor'], 'sumber' => 'vendor',
        ]);

        $res = $this->getJson("/api/uang-jalan/opsi/proyek/{$proyek['id_proyek']}/penugasan");
        $res->assertJsonPath('data.0.id_penugasan', $penugasanVendor)
            ->assertJsonPath('data.0.sumber', 'vendor')
            ->assertJsonPath('data.0.id_vendor', $m['vendor'])
            ->assertJsonPath('data.0.nama_driver', 'Al Wanton')
            ->assertJsonPath('data.0.nopol', 'B 9550 FXY');
    }

    public function test_opsi_rute_proyek_hanya_rute_yang_dikontrak(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);

        DB::table('rute')->insert([
            'id_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Tidak Dikontrak', 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/uang-jalan/opsi/proyek/{$proyek['id_proyek']}/rute");
        $res->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_rute', $m['rute']);
    }

    public function test_opsi_penugasan_proyek_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('DISPATCHER');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $proyekLain, 'id_perusahaan' => $idPerusahaanLain, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Lain', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/uang-jalan/opsi/proyek/{$proyekLain}/penugasan")->assertStatus(404);
    }

    public function test_filter_daftar_berdasarkan_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $m = $this->master();
        $proyekA = $this->buatProyek($m['rute']);
        $proyekB = $this->buatProyek($m['rute']);

        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyekA['id_proyek']]))->assertStatus(201);
        $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyekB['id_proyek']]))->assertStatus(201);
        $this->postJson('/api/uang-jalan', $this->payload())->assertStatus(201);

        $this->getJson('/api/uang-jalan')->assertJsonPath('meta.total', 3);
        $this->getJson("/api/uang-jalan?id_proyek={$proyekA['id_proyek']}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_proyek', $proyekA['id_proyek']);
        $this->getJson('/api/uang-jalan?search=Proyek Logistik')->assertJsonPath('meta.total', 2);
    }

    public function test_edit_bisa_melepas_tautan_penugasan_dan_proyek(): void
    {
        $approver = $this->buatApprover();
        $this->aturApproval('uang_jalan', (string) $approver->id_pengguna);
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        $penugasan = $this->buatPenugasan($proyek['id_proyek']);

        $id = $this->postJson('/api/uang-jalan', $this->payload(['id_proyek' => $proyek['id_proyek'], 'id_penugasan' => $penugasan]))
            ->json('data.id_uang_jalan');

        $res = $this->putJson("/api/uang-jalan/{$id}", $this->payload());

        $res->assertStatus(200)
            ->assertJsonPath('data.id_proyek', null)
            ->assertJsonPath('data.id_penugasan', null);
        $this->assertDatabaseHas('uang_jalan', ['id_uang_jalan' => $id, 'id_proyek' => null, 'id_penugasan' => null]);
    }

    private function buatRateCard(string $idProyek, string $idRute, ?string $idJenis, float $tol, float $bbm, float $lain): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis,
            'estimasi_tol' => $tol, 'estimasi_bbm' => $bbm, 'estimasi_biaya_lain' => $lain,
            'uang_jalan' => $tol + $bbm + $lain, 'dibuat_pada' => now(),
        ]);
    }

    public function test_tarif_rate_card_mengambil_baris_sesuai_jenis_kendaraan(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        $jenisA = (string) Str::uuid();
        $jenisB = (string) Str::uuid();
        DB::table('armada')->where('id_armada', $m['armada'])->update(['id_jenis_kendaraan' => $jenisB]);
        $this->buatRateCard($proyek['id_proyek'], $m['rute'], $jenisA, 100000, 50000, 30000);
        $this->buatRateCard($proyek['id_proyek'], $m['rute'], $jenisB, 200000, 80000, 20000);

        $this->getJson("/api/uang-jalan/tarif-rate-card?id_proyek={$proyek['id_proyek']}&id_rute={$m['rute']}&id_armada={$m['armada']}")
            ->assertStatus(200)
            ->assertJsonPath('data.tol_per_trip', 200000)
            ->assertJsonPath('data.bbm_per_trip', 80000)
            ->assertJsonPath('data.biaya_lain_per_trip', 20000)
            ->assertJsonPath('data.uang_jalan_per_trip', 300000);
    }

    public function test_tarif_rate_card_jatuh_ke_baris_pertama_bila_jenis_tidak_cocok(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        DB::table('armada')->where('id_armada', $m['armada'])->update(['id_jenis_kendaraan' => (string) Str::uuid()]);
        $this->buatRateCard($proyek['id_proyek'], $m['rute'], (string) Str::uuid(), 100000, 0, 0);

        $this->getJson("/api/uang-jalan/tarif-rate-card?id_proyek={$proyek['id_proyek']}&id_rute={$m['rute']}&id_armada={$m['armada']}")
            ->assertStatus(200)
            ->assertJsonPath('data.tol_per_trip', 100000);
    }

    public function test_tarif_rate_card_untuk_unit_vendor_memakai_jenis_armada_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);
        $jenis = (string) Str::uuid();
        DB::table('armada_vendor')->where('id_armada_vendor', $m['armada_vendor'])->update(['id_jenis_kendaraan' => $jenis]);
        $this->buatRateCard($proyek['id_proyek'], $m['rute'], $jenis, 120000, 0, 0);

        $this->getJson("/api/uang-jalan/tarif-rate-card?id_proyek={$proyek['id_proyek']}&id_rute={$m['rute']}&id_armada_vendor={$m['armada_vendor']}")
            ->assertStatus(200)
            ->assertJsonPath('data.tol_per_trip', 120000)
            ->assertJsonPath('data.uang_jalan_per_trip', 120000);
    }

    public function test_tarif_rate_card_kosong_bila_belum_ada_rincian(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();
        $proyek = $this->buatProyek($m['rute']);

        $this->getJson("/api/uang-jalan/tarif-rate-card?id_proyek={$proyek['id_proyek']}&id_rute={$m['rute']}")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_tarif_rate_card_proyek_perusahaan_lain_404(): void
    {
        $this->actingAsRole('DISPATCHER');
        $m = $this->master();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $proyekLain, 'id_perusahaan' => $idPerusahaanLain, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Lain', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/uang-jalan/tarif-rate-card?id_proyek={$proyekLain}&id_rute={$m['rute']}")->assertStatus(404);
    }
}
