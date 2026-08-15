# FINAL review package — seluruh perubahan plan arus-kas-view-log-pemasukan
CATATAN: diff file backend ArusKas Service/Repository/Interface memakai snapshot sebelum Task 2 sebagai base, sehingga IKUT menampilkan kode stream paralel di luar plan ini (infoPengajuanTrip, namaPengguna — lihat ruling di ledger). Kode itu BUKAN bagian plan ini.

## FILE BARU
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/database/migrations/2026_08_15_100001_create_pemasukan_table.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/database/migrations/2026_08_15_100001_create_pemasukan_table.php
new file mode 100644
index 0000000..6c855ce
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/database/migrations/2026_08_15_100001_create_pemasukan_table.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Helpers\MigrationHelper;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('pemasukan', function (Blueprint $table) {
+            $table->char('id_pemasukan', 36)->primary();
+            $table->char('id_perusahaan', 36)->index();
+            $table->string('nomor_pemasukan', 30);
+            $table->string('kategori', 30);
+            $table->date('tanggal');
+            $table->decimal('nominal', 15, 2);
+            $table->string('sumber_dana', 150);
+            $table->string('keterangan', 255)->nullable();
+            $table->string('url_bukti', 500)->nullable();
+            MigrationHelper::auditColumns($table);
+            $table->index(['id_perusahaan', 'tanggal']);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('pemasukan');
+    }
+};
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/PemasukanModel.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/PemasukanModel.php
new file mode 100644
index 0000000..3c25014
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/PemasukanModel.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Modules\ArusKas;
+
+use App\Models\BaseModel;
+
+class PemasukanModel extends BaseModel
+{
+    protected $table = 'pemasukan';
+    protected $primaryKey = 'id_pemasukan';
+
+    protected $fillable = [
+        'id_pemasukan',
+        'id_perusahaan',
+        'nomor_pemasukan',
+        'kategori',
+        'tanggal',
+        'nominal',
+        'sumber_dana',
+        'keterangan',
+        'url_bukti',
+    ];
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/StorePemasukanRequest.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/StorePemasukanRequest.php
new file mode 100644
index 0000000..7191177
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/StorePemasukanRequest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Modules\ArusKas\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+class StorePemasukanRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    public function rules(): array
+    {
+        return [
+            'kategori'    => ['required', 'string', Rule::in(['pendapatan_jasa', 'penjualan_aset', 'pengembalian_dana', 'modal_pinjaman', 'lainnya'])],
+            'nominal'     => ['required', 'numeric', 'min:0.01'],
+            'tanggal'     => ['required', 'date'],
+            'sumber_dana' => ['required', 'string', 'max:150'],
+            'keterangan'  => ['nullable', 'string', 'max:255'],
+            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
+        ];
+    }
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/UpdatePemasukanRequest.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/UpdatePemasukanRequest.php
new file mode 100644
index 0000000..73b527b
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Requests/UpdatePemasukanRequest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Modules\ArusKas\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+class UpdatePemasukanRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    public function rules(): array
+    {
+        return [
+            'kategori'    => ['sometimes', 'required', 'string', Rule::in(['pendapatan_jasa', 'penjualan_aset', 'pengembalian_dana', 'modal_pinjaman', 'lainnya'])],
+            'nominal'     => ['sometimes', 'required', 'numeric', 'min:0.01'],
+            'tanggal'     => ['sometimes', 'required', 'date'],
+            'sumber_dana' => ['sometimes', 'required', 'string', 'max:150'],
+            'keterangan'  => ['nullable', 'string', 'max:255'],
+            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
+        ];
+    }
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanResource.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanResource.php
new file mode 100644
index 0000000..acd6520
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanResource.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Modules\ArusKas\Resources;
+
+use App\Support\PenyimpananBerkas;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+class PemasukanResource extends JsonResource
+{
+    public function toArray($request): array
+    {
+        return [
+            'id_pemasukan'    => $this->id_pemasukan,
+            'id_perusahaan'   => $this->id_perusahaan,
+            'nomor_pemasukan' => $this->nomor_pemasukan,
+            'kategori'        => $this->kategori,
+            'tanggal'         => $this->tanggal,
+            'nominal'         => (float) $this->nominal,
+            'sumber_dana'     => $this->sumber_dana,
+            'keterangan'      => $this->keterangan,
+            'url_bukti'       => PenyimpananBerkas::url($this->url_bukti),
+            'dibuat_pada'     => $this->dibuat_pada,
+            'diubah_pada'     => $this->diubah_pada,
+        ];
+    }
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanGabunganResource.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanGabunganResource.php
new file mode 100644
index 0000000..90620d7
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Resources/PemasukanGabunganResource.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Modules\ArusKas\Resources;
+
+use App\Support\PenyimpananBerkas;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+class PemasukanGabunganResource extends JsonResource
+{
+    public function toArray($request): array
+    {
+        return [
+            'jenis'        => $this->jenis,
+            'id'           => $this->id,
+            'nomor'        => $this->nomor,
+            'kategori'     => $this->kategori,
+            'tanggal'      => $this->tanggal,
+            'nominal'      => (float) $this->nominal,
+            'sumber_dana'  => $this->sumber_dana,
+            'keterangan'   => $this->keterangan,
+            'url_bukti'    => PenyimpananBerkas::url($this->url_bukti),
+            'dapat_diubah' => $this->jenis === 'manual',
+        ];
+    }
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/tests/Feature/ArusKasPemasukanTest.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/tests/Feature/ArusKasPemasukanTest.php
new file mode 100644
index 0000000..00de438
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/tests/Feature/ArusKasPemasukanTest.php
@@ -0,0 +1,252 @@
+<?php
+declare(strict_types=1);
+
+namespace Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Tests\TestCase;
+
+class ArusKasPemasukanTest extends TestCase
+{
+    use RefreshDatabase;
+
+    private function payloadValid(array $override = []): array
+    {
+        return array_merge([
+            'kategori'    => 'pendapatan_jasa',
+            'nominal'     => 1500000,
+            'tanggal'     => '2026-08-05',
+            'sumber_dana' => 'PT Klien Jaya',
+            'keterangan'  => 'Pembayaran jasa angkut',
+        ], $override);
+    }
+
+    private function buatPemasukan(array $override = []): string
+    {
+        $id = (string) Str::uuid();
+        DB::table('pemasukan')->insert(array_merge([
+            'id_pemasukan'    => $id,
+            'id_perusahaan'   => self::PERUSAHAAN_ID,
+            'nomor_pemasukan' => 'PM-TEST-' . Str::random(6),
+            'kategori'        => 'pendapatan_jasa',
+            'tanggal'         => '2026-08-05',
+            'nominal'         => 500000,
+            'sumber_dana'     => 'PT Sumber Dana',
+            'dibuat_pada'     => now(),
+        ], $override));
+        return $id;
+    }
+
+    private function buatFaktur(array $override = []): string
+    {
+        $id = (string) Str::uuid();
+        DB::table('faktur')->insert(array_merge([
+            'id_faktur'      => $id,
+            'id_perusahaan'  => self::PERUSAHAAN_ID,
+            'nomor_faktur'   => 'FK-TEST-' . Str::random(6),
+            'total'          => 1000000,
+            'status'         => 'terkirim',
+            'tanggal_faktur' => '2026-08-05',
+            'dibuat_pada'    => now(),
+        ], $override));
+        return $id;
+    }
+
+    public function test_keuangan_membuat_pemasukan_langsung_tercatat_dengan_nomor_pm(): void
+    {
+        $this->actingAsRole('KEUANGAN');
+
+        $res = $this->postJson('/api/v1/arus-kas/pemasukan', $this->payloadValid());
+
+        $res->assertStatus(201)
+            ->assertJsonPath('data.kategori', 'pendapatan_jasa')
+            ->assertJsonPath('data.nominal', 1500000)
+            ->assertJsonPath('data.sumber_dana', 'PT Klien Jaya');
+
+        $this->assertMatchesRegularExpression('/^PM-\d{6}-0001$/', (string) $res->json('data.nomor_pemasukan'));
+        $this->assertDatabaseHas('pemasukan', [
+            'id_perusahaan' => self::PERUSAHAAN_ID,
+            'kategori'      => 'pendapatan_jasa',
+            'sumber_dana'   => 'PT Klien Jaya',
+        ]);
+    }
+
+    public function test_nomor_pemasukan_berurutan_per_perusahaan(): void
+    {
+        $this->actingAsRole('SUPERADMIN');
+
+        $a = $this->postJson('/api/v1/arus-kas/pemasukan', $this->payloadValid())->json('data.nomor_pemasukan');
+        $b = $this->postJson('/api/v1/arus-kas/pemasukan', $this->payloadValid())->json('data.nomor_pemasukan');
+
+        $this->assertStringEndsWith('-0001', (string) $a);
+        $this->assertStringEndsWith('-0002', (string) $b);
+    }
+
+    public function test_validasi_field_wajib_dan_kategori_enum(): void
+    {
+        $this->actingAsRole('KEUANGAN');
+
+        $this->postJson('/api/v1/arus-kas/pemasukan', [])
+            ->assertStatus(422)
+            ->assertJsonValidationErrors(['kategori', 'nominal', 'tanggal', 'sumber_dana']);
+
+        $this->postJson('/api/v1/arus-kas/pemasukan', $this->payloadValid(['kategori' => 'gaji']))
+            ->assertStatus(422)
+            ->assertJsonValidationErrors(['kategori']);
+    }
+
+    public function test_role_admin_tidak_bisa_membuat_pemasukan(): void
+    {
+        $this->actingAsRole('ADMIN');
+
+        $this->postJson('/api/v1/arus-kas/pemasukan', $this->payloadValid())->assertStatus(403);
+    }
+
+    public function test_update_pemasukan_manual(): void
+    {
+        $this->actingAsRole('KEUANGAN');
+        $id = $this->buatPemasukan();
+
+        $res = $this->putJson("/api/v1/arus-kas/pemasukan/{$id}", $this->payloadValid([
+            'kategori' => 'penjualan_aset',
+            'nominal'  => 2500000,
+        ]));
+
+        $res->assertStatus(200)
+            ->assertJsonPath('data.kategori', 'penjualan_aset')
+            ->assertJsonPath('data.nominal', 2500000);
+        $this->assertDatabaseHas('pemasukan', ['id_pemasukan' => $id, 'kategori' => 'penjualan_aset']);
+    }
+
+    public function test_delete_pemasukan_soft_delete(): void
+    {
+        $this->actingAsRole('KEUANGAN');
+        $id = $this->buatPemasukan();
+
+        $this->deleteJson("/api/v1/arus-kas/pemasukan/{$id}")->assertStatus(200);
+
+        $this->assertNotNull(DB::table('pemasukan')->where('id_pemasukan', $id)->value('dihapus_pada'));
+    }
+
+    public function test_pemasukan_perusahaan_lain_tidak_bisa_diubah(): void
+    {
+        $this->actingAsRole('KEUANGAN');
+        $idLain = (string) Str::uuid();
+        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
+        $id = $this->buatPemasukan(['id_perusahaan' => $idLain]);
+
+        $this->putJson("/api/v1/arus-kas/pemasukan/{$id}", $this->payloadValid())->assertStatus(404);
+        $this->deleteJson("/api/v1/arus-kas/pemasukan/{$id}")->assertStatus(404);
+    }
+
+    public function test_role_admin_tidak_bisa_ubah_atau_hapus(): void
+    {
+        $this->actingAsRole('ADMIN');
+        $id = $this->buatPemasukan();
+
+        $this->putJson("/api/v1/arus-kas/pemasukan/{$id}", $this->payloadValid())->assertStatus(403);
+        $this->deleteJson("/api/v1/arus-kas/pemasukan/{$id}")->assertStatus(403);
+    }
+
+    public function test_list_gabungan_invoice_dan_manual(): void
+    {
+        $this->actingAsRole('ADMIN');
+        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
+        $this->buatFaktur(['status' => 'batal', 'tanggal_faktur' => '2026-08-06', 'total' => 999999]);
+        $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000]);
+
+        $res = $this->getJson('/api/v1/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31');
+        $res->assertStatus(200);
+
+        $rows = collect($res->json('data'));
+        $this->assertCount(2, $rows);
+
+        $invoice = $rows->firstWhere('jenis', 'invoice');
+        $this->assertSame(1000000, $invoice['nominal']);
+        $this->assertFalse($invoice['dapat_diubah']);
+        $this->assertNull($invoice['kategori']);
+
+        $manual = $rows->firstWhere('jenis', 'manual');
+        $this->assertSame(750000, $manual['nominal']);
+        $this->assertTrue($manual['dapat_diubah']);
+        $this->assertSame('pendapatan_jasa', $manual['kategori']);
+        $this->assertSame('PT Sumber Dana', $manual['sumber_dana']);
+    }
+
+    public function test_list_gabungan_filter_jenis_dan_kategori(): void
+    {
+        $this->actingAsRole('ADMIN');
+        $this->buatFaktur(['tanggal_faktur' => '2026-08-05']);
+        $this->buatPemasukan(['kategori' => 'pendapatan_jasa', 'tanggal' => '2026-08-06']);
+        $this->buatPemasukan(['kategori' => 'modal_pinjaman', 'tanggal' => '2026-08-07']);
+
+        $invoice = $this->getJson('/api/v1/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31&jenis=invoice');
+        $this->assertCount(1, $invoice->json('data'));
+        $this->assertSame('invoice', $invoice->json('data.0.jenis'));
+
+        $kategori = $this->getJson('/api/v1/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31&kategori=modal_pinjaman');
+        $this->assertCount(1, $kategori->json('data'));
+        $this->assertSame('modal_pinjaman', $kategori->json('data.0.kategori'));
+    }
+
+    public function test_list_gabungan_isolasi_tenant_dan_soft_delete(): void
+    {
+        $this->actingAsRole('ADMIN');
+        $idLain = (string) Str::uuid();
+        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
+        $this->buatFaktur(['id_perusahaan' => $idLain, 'tanggal_faktur' => '2026-08-05']);
+        $this->buatPemasukan(['id_perusahaan' => $idLain, 'tanggal' => '2026-08-06']);
+        $this->buatPemasukan(['tanggal' => '2026-08-07', 'dihapus_pada' => now()]);
+
+        $res = $this->getJson('/api/v1/arus-kas/pemasukan?dari=2026-08-01&sampai=2026-08-31');
+        $this->assertCount(0, $res->json('data'));
+    }
+
+    public function test_list_gabungan_default_bulan_berjalan(): void
+    {
+        $this->actingAsRole('ADMIN');
+        $this->buatPemasukan(['tanggal' => now()->startOfMonth()->addDays(2)->toDateString()]);
+        $this->buatPemasukan(['tanggal' => now()->subMonths(2)->toDateString()]);
+
+        $res = $this->getJson('/api/v1/arus-kas/pemasukan');
+        $res->assertStatus(200);
+        $this->assertCount(1, $res->json('data'));
+    }
+
+    public function test_rekap_memuat_pemasukan_manual_dan_filter_sumber(): void
+    {
+        $this->actingAsRole('SUPERADMIN');
+        $this->buatFaktur(['tanggal_faktur' => '2026-08-05', 'total' => 1000000]);
+        $id = $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000, 'keterangan' => 'Setoran modal']);
+
+        $res = $this->getJson('/api/v1/arus-kas?dari=2026-08-01&sampai=2026-08-31');
+        $res->assertStatus(200)
+            ->assertJsonPath('data.ringkasan.total_pemasukan', 1750000)
+            ->assertJsonPath('data.ringkasan.netto', 1750000);
+
+        $rows = collect($res->json('data.transaksi'))->where('sumber', 'pemasukan_manual')->values();
+        $this->assertCount(1, $rows);
+        $this->assertSame('masuk', $rows[0]['arah']);
+        $this->assertSame(750000, $rows[0]['nominal']);
+        $this->assertSame('pendapatan_jasa', $rows[0]['kategori']);
+        $this->assertSame('2026-08-09', $rows[0]['tanggal']);
+        $this->assertSame($id, $rows[0]['referensi']['id']);
+        $this->assertSame('Setoran modal', $rows[0]['keterangan']);
+
+        $filter = $this->getJson('/api/v1/arus-kas?dari=2026-08-01&sampai=2026-08-31&sumber=pemasukan_manual');
+        $this->assertCount(1, $filter->json('data.transaksi'));
+        $this->assertSame('pemasukan_manual', $filter->json('data.transaksi.0.sumber'));
+    }
+
+    public function test_export_excel_memuat_pemasukan_manual(): void
+    {
+        $this->actingAsRole('SUPERADMIN');
+        $this->buatPemasukan(['tanggal' => '2026-08-09', 'nominal' => 750000]);
+
+        $res = $this->get('/api/v1/arus-kas/export/excel?dari=2026-08-01&sampai=2026-08-31');
+        $res->assertStatus(200);
+        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
+    }
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/pemasukanMeta.ts b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/pemasukanMeta.ts
new file mode 100644
index 0000000..c64a953
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/pemasukanMeta.ts
@@ -0,0 +1,28 @@
+import { KategoriPemasukan } from '@/services/arusKas.service'
+
+export const KATEGORI_PEMASUKAN_META: Record<KategoriPemasukan, { label: string; tag: string }> = {
+    pendapatan_jasa: {
+        label: 'Pendapatan Jasa',
+        tag: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300',
+    },
+    penjualan_aset: {
+        label: 'Penjualan Aset',
+        tag: 'bg-teal-100 text-teal-600 dark:bg-teal-500/20 dark:text-teal-300',
+    },
+    pengembalian_dana: {
+        label: 'Pengembalian Dana',
+        tag: 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300',
+    },
+    modal_pinjaman: {
+        label: 'Modal/Pinjaman',
+        tag: 'bg-fuchsia-100 text-fuchsia-600 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
+    },
+    lainnya: {
+        label: 'Lainnya',
+        tag: 'bg-gray-100 text-gray-600 dark:bg-gray-500/20 dark:text-gray-300',
+    },
+}
+
+export const INVOICE_TAG = 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300'
+
+export const PEMASUKAN_MANUAL_TAG = 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300'
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/DetailTransaksiDialog.tsx b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/DetailTransaksiDialog.tsx
new file mode 100644
index 0000000..d9f1c43
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/DetailTransaksiDialog.tsx
@@ -0,0 +1,105 @@
+'use client'
+import dayjs from 'dayjs'
+import { Dialog, Tag } from '@/components/ui'
+import { HiOutlineDocumentText } from 'react-icons/hi'
+import { formatRupiah } from '@/utils/formatNumber'
+
+export type DetailTransaksi = {
+    tanggal: string
+    arah: 'masuk' | 'keluar'
+    sumberLabel: string
+    sumberTagClass: string
+    kategoriLabel: string | null
+    nomor: string | null
+    referensiHref: string | null
+    sumberDana: string | null
+    keterangan: string | null
+    nominal: number
+    url_bukti: string | null
+}
+
+const LABEL_CLASS = 'text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1'
+const VALUE_CLASS = 'text-sm font-medium text-gray-800 dark:text-gray-200'
+
+const isGambar = (url: string) => /\.(jpe?g|png|webp|gif)(\?|$)/i.test(url)
+
+export default function DetailTransaksiDialog({ transaksi, onClose }: { transaksi: DetailTransaksi | null; onClose: () => void }) {
+    const t = transaksi
+    return (
+        <Dialog isOpen={!!t} onRequestClose={onClose} onClose={onClose} width={560}>
+            <h5 className="text-base font-semibold mb-1">Detail Transaksi</h5>
+            <p className="text-xs font-mono text-gray-400 mb-4">{t?.nomor ?? '—'}</p>
+            {t && (
+                <div className="max-h-[65vh] overflow-y-auto pr-1">
+                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
+                        <div>
+                            <p className={LABEL_CLASS}>Tanggal</p>
+                            <p className={VALUE_CLASS}>{dayjs(t.tanggal).format('DD MMM YYYY')}</p>
+                        </div>
+                        <div>
+                            <p className={LABEL_CLASS}>Sumber</p>
+                            <div className="flex items-center gap-2">
+                                <Tag className={`text-xs font-semibold ${t.sumberTagClass}`}>{t.sumberLabel}</Tag>
+                                {t.kategoriLabel && <span className="text-xs text-gray-400">{t.kategoriLabel}</span>}
+                            </div>
+                        </div>
+                        <div>
+                            <p className={LABEL_CLASS}>Nominal</p>
+                            <p className={`text-sm font-bold tabular-nums ${
+                                t.arah === 'masuk' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'
+                            }`}>
+                                {t.arah === 'masuk' ? '+ ' : '- '}{formatRupiah(t.nominal)}
+                            </p>
+                        </div>
+                        <div>
+                            <p className={LABEL_CLASS}>Referensi</p>
+                            {t.referensiHref ? (
+                                <a href={t.referensiHref} target="_blank" rel="noreferrer"
+                                    className="text-sm text-blue-600 dark:text-blue-400 hover:underline">
+                                    {t.nomor ?? 'Lihat referensi'}
+                                </a>
+                            ) : (
+                                <p className={VALUE_CLASS}>{t.nomor ?? '—'}</p>
+                            )}
+                        </div>
+                        {t.sumberDana && (
+                            <div>
+                                <p className={LABEL_CLASS}>Sumber Dana</p>
+                                <p className={VALUE_CLASS}>{t.sumberDana}</p>
+                            </div>
+                        )}
+                    </div>
+
+                    {t.keterangan && (
+                        <div className="mt-4">
+                            <p className={LABEL_CLASS}>Keterangan</p>
+                            <p className="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line">{t.keterangan}</p>
+                        </div>
+                    )}
+
+                    <div className="mt-5">
+                        <p className={`${LABEL_CLASS} mb-2`}>Bukti</p>
+                        {t.url_bukti ? (
+                            isGambar(t.url_bukti) ? (
+                                <div className="w-fit">
+                                    <a href={t.url_bukti} target="_blank" rel="noreferrer">
+                                        <img src={t.url_bukti} alt="Bukti transaksi"
+                                            className="h-24 w-40 object-cover rounded-lg border border-gray-100 dark:border-gray-700" />
+                                    </a>
+                                    <p className="text-xs text-gray-400 mt-1">Klik untuk membuka</p>
+                                </div>
+                            ) : (
+                                <a href={t.url_bukti} target="_blank" rel="noreferrer"
+                                    className="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:underline">
+                                    <HiOutlineDocumentText className="text-base" /> Lihat bukti
+                                </a>
+                            )
+                        ) : (
+                            <p className="text-xs text-gray-400 italic">Tidak ada bukti tersimpan.</p>
+                        )}
+                    </div>
+                </div>
+            )}
+        </Dialog>
+    )
+}
diff --git a/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/PemasukanTab.tsx b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/PemasukanTab.tsx
new file mode 100644
index 0000000..49ae603
--- /dev/null
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/PemasukanTab.tsx
@@ -0,0 +1,370 @@
+'use client'
+import { useCallback, useEffect, useRef, useState } from 'react'
+import dayjs from 'dayjs'
+import { Card, Button, Dialog, FormItem, Input, Tag, Tooltip, Spinner, toast, Notification } from '@/components/ui'
+import Select from '@/components/ui/Select'
+import DatePicker from '@/components/ui/DatePicker'
+import DataTable from '@/components/shared/DataTable'
+import type { ColumnDef } from '@/components/shared/DataTable'
+import ConfirmDialog from '@/components/shared/ConfirmDialog'
+import UploadBerkas from '@/components/shared/UploadBerkas'
+import { HiOutlineEye, HiOutlinePencilAlt, HiOutlineTrash } from 'react-icons/hi'
+import DetailTransaksiDialog, { DetailTransaksi } from './DetailTransaksiDialog'
+import { KATEGORI_PEMASUKAN_META, INVOICE_TAG, PEMASUKAN_MANUAL_TAG } from './pemasukanMeta'
+import { parseApiError } from '@/utils/error.util'
+import { formatRupiah, formatNum } from '@/utils/formatNumber'
+import useCurrentSession from '@/utils/hooks/useCurrentSession'
+import { ROUTES } from '@/constants/route.constant'
+import { arusKasService, KategoriPemasukan, PemasukanRow } from '@/services/arusKas.service'
+
+type Option = { value: string; label: string }
+
+const MAX_FILE_SIZE = 5 * 1024 * 1024
+
+const KATEGORI_KEYS = Object.keys(KATEGORI_PEMASUKAN_META) as KategoriPemasukan[]
+
+const FILTER_OPTIONS: Option[] = [
+    { value: '',        label: 'Semua' },
+    { value: 'invoice', label: 'Invoice' },
+    ...KATEGORI_KEYS.map(value => ({ value, label: KATEGORI_PEMASUKAN_META[value].label })),
+]
+
+const KATEGORI_OPTIONS: { value: KategoriPemasukan; label: string }[] =
+    KATEGORI_KEYS.map(value => ({ value, label: KATEGORI_PEMASUKAN_META[value].label }))
+
+type PemasukanForm = {
+    kategori: KategoriPemasukan | ''
+    nominal: string
+    tanggal: string
+    sumber_dana: string
+    keterangan: string
+}
+
+const emptyForm = (): PemasukanForm => ({
+    kategori: '',
+    nominal: '',
+    tanggal: dayjs().format('YYYY-MM-DD'),
+    sumber_dana: '',
+    keterangan: '',
+})
+
+export default function PemasukanTab({ tambahTrigger = 0 }: { tambahTrigger?: number }) {
+    const { session } = useCurrentSession()
+    const authority = ((session?.user?.authority ?? []) as string[]).map(a => a.toLowerCase())
+    const bolehKelola = ['keuangan', 'superadmin'].some(r => authority.includes(r))
+
+    const [dari, setDari]     = useState(dayjs().startOf('month').format('YYYY-MM-DD'))
+    const [sampai, setSampai] = useState(dayjs().endOf('month').format('YYYY-MM-DD'))
+    const [filter, setFilter] = useState('')
+
+    const [list, setList]             = useState<PemasukanRow[]>([])
+    const [loading, setLoading]       = useState(false)
+    const [submitting, setSubmitting] = useState(false)
+    const [currentPage, setCurrentPage] = useState(1)
+    const [pageSize, setPageSize]       = useState(10)
+
+    const [showForm, setShowForm]     = useState(false)
+    const [editTarget, setEditTarget] = useState<PemasukanRow | null>(null)
+    const [form, setForm]             = useState<PemasukanForm>(emptyForm())
+    const [file, setFile]             = useState<File | null>(null)
+
+    const [deleteTarget, setDeleteTarget] = useState<PemasukanRow | null>(null)
+    const [detailTarget, setDetailTarget] = useState<DetailTransaksi | null>(null)
+
+    const reqRef = useRef(0)
+    const fetchData = useCallback(async () => {
+        const reqId = ++reqRef.current
+        setLoading(true)
+        try {
+            const data = await arusKasService.listPemasukan({
+                dari,
+                sampai,
+                jenis: filter === 'invoice' ? 'invoice' : undefined,
+                kategori: filter && filter !== 'invoice' ? (filter as KategoriPemasukan) : undefined,
+            })
+            if (reqRef.current !== reqId) return
+            setList(data)
+            setCurrentPage(1)
+        } catch (err) {
+            if (reqRef.current !== reqId) return
+            toast.push(<Notification type="danger" title={parseApiError(err)} />)
+        } finally {
+            if (reqRef.current === reqId) setLoading(false)
+        }
+    }, [dari, sampai, filter])
+
+    useEffect(() => { fetchData() }, [fetchData])
+
+    const jalankan = async (aksi: () => Promise<unknown>, pesanSukses: string, tutup?: () => void) => {
+        setSubmitting(true)
+        try {
+            await aksi()
+            toast.push(<Notification type="success" title={pesanSukses} />)
+            tutup?.()
+            fetchData()
+        } catch (err) {
+            toast.push(<Notification type="danger" title={parseApiError(err)} />)
+        } finally {
+            setSubmitting(false)
+        }
+    }
+
+    const openAdd = useCallback(() => { setEditTarget(null); setForm(emptyForm()); setFile(null); setShowForm(true) }, [])
+
+    useEffect(() => {
+        if (tambahTrigger > 0) openAdd()
+    }, [tambahTrigger, openAdd])
+
+    const openEdit = (p: PemasukanRow) => {
+        setEditTarget(p)
+        setForm({
+            kategori: p.kategori ?? '',
+            nominal: String(p.nominal),
+            tanggal: p.tanggal,
+            sumber_dana: p.sumber_dana ?? '',
+            keterangan: p.keterangan ?? '',
+        })
+        setFile(null)
+        setShowForm(true)
+    }
+
+    const closeForm = () => { setShowForm(false); setEditTarget(null) }
+
+    const validasiFile = (f: File | null) => {
+        if (f && f.size > MAX_FILE_SIZE) {
+            toast.push(<Notification type="danger" title={`Ukuran file maksimal 5 MB (file dipilih: ${(f.size / 1024 / 1024).toFixed(1)} MB)`} />)
+            return
+        }
+        setFile(f)
+    }
+
+    const handleSubmitForm = () => {
+        if (!form.kategori || !form.nominal || !form.tanggal || !form.sumber_dana.trim()) return
+        const payload = {
+            kategori: form.kategori as KategoriPemasukan,
+            nominal: Number(form.nominal),
+            tanggal: form.tanggal,
+            sumber_dana: form.sumber_dana.trim(),
+            keterangan: form.keterangan.trim() || null,
+        }
+        if (editTarget) {
+            jalankan(() => arusKasService.updatePemasukan(editTarget.id, payload, file), 'Pemasukan berhasil diperbarui', closeForm)
+        } else {
+            jalankan(() => arusKasService.createPemasukan(payload, file), 'Pemasukan berhasil dicatat', closeForm)
+        }
+    }
+
+    const handleDelete = () => {
+        if (!deleteTarget) return
+        jalankan(() => arusKasService.deletePemasukan(deleteTarget.id), 'Pemasukan berhasil dihapus', () => setDeleteTarget(null))
+    }
+
+    const bukaDetail = (p: PemasukanRow) => {
+        setDetailTarget({
+            tanggal: p.tanggal,
+            arah: 'masuk',
+            sumberLabel: p.jenis === 'invoice' ? 'Invoice' : 'Pemasukan Manual',
+            sumberTagClass: p.jenis === 'invoice' ? INVOICE_TAG : PEMASUKAN_MANUAL_TAG,
+            kategoriLabel: p.kategori ? KATEGORI_PEMASUKAN_META[p.kategori].label : null,
+            nomor: p.nomor,
+            referensiHref: p.jenis === 'invoice' ? ROUTES.FAKTUR_DETAIL(p.id) : null,
+            sumberDana: p.sumber_dana,
+            keterangan: p.keterangan,
+            nominal: p.nominal,
+            url_bukti: p.url_bukti,
+        })
+    }
+
+    const paged = list.slice((currentPage - 1) * pageSize, currentPage * pageSize)
+
+    const columns: ColumnDef<PemasukanRow>[] = [
+        {
+            header: 'No', id: 'no', size: 50,
+            cell: ({ row }) => (currentPage - 1) * pageSize + row.index + 1,
+        },
+        {
+            header: 'Tanggal', accessorKey: 'tanggal', size: 120,
+            cell: ({ row }) => <span className="whitespace-nowrap">{dayjs(row.original.tanggal).format('DD MMM YYYY')}</span>,
+        },
+        {
+            header: 'Sumber', id: 'sumber', size: 140,
+            cell: ({ row }) => {
+                const p = row.original
+                if (p.jenis === 'invoice') {
+                    return <Tag className={`text-xs font-semibold ${INVOICE_TAG}`}>Invoice</Tag>
+                }
+                const km = p.kategori ? KATEGORI_PEMASUKAN_META[p.kategori] : null
+                return km
+                    ? <Tag className={`text-xs font-semibold ${km.tag}`}>{km.label}</Tag>
+                    : <Tag className={`text-xs font-semibold ${PEMASUKAN_MANUAL_TAG}`}>Pemasukan Manual</Tag>
+            },
+        },
+        {
+            header: 'Nomor', accessorKey: 'nomor', size: 170,
+            cell: ({ row }) => {
+                const p = row.original
+                if (p.jenis === 'invoice') {
+                    return (
+                        <a href={ROUTES.FAKTUR_DETAIL(p.id)} target="_blank" rel="noopener noreferrer"
+                            className="text-blue-600 dark:text-blue-400 hover:underline">{p.nomor}</a>
+                    )
+                }
+                return <span className="font-mono font-semibold text-xs">{p.nomor}</span>
+            },
+        },
+        {
+            header: 'Sumber Dana', accessorKey: 'sumber_dana', size: 160,
+            cell: ({ row }) => row.original.sumber_dana ?? '—',
+        },
+        {
+            header: 'Keterangan', accessorKey: 'keterangan', size: 200,
+            cell: ({ row }) => row.original.keterangan ?? '—',
+        },
+        {
+            header: 'Nominal', id: 'nominal', size: 150,
+            cell: ({ row }) => (
+                <span className="tabular-nums font-semibold whitespace-nowrap text-emerald-600 dark:text-emerald-400">
+                    + {formatRupiah(row.original.nominal)}
+                </span>
+            ),
+        },
+        {
+            header: '', id: 'aksi', size: 130,
+            cell: ({ row }) => {
+                const p = row.original
+                return (
+                    <div className="flex items-center justify-end gap-1">
+                        <Tooltip title="Lihat Detail">
+                            <span
+                                className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300 dark:hover:bg-blue-500/30 transition-colors"
+                                onClick={() => bukaDetail(p)}>
+                                <HiOutlineEye className="text-lg" />
+                            </span>
+                        </Tooltip>
+                        {p.dapat_diubah && bolehKelola && (
+                            <>
+                                <Tooltip title="Edit">
+                                    <span
+                                        className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300 dark:hover:bg-blue-500/30 transition-colors"
+                                        onClick={() => openEdit(p)}>
+                                        <HiOutlinePencilAlt className="text-lg" />
+                                    </span>
+                                </Tooltip>
+                                <Tooltip title="Hapus">
+                                    <span
+                                        className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/20 dark:text-red-400 dark:hover:bg-red-500/30 transition-colors"
+                                        onClick={() => setDeleteTarget(p)}>
+                                        <HiOutlineTrash className="text-lg" />
+                                    </span>
+                                </Tooltip>
+                            </>
+                        )}
+                    </div>
+                )
+            },
+        },
+    ]
+
+    return (
+        <div className="flex flex-col gap-4">
+            <Card bodyClass="p-0">
+                <div className="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-3">
+                    <div className="flex items-center gap-2">
+                        <DatePicker inputFormat="DD/MM/YYYY" className="w-40"
+                            value={dari ? dayjs(dari).toDate() : null}
+                            onChange={date => setDari(date ? dayjs(date).format('YYYY-MM-DD') : '')} />
+                        <span className="text-gray-400 text-sm">s/d</span>
+                        <DatePicker inputFormat="DD/MM/YYYY" className="w-40"
+                            value={sampai ? dayjs(sampai).toDate() : null}
+                            onChange={date => setSampai(date ? dayjs(date).format('YYYY-MM-DD') : '')} />
+                    </div>
+                    <div className="w-full sm:w-48 shrink-0">
+                        <Select
+                            isSearchable={false}
+                            options={FILTER_OPTIONS}
+                            value={FILTER_OPTIONS.find(o => o.value === filter) ?? FILTER_OPTIONS[0]}
+                            onChange={opt => setFilter((opt as Option | null)?.value ?? '')}
+                        />
+                    </div>
+                    {loading && <Spinner size={20} />}
+                </div>
+                <DataTable
+                    columns={columns}
+                    data={paged as unknown[]}
+                    loading={loading}
+                    noData={!loading && list.length === 0}
+                    pagingData={{ total: list.length, pageIndex: currentPage, pageSize }}
+                    onPaginationChange={setCurrentPage}
+                    onSelectChange={(size) => { setPageSize(size); setCurrentPage(1) }}
+                />
+            </Card>
+
+            <Dialog isOpen={showForm} onRequestClose={closeForm} onClose={closeForm} width={640}>
+                <h5 className="text-base font-semibold mb-5">{editTarget ? 'Edit Pemasukan' : 'Tambah Pemasukan'}</h5>
+                <form onSubmit={e => { e.preventDefault(); handleSubmitForm() }}>
+                    <div className="max-h-[65vh] overflow-y-auto pr-1">
+                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6">
+                            <FormItem label="Kategori" asterisk>
+                                <Select
+                                    isSearchable={false}
+                                    placeholder="Pilih kategori..."
+                                    options={KATEGORI_OPTIONS}
+                                    value={KATEGORI_OPTIONS.find(o => o.value === form.kategori) ?? null}
+                                    onChange={opt => setForm(p => ({ ...p, kategori: ((opt as { value: KategoriPemasukan } | null)?.value) ?? '' }))}
+                                />
+                            </FormItem>
+                            <FormItem label="Nominal" asterisk>
+                                <Input prefix="Rp" placeholder="0"
+                                    value={form.nominal ? formatNum(Number(form.nominal)) : ''}
+                                    onChange={e => setForm(p => ({ ...p, nominal: e.target.value.replace(/\D/g, '') }))} />
+                            </FormItem>
+                            <FormItem label="Tanggal" asterisk>
+                                <DatePicker inputFormat="DD/MM/YYYY"
+                                    value={form.tanggal ? dayjs(form.tanggal).toDate() : null}
+                                    onChange={date => setForm(p => ({ ...p, tanggal: date ? dayjs(date).format('YYYY-MM-DD') : '' }))} />
+                            </FormItem>
+                            <FormItem label="Sumber Dana" asterisk>
+                                <Input placeholder="Nama pemberi / asal dana" value={form.sumber_dana}
+                                    onChange={e => setForm(p => ({ ...p, sumber_dana: e.target.value }))} />
+                            </FormItem>
+                            <div className="sm:col-span-2">
+                                <FormItem label="Keterangan (opsional)">
+                                    <Input textArea rows={3} placeholder="Catatan tambahan..." value={form.keterangan}
+                                        onChange={e => setForm(p => ({ ...p, keterangan: e.target.value }))} />
+                                </FormItem>
+                            </div>
+                            <div className="sm:col-span-2">
+                                <FormItem label="Bukti (opsional)">
+                                    <UploadBerkas
+                                        file={file}
+                                        label={editTarget ? 'Ganti file (opsional)' : 'Pilih file'}
+                                        existingUrl={editTarget?.url_bukti ?? null}
+                                        existingLabel="Bukti saat ini"
+                                        emptyText={editTarget ? 'Belum ada bukti tersimpan' : null}
+                                        onChange={validasiFile}
+                                    />
+                                </FormItem>
+                            </div>
+                        </div>
+                    </div>
+                    <div className="flex justify-end gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
+                        <Button type="button" variant="plain" onClick={closeForm}>Batal</Button>
+                        <Button type="submit" variant="solid" loading={submitting}
+                            disabled={!form.kategori || !form.nominal || !form.tanggal || !form.sumber_dana.trim()}>
+                            Simpan
+                        </Button>
+                    </div>
+                </form>
+            </Dialog>
+
+            <DetailTransaksiDialog transaksi={detailTarget} onClose={() => setDetailTarget(null)} />
+
+            <ConfirmDialog isOpen={!!deleteTarget} type="danger" title="Hapus Pemasukan"
+                confirmText="Ya, Hapus" cancelText="Batal"
+                onClose={() => setDeleteTarget(null)} onCancel={() => setDeleteTarget(null)} onConfirm={handleDelete}
+                confirmButtonProps={{ loading: submitting }}>
+                <p>Hapus pemasukan {deleteTarget?.nomor}? Tindakan ini tidak dapat dibatalkan.</p>
+            </ConfirmDialog>
+        </div>
+    )
+}

## FILE DIUBAH (kumulatif, base = snapshot sebelum task pertama yang menyentuhnya)
