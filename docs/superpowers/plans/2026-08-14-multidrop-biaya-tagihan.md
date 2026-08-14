# Multidrop Trip + Biaya Tagihan Trip — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trip bisa punya banyak titik drop (rencana di penugasan → disalin ke trip) dan biaya tambahan yang ditagihkan ke klien (multidrop, TKBM, dst.) ikut masuk konsolidasi + faktur.

**Architecture:** Hybrid snapshot — `titik_drop_penugasan` (rencana) disalin ke `titik_drop_trip` saat trip dibuat; realisasi (trip) jadi sumber kebenaran tampilan & tagihan. Biaya tagihan klien = tabel generik `biaya_tagihan_trip` per laporan perjalanan (kembaran `biaya_lain_trip`, tapi TIDAK ikut settlement supir). Penagihan menjumlah tarif rute + biaya tagihan; tiap baris biaya jadi item faktur terpisah.

**Tech Stack:** Laravel 11 (module pattern: Controller→Service→Repository+Interface), MySQL/SQLite(test), phpunit; Next.js 15 + Ecme.

**Spec:** `docs/superpowers/specs/2026-08-14-multidrop-trip-design.md`

## Global Constraints

- **DILARANG menjalankan `git commit` / mengubah git state** — user commit manual. Setiap akhir task cukup laporkan ringkasan diff (SDD snapshot).
- **DILARANG menjalankan build** (`npm run build`, docker) — user build sendiri. `npm run lint` boleh.
- Test backend: `vendor/bin/phpunit --filter=NamaTest` (JANGAN `php artisan test`).
- Semua tabel baru wajib `MigrationHelper::auditColumns($table)`; semua query baris aktif pakai `whereNull('dihapus_pada')`.
- Eloquent/DB query HANYA di `*Repository.php`; method baru repository WAJIB ditambahkan ke interface di `Contracts/`.
- Semua teks UI bahasa Indonesia. Respons API mengikuti kontrak `{ success, message, data, timestamp }`.
- Batas: maksimal **10** titik drop per penugasan/trip; maksimal **10** baris biaya tagihan per trip; `lokasi` max 200 char; `nama_biaya` max 100 char; `nominal >= 0`.
- Guard pasca-faktur: perubahan `titik_drop_trip` dan `biaya_tagihan` DITOLAK 422 bila trip terhubung faktur aktif (`faktur_trip.dihapus_pada IS NULL` + `faktur.status != 'batal'` + `faktur.dihapus_pada IS NULL`).

---

### Task 1: Migration 3 tabel baru

**Files:**
- Create: `database/migrations/2026_08_14_000001_create_titik_drop_penugasan_table.php`
- Create: `database/migrations/2026_08_14_000002_create_titik_drop_trip_table.php`
- Create: `database/migrations/2026_08_14_000003_create_biaya_tagihan_trip_table.php`

**Interfaces:**
- Produces: tabel `titik_drop_penugasan(id_titik_drop, id_penugasan, urutan, lokasi)`, `titik_drop_trip(id_titik_drop, id_trip, urutan, lokasi)`, `biaya_tagihan_trip(id_biaya_tagihan, id_laporan, nama_biaya, nominal)` — semua + kolom audit.

- [ ] **Step 1: Tulis 3 migration**

`2026_08_14_000001_create_titik_drop_penugasan_table.php`:
```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titik_drop_penugasan', function (Blueprint $table) {
            $table->char('id_titik_drop', 36)->primary();
            $table->char('id_penugasan', 36)->index();
            $table->unsignedTinyInteger('urutan');
            $table->string('lokasi', 200);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titik_drop_penugasan');
    }
};
```

`2026_08_14_000002_create_titik_drop_trip_table.php` — identik, ganti nama tabel jadi `titik_drop_trip` dan kolom `id_penugasan` jadi `id_trip` (tetap `->index()`).

`2026_08_14_000003_create_biaya_tagihan_trip_table.php`:
```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biaya_tagihan_trip', function (Blueprint $table) {
            $table->char('id_biaya_tagihan', 36)->primary();
            $table->char('id_laporan', 36)->index();
            $table->string('nama_biaya', 100);
            $table->decimal('nominal', 15, 2)->default(0);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_tagihan_trip');
    }
};
```

- [ ] **Step 2: Verifikasi migration jalan (sqlite test memuat semua migration)**

Run: `vendor/bin/phpunit --filter=TripMulaiTest`
Expected: PASS (jumlah test sama seperti sebelum task ini; kalau migration salah, semua error di boot).

- [ ] **Step 3: Checkpoint** — laporkan diff (3 file baru). Jangan commit.

---

### Task 2: Penugasan — simpan & tampilkan titik drop (rencana)

**Files:**
- Modify: `app/Modules/Penugasan/Requests/StorePenugasanRequest.php`, `Requests/UpdatePenugasanRequest.php` (tambah rules)
- Modify: `app/Modules/Penugasan/PenugasanService.php` (create, update)
- Modify: `app/Modules/Penugasan/PenugasanRepository.php` + `Contracts/PenugasanRepositoryInterface.php`
- Modify: `app/Modules/Penugasan/PenugasanController.php` (show, index — lampirkan titik_drop)
- Modify: `app/Modules/Penugasan/Resources/PenugasanResource.php`
- Test: `tests/Feature/PenugasanTitikDropTest.php` (baru)

**Interfaces:**
- Produces (dipakai Task 3): `PenugasanRepositoryInterface::syncTitikDrop(string $idPenugasan, array $lokasiList): void` dan `titikDropUntukBanyak(array $idPenugasan): array` (map `id_penugasan => string[]` lokasi urut `urutan`).
- Payload API: `titik_drop: string[]` opsional di `POST/PUT /penugasan`; resource mengembalikan `titik_drop: string[]`.

- [ ] **Step 1: Tulis failing test** `tests/Feature/PenugasanTitikDropTest.php`

Ikuti pola setup test penugasan yang ada (lihat `tests/Feature/PenugasanStatusOtomatisTest.php` untuk helper `actingAsRole`, pembuatan proyek/armada/supir). Inti asersi:

```php
public function test_store_penugasan_menyimpan_titik_drop_berurutan(): void
{
    // ...setup proyek+supir+armada seperti PenugasanStatusOtomatisTest...
    $res = $this->postJson('/api/v1/penugasan', [
        // ...field wajib penugasan yang sama dengan test existing...
        'titik_drop' => ['JLB', 'MRY', 'RDS'],
    ]);
    $res->assertStatus(201)->assertJsonPath('data.titik_drop', ['JLB', 'MRY', 'RDS']);

    $id = $res->json('data.id_penugasan');
    $rows = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
        ->whereNull('dihapus_pada')->orderBy('urutan')->get();
    $this->assertSame(['JLB', 'MRY', 'RDS'], $rows->pluck('lokasi')->all());
    $this->assertSame([1, 2, 3], $rows->pluck('urutan')->map(fn ($u) => (int) $u)->all());
}

public function test_update_penugasan_replace_titik_drop(): void
{
    // buat penugasan dengan ['JLB','MRY'], lalu PUT dengan ['KPM']
    // assert: baris aktif tinggal 1 ('KPM'), baris lama dihapus_pada != null
}

public function test_titik_drop_lebih_dari_10_ditolak(): void
{
    // kirim 11 string → assertStatus(422)
}

public function test_update_tanpa_key_titik_drop_tidak_menghapus_yang_ada(): void
{
    // buat dengan ['JLB'], PUT tanpa field titik_drop → baris 'JLB' tetap aktif
}
```

- [ ] **Step 2: Run test, pastikan FAIL** — `vendor/bin/phpunit --filter=PenugasanTitikDropTest` (fail: field diabaikan / titik_drop null).

- [ ] **Step 3: Implementasi**

Requests (Store & Update, rules sama):
```php
'titik_drop'   => ['sometimes', 'array', 'max:10'],
'titik_drop.*' => ['required', 'string', 'max:200'],
```

`PenugasanRepository` (+ daftarkan di interface):
```php
public function syncTitikDrop(string $idPenugasan, array $lokasiList): void
{
    DB::table('titik_drop_penugasan')
        ->where('id_penugasan', $idPenugasan)
        ->whereNull('dihapus_pada')
        ->update(RecordHelper::stampDelete());

    foreach (array_values($lokasiList) as $i => $lokasi) {
        DB::table('titik_drop_penugasan')->insert(RecordHelper::stampCreate([
            'id_penugasan' => $idPenugasan,
            'urutan'       => $i + 1,
            'lokasi'       => trim((string) $lokasi),
        ], 'id_titik_drop'));
    }
}

public function titikDropUntukBanyak(array $idPenugasan): array
{
    if ($idPenugasan === []) {
        return [];
    }
    return DB::table('titik_drop_penugasan')
        ->whereIn('id_penugasan', $idPenugasan)
        ->whereNull('dihapus_pada')
        ->orderBy('urutan')
        ->get(['id_penugasan', 'lokasi'])
        ->groupBy('id_penugasan')
        ->map(fn ($g) => $g->pluck('lokasi')->all())
        ->all();
}
```
(Import `App\Support\RecordHelper` bila belum.)

`PenugasanService::create` — sebelum `$record = $this->repo->create($data);` tambahkan ekstraksi, sesudahnya sync + lampirkan:
```php
$titikDrop = $data['titik_drop'] ?? null;
unset($data['titik_drop']);
// ... $record = $this->repo->create($data);
if ($titikDrop !== null) {
    $this->repo->syncTitikDrop((string) $record->id_penugasan, $titikDrop);
}
$record->titik_drop = $titikDrop ?? [];
```
`PenugasanService::update` — pola sama: `array_key_exists('titik_drop', $data)` → sync; lalu `$record->titik_drop = $this->repo->titikDropUntukBanyak([$id])[$id] ?? []` sebelum return.

`PenugasanController::show` — setelah dapat record dari service, lampirkan:
```php
$record->titik_drop = $this->service->titikDropUntuk((string) $record->id_penugasan);
```
Tambahkan di `PenugasanService`:
```php
public function titikDropUntuk(string $idPenugasan): array
{
    return $this->repo->titikDropUntukBanyak([$idPenugasan])[$idPenugasan] ?? [];
}
```
`PenugasanController::index` — setelah dapat `$result['data']`, bulk-lampirkan:
```php
$map = $this->service->titikDropBanyak(array_map(fn ($r) => (string) $r->id_penugasan, [...$result['data']]));
foreach ($result['data'] as $row) { $row->titik_drop = $map[$row->id_penugasan] ?? []; }
```
dengan `PenugasanService::titikDropBanyak(array $ids): array` yang memanggil `titikDropUntukBanyak` repo.

`PenugasanResource` — tambahkan `'titik_drop' => $this->titik_drop ?? [],`.

- [ ] **Step 4: Run test sampai PASS** — `vendor/bin/phpunit --filter=PenugasanTitikDropTest`
- [ ] **Step 5: Regression** — `vendor/bin/phpunit --filter="PenugasanStatusOtomatisTest|PenugasanVendorTest|PenugasanListPerusahaanTest|PenugasanArmadaLifecycleTest"` Expected: PASS semua.
- [ ] **Step 6: Checkpoint** — laporkan diff. Jangan commit.

---

### Task 3: Trip — salin drop saat trip dibuat + endpoint koreksi + tampil

**Files:**
- Modify: `app/Modules/Trip/TripService.php` (`mulaiDariPenugasan`, `create`)
- Modify: `app/Modules/Trip/TripRepository.php` + `Contracts/TripRepositoryInterface.php`
- Modify: `app/Modules/Trip/TripController.php` (method baru `updateTitikDrop`, dan `show`)
- Modify: ServiceProvider Trip (route `PUT trip/{id}/titik-drop` — letakkan berdampingan dengan route `trip/{id}/status`)
- Test: `tests/Feature/TripTitikDropTest.php` (baru)

**Interfaces:**
- Consumes: tabel Task 1; `titik_drop_penugasan` terisi via Task 2.
- Produces: `TripRepositoryInterface::salinTitikDropDariPenugasan(string $idPenugasan, string $idTrip): void`, `syncTitikDropTrip(string $idTrip, array $lokasiList): void`, `titikDropTrip(string $idTrip): array` (string[] urut), `titikDropTripBanyak(array $idTrips): array` (map), `tripPunyaFakturAktif(string $idTrip): bool`.
- API: `PUT /trip/{id}/titik-drop` body `{ "titik_drop": ["JLB","MRY"] }` → data trip detail; detail `GET /trip/{id}` mengembalikan `titik_drop: string[]` dan `sudah_difakturkan: bool`.

- [ ] **Step 1: Tulis failing test** `tests/Feature/TripTitikDropTest.php` (pola setup ikuti `TripMulaiTest`):

```php
public function test_mulai_trip_menyalin_titik_drop_dari_penugasan(): void
{
    // setup penugasan aktif dgn titik_drop ['JLB','MRY'] (insert via endpoint penugasan)
    // POST /api/v1/trip/mulai {id_penugasan}
    // assert: titik_drop_trip milik trip baru = ['JLB','MRY'] urut
}

public function test_edit_titik_drop_penugasan_tidak_mengubah_trip_yang_sudah_ada(): void
{
    // mulai trip (tersalin ['JLB']), lalu PUT penugasan titik_drop ['KPM']
    // assert: titik_drop_trip tetap ['JLB']
}

public function test_put_titik_drop_trip_replace_realisasi(): void
{
    // PUT /api/v1/trip/{id}/titik-drop {titik_drop:['JLB','MRY','RDS','KPM']}
    // assert 200 + data.titik_drop lengkap; baris lama soft-deleted
}

public function test_put_titik_drop_ditolak_bila_sudah_difakturkan(): void
{
    // insert manual faktur (status draft) + faktur_trip untuk trip tsb (lihat pola KonsolidasiKlienTest / PenagihanTripTest)
    // PUT titik-drop → assertStatus(422)
}
```

- [ ] **Step 2: Run, pastikan FAIL** — `vendor/bin/phpunit --filter=TripTitikDropTest`

- [ ] **Step 3: Implementasi**

`TripRepository` (+ interface), pakai `RecordHelper`:
```php
public function salinTitikDropDariPenugasan(string $idPenugasan, string $idTrip): void
{
    $rows = DB::table('titik_drop_penugasan')
        ->where('id_penugasan', $idPenugasan)
        ->whereNull('dihapus_pada')
        ->orderBy('urutan')
        ->get(['urutan', 'lokasi']);

    foreach ($rows as $row) {
        DB::table('titik_drop_trip')->insert(RecordHelper::stampCreate([
            'id_trip' => $idTrip,
            'urutan'  => (int) $row->urutan,
            'lokasi'  => $row->lokasi,
        ], 'id_titik_drop'));
    }
}

public function syncTitikDropTrip(string $idTrip, array $lokasiList): void
{
    DB::table('titik_drop_trip')
        ->where('id_trip', $idTrip)->whereNull('dihapus_pada')
        ->update(RecordHelper::stampDelete());
    foreach (array_values($lokasiList) as $i => $lokasi) {
        DB::table('titik_drop_trip')->insert(RecordHelper::stampCreate([
            'id_trip' => $idTrip,
            'urutan'  => $i + 1,
            'lokasi'  => trim((string) $lokasi),
        ], 'id_titik_drop'));
    }
}

public function titikDropTripBanyak(array $idTrips): array
{
    if ($idTrips === []) {
        return [];
    }
    return DB::table('titik_drop_trip')
        ->whereIn('id_trip', $idTrips)->whereNull('dihapus_pada')
        ->orderBy('urutan')
        ->get(['id_trip', 'lokasi'])
        ->groupBy('id_trip')
        ->map(fn ($g) => $g->pluck('lokasi')->all())
        ->all();
}

public function titikDropTrip(string $idTrip): array
{
    return $this->titikDropTripBanyak([$idTrip])[$idTrip] ?? [];
}

public function tripPunyaFakturAktif(string $idTrip): bool
{
    return DB::table('faktur_trip as ft')
        ->join('faktur as f', 'f.id_faktur', '=', 'ft.id_faktur')
        ->where('ft.id_trip', $idTrip)
        ->whereNull('ft.dihapus_pada')
        ->whereNull('f.dihapus_pada')
        ->where('f.status', '!=', 'batal')
        ->exists();
}
```

`TripService::mulaiDariPenugasan` — setelah `$trip = $this->repo->create([...])` tambahkan:
```php
$this->repo->salinTitikDropDariPenugasan((string) $penugasan->id_penugasan, (string) $trip->id_trip);
```
`TripService::create` (jalur dari jadwal) — setelah `return $this->repo->create($data);` diubah agar menyalin juga:
```php
$trip = $this->repo->create($data);
$this->repo->salinTitikDropDariPenugasan((string) $penugasan->id_penugasan, (string) $trip->id_trip);
return $trip;
```
Method service baru:
```php
public function updateTitikDrop(string $idTrip, array $lokasiList, string $idPerusahaan): TripModel
{
    $trip = $this->findOrFail($idTrip, $idPerusahaan);
    if ($this->repo->tripPunyaFakturAktif($idTrip)) {
        abort(422, 'Trip sudah masuk faktur — titik drop tidak dapat diubah');
    }
    $this->repo->syncTitikDropTrip($idTrip, $lokasiList);
    return $trip;
}
```

`TripController::updateTitikDrop`:
```php
public function updateTitikDrop(Request $request, string $id): JsonResponse
{
    $validated = $request->validate([
        'titik_drop'   => ['present', 'array', 'max:10'],
        'titik_drop.*' => ['required', 'string', 'max:200'],
    ]);

    $this->service->updateTitikDrop($id, $validated['titik_drop'], (string) $request->user()->id_perusahaan);
    return ApiResponse::success(['titik_drop' => $validated['titik_drop']], 'Titik drop diperbarui');
}
```
Route (ServiceProvider Trip, sejajar route trip lain): `Route::put('trip/{id}/titik-drop', [TripController::class, 'updateTitikDrop']);`

`TripController::show` — lampirkan ke payload detail (ikuti bentuk respons show yang ada):
```php
$trip->titik_drop        = $this->service->titikDropTrip($id);          // delegasi ke repo
$trip->sudah_difakturkan = $this->service->tripPunyaFakturAktif($id);   // delegasi ke repo
```
(tambahkan dua method delegasi tipis di `TripService`). Pastikan resource/respons show menyertakan kedua field (kalau show memakai `TripResource`, tambahkan `'titik_drop' => $this->titik_drop ?? []` dan `'sudah_difakturkan' => (bool)($this->sudah_difakturkan ?? false)`).

`TripController::index` (untuk Trip Monitor): bulk-lampirkan `titik_drop` memakai `titikDropTripBanyak` atas id trip halaman tsb, pola sama seperti Penugasan Task 2.

- [ ] **Step 4: Run sampai PASS** — `vendor/bin/phpunit --filter=TripTitikDropTest`
- [ ] **Step 5: Regression** — `vendor/bin/phpunit --filter="TripMulaiTest|TripTest|TripPenugasanSinkronTest|StatusTripTest"` Expected: PASS.
- [ ] **Step 6: Checkpoint** — laporkan diff. Jangan commit.

---

### Task 4: LaporanPerjalanan — biaya_tagihan (add cost sisi klien)

**Files:**
- Create: `app/Modules/LaporanPerjalanan/BiayaTagihanTripModel.php`
- Create: `app/Modules/LaporanPerjalanan/Resources/BiayaTagihanTripResource.php`
- Modify: `app/Modules/LaporanPerjalanan/LaporanPerjalananModel.php` (relasi `biayaTagihan`)
- Modify: `app/Modules/LaporanPerjalanan/LaporanPerjalananRepository.php` + interface (`syncBiayaTagihan`, `reload` ikut relasi)
- Modify: `app/Modules/LaporanPerjalanan/LaporanPerjalananService.php` (`createForTrip`, `update`)
- Modify: `app/Modules/LaporanPerjalanan/Requests/StoreLaporanPerjalananRequest.php` (rules; JANGAN tambah ke request upsert supir — biaya tagihan domain ops)
- Modify: `app/Modules/LaporanPerjalanan/Resources/LaporanPerjalananResource.php`
- Test: `tests/Feature/BiayaTagihanTripTest.php` (baru)

**Interfaces:**
- Consumes: `TripRepositoryInterface::tripPunyaFakturAktif` (Task 3).
- Produces (dipakai Task 5 & 6): tabel `biaya_tagihan_trip` terisi; payload laporan `biaya_tagihan: [{nama_biaya, nominal}]`; resource `biaya_tagihan`.

- [ ] **Step 1: Failing test** `tests/Feature/BiayaTagihanTripTest.php` (setup ikuti `LaporanPerjalananTest`):

```php
public function test_simpan_dan_replace_biaya_tagihan(): void
{
    // create laporan via endpoint dgn biaya_tagihan [['nama_biaya'=>'Multidrop','nominal'=>150000]]
    // assert db 1 baris aktif; lalu update dgn [['nama_biaya'=>'TKBM','nominal'=>100000]]
    // assert baris aktif = TKBM saja
}

public function test_biaya_tagihan_tidak_mempengaruhi_total_realisasi_settlement(): void
{
    // laporan dgn uang_jalan 100000 + biaya_tagihan 150000
    // GET rekap-biaya/settlement trip (pola TripSettlementTest) → total_realisasi tetap 100000
}

public function test_biaya_tagihan_ditolak_bila_sudah_difakturkan(): void
{
    // hubungkan trip ke faktur draft (insert faktur + faktur_trip manual)
    // update laporan kirim biaya_tagihan → assertStatus(422)
}
```

- [ ] **Step 2: Run, FAIL** — `vendor/bin/phpunit --filter=BiayaTagihanTripTest`

- [ ] **Step 3: Implementasi**

`BiayaTagihanTripModel.php` (cermin `BiayaLainTripModel`):
```php
<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;

class BiayaTagihanTripModel extends BaseModel
{
    protected $table = 'biaya_tagihan_trip';
    protected $primaryKey = 'id_biaya_tagihan';

    protected $fillable = ['id_biaya_tagihan', 'id_laporan', 'nama_biaya', 'nominal'];

    protected $casts = ['nominal' => 'float'];
}
```

`LaporanPerjalananModel` — relasi (cermin `biayaLain`):
```php
public function biayaTagihan(): HasMany
{
    return $this->hasMany(BiayaTagihanTripModel::class, 'id_laporan', 'id_laporan')
        ->whereNull('dihapus_pada');
}
```

Repository: `reload` jadi `$model->fresh(['biayaLain', 'biayaTagihan', 'foto'])`; tambah (dan daftarkan di interface):
```php
public function syncBiayaTagihan(LaporanPerjalananModel $laporan, array $items): void
{
    BiayaTagihanTripModel::active()
        ->where('id_laporan', $laporan->id_laporan)
        ->each(fn (BiayaTagihanTripModel $item) => $item->softDelete());

    foreach ($items as $item) {
        BiayaTagihanTripModel::create([
            'id_laporan' => $laporan->id_laporan,
            'nama_biaya' => $item['nama_biaya'],
            'nominal'    => $item['nominal'],
        ]);
    }
}
```

`StoreLaporanPerjalananRequest` rules tambahan:
```php
'biaya_tagihan'              => ['sometimes', 'array', 'max:10'],
'biaya_tagihan.*.nama_biaya' => ['required_with:biaya_tagihan', 'string', 'max:100'],
'biaya_tagihan.*.nominal'    => ['required_with:biaya_tagihan', 'numeric', 'min:0'],
```

`LaporanPerjalananService::createForTrip` dan `::update` — pola identik `biaya_lain`:
```php
$hasBiayaTagihan = array_key_exists('biaya_tagihan', $data);
$biayaTagihan = $data['biaya_tagihan'] ?? [];
unset($data['biaya_tagihan']);
if ($hasBiayaTagihan && $this->tripRepo->tripPunyaFakturAktif((string) $record->id_trip)) {
    abort(422, 'Trip sudah masuk faktur — biaya tagihan tidak dapat diubah');
}
// ...setelah create/update record:
if ($hasBiayaTagihan) {
    $this->repo->syncBiayaTagihan($record, $biayaTagihan);
}
```
(Di `createForTrip` gunakan `$idTrip` langsung untuk guard.)

`BiayaTagihanTripResource` (cermin `BiayaLainTripResource`) + di `LaporanPerjalananResource`:
```php
'biaya_tagihan' => BiayaTagihanTripResource::collection($this->whenLoaded('biayaTagihan')),
```

- [ ] **Step 4: Run sampai PASS** — `vendor/bin/phpunit --filter=BiayaTagihanTripTest`
- [ ] **Step 5: Regression** — `vendor/bin/phpunit --filter="LaporanPerjalananTest|TripSettlementTest"` Expected: PASS.
- [ ] **Step 6: Checkpoint** — laporkan diff. Jangan commit.

---

### Task 5: KonsolidasiKlien — titik drop + biaya tambahan di rekap & export

**Files:**
- Modify: `app/Modules/KonsolidasiKlien/KonsolidasiKlienRepository.php` + `Contracts/KonsolidasiKlienRepositoryInterface.php`
- Modify: `app/Modules/KonsolidasiKlien/KonsolidasiKlienService.php`
- Modify: `app/Modules/KonsolidasiKlien/Exports/KonsolidasiKlienExport.php`
- Test: modifikasi/tambah di `tests/Feature/KonsolidasiKlienTest.php`

**Interfaces:**
- Consumes: `titik_drop_trip`, `biaya_tagihan_trip` (Task 3 & 4).
- Produces (dipakai frontend Task 8): tiap baris trip rekap punya `titik_drop: string[]` dan `biaya_tambahan: float`; `ringkasan.estimasi_nilai` = Σ tarif + Σ biaya_tambahan (baris bertarif).

- [ ] **Step 1: Failing test** — tambah di `KonsolidasiKlienTest`:

```php
public function test_rekap_menyertakan_titik_drop_dan_biaya_tambahan(): void
{
    // setup trip selesai ber-laporan (pola test yang sudah ada di file ini)
    // insert titik_drop_trip ['JLB','MRY'] + biaya_tagihan_trip 150000 utk laporan trip tsb
    // GET rekap → assertJsonPath trips.0.titik_drop ['JLB','MRY']
    //           → trips.0.biaya_tambahan 150000
    //           → ringkasan.estimasi_nilai = tarif + 150000
}
```

- [ ] **Step 2: Run, FAIL** — `vendor/bin/phpunit --filter=KonsolidasiKlienTest`

- [ ] **Step 3: Implementasi**

Repository — dua method baru (+ interface):
```php
public function titikDropPerTrip(array $idTrips): array
{
    if ($idTrips === []) {
        return [];
    }
    return DB::table('titik_drop_trip')
        ->whereIn('id_trip', $idTrips)->whereNull('dihapus_pada')
        ->orderBy('urutan')
        ->get(['id_trip', 'lokasi'])
        ->groupBy('id_trip')
        ->map(fn ($g) => $g->pluck('lokasi')->all())
        ->all();
}

public function biayaTagihanPerTrip(array $idTrips): array
{
    if ($idTrips === []) {
        return [];
    }
    return DB::table('biaya_tagihan_trip as bt')
        ->join('laporan_perjalanan as lp', 'lp.id_laporan', '=', 'bt.id_laporan')
        ->whereIn('lp.id_trip', $idTrips)
        ->whereNull('bt.dihapus_pada')->whereNull('lp.dihapus_pada')
        ->groupBy('lp.id_trip')
        ->selectRaw('lp.id_trip, SUM(bt.nominal) as total')
        ->pluck('total', 'id_trip')
        ->map(fn ($v) => (float) $v)
        ->all();
}
```

Service `rekap()` — setelah `$rows = $this->repo->tripKlien(...)` (sebelum map), ambil map:
```php
$idTrips   = array_map(fn ($r) => (string) $r->id_trip, $rows);
$dropMap   = $this->repo->titikDropPerTrip($idTrips);
$biayaMap  = $this->repo->biayaTagihanPerTrip($idTrips);
```
`mapBaris` menerima dua map tambahan (ubah signature private method) dan menambahkan:
```php
'titik_drop'     => $dropMap[$row->id_trip] ?? [],
'biaya_tambahan' => $biayaMap[$row->id_trip] ?? 0.0,
```
Ringkasan:
```php
'estimasi_nilai' => array_sum(array_map(fn ($t) => $t['tarif']['harga'] + $t['biaya_tambahan'], $bertarif)),
```

Export — headings jadi:
```php
['No', 'Tanggal', 'Proyek', 'Rute', 'Asal', 'Tujuan', 'Nopol', 'Supir', 'Sumber', 'Jarak (km)', 'Tarif (Rp)', 'Biaya Tambahan (Rp)', 'Total (Rp)', 'Status Tagihan']
```
Baris: sel Tujuan = `!empty($t['titik_drop']) ? implode(' → ', $t['titik_drop']) : ($t['tujuan'] ?? '-')`; kolom baru `$t['biaya_tambahan'] ?? 0` dan total `($t['tarif']['harga'] ?? 0) + ($t['biaya_tambahan'] ?? 0)`; baris TOTAL menjumlahkan kolom biaya & total juga.

- [ ] **Step 4: Run sampai PASS** — `vendor/bin/phpunit --filter=KonsolidasiKlienTest`
- [ ] **Step 5: Checkpoint** — laporkan diff. Jangan commit.

---

### Task 6: PenagihanTrip — nilai tagih + item faktur per biaya

**Files:**
- Modify: `app/Modules/PenagihanTrip/PenagihanTripRepository.php` + `Contracts/PenagihanTripRepositoryInterface.php`
- Modify: `app/Modules/PenagihanTrip/PenagihanTripService.php`
- Test: tambah di `tests/Feature/PenagihanTripTest.php`

**Interfaces:**
- Produces: baris `daftar()` punya `biaya_tagihan: [{nama_biaya, nominal}]` dan `total_biaya_tagihan: float`; `buatDraftFaktur` membuat item faktur per baris biaya.

- [ ] **Step 1: Failing test** — tambah di `PenagihanTripTest`:

```php
public function test_buat_faktur_menyertakan_item_biaya_tagihan(): void
{
    // trip siap tagih dgn tarif 900000 (pola test existing) + biaya_tagihan_trip 'Multidrop' 150000
    // POST buat faktur utk trip tsb
    // assert: faktur.total == 1050000
    // assert: ada faktur_item dgn deskripsi mengandung 'Multidrop' dan harga_satuan 150000
}
```

- [ ] **Step 2: Run, FAIL** — `vendor/bin/phpunit --filter=PenagihanTripTest`

- [ ] **Step 3: Implementasi**

Repository — method baru (+ interface), bentuk baris penuh (bukan cuma sum, karena jadi item faktur):
```php
public function biayaTagihanUntukTrips(array $idTrips): array
{
    if ($idTrips === []) {
        return [];
    }
    return DB::table('biaya_tagihan_trip as bt')
        ->join('laporan_perjalanan as lp', 'lp.id_laporan', '=', 'bt.id_laporan')
        ->whereIn('lp.id_trip', $idTrips)
        ->whereNull('bt.dihapus_pada')->whereNull('lp.dihapus_pada')
        ->orderBy('bt.dibuat_pada')
        ->get(['lp.id_trip', 'bt.nama_biaya', 'bt.nominal'])
        ->groupBy('id_trip')
        ->map(fn ($g) => $g->map(fn ($b) => ['nama_biaya' => $b->nama_biaya, 'nominal' => (float) $b->nominal])->values()->all())
        ->all();
}
```

Service — `daftar()`: ambil `$biayaMap = $this->repo->biayaTagihanUntukTrips(array id_trip)`; oper ke `mapBaris` (tambah parameter `array $biayaTagihan = []`), yang menambahkan:
```php
'biaya_tagihan'       => $biayaTagihan,
'total_biaya_tagihan' => array_sum(array_column($biayaTagihan, 'nominal')),
```

`buatDraftFaktur` — setelah `$items` group tarif, tambah item per biaya:
```php
$biayaMap = $this->repo->biayaTagihanUntukTrips(array_map(fn ($b) => (string) $b['id_trip'], $terpilih));
foreach ($terpilih as $baris) {
    foreach ($biayaMap[$baris['id_trip']] ?? [] as $biaya) {
        $items[] = [
            'deskripsi'    => "{$biaya['nama_biaya']} — {$baris['nopol']}, {$baris['tanggal']}",
            'qty'          => 1,
            'harga_satuan' => $biaya['nominal'],
        ];
    }
}
```
(pastikan blok ini sebelum `$this->fakturService->create`).

- [ ] **Step 4: Run sampai PASS** — `vendor/bin/phpunit --filter=PenagihanTripTest`
- [ ] **Step 5: Regression penuh backend** — `vendor/bin/phpunit` Expected: PASS semua (kalau ada test lain yang assert bentuk respons konsolidasi/penagihan, sesuaikan asersinya dengan field baru).
- [ ] **Step 6: Checkpoint** — laporkan diff. Jangan commit.

---

### Task 7: Frontend — input titik drop di dialog penugasan (internal & vendor)

**Files:**
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/penugasan.service.ts` (tipe `Penugasan` + payload: `titik_drop?: string[]`)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/penugasan/page.tsx` (dialog create/edit)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/vendor/PenugasanVendorTab.tsx` (dialog create/edit)

**Interfaces:**
- Consumes: API Task 2 (`titik_drop` di payload & resource penugasan).

- [ ] **Step 1: Implementasi UI (pola sama di kedua dialog)**

State + handler (di komponen dialog):
```tsx
const [titikDrop, setTitikDrop] = useState<string[]>([])
const tambahDrop  = () => setTitikDrop(prev => (prev.length < 10 ? [...prev, ''] : prev))
const ubahDrop    = (i: number, v: string) => setTitikDrop(prev => prev.map((d, idx) => (idx === i ? v : d)))
const hapusDrop   = (i: number) => setTitikDrop(prev => prev.filter((_, idx) => idx !== i))
```
JSX (letakkan setelah field rute/armada di dialog, sebelum tombol submit):
```tsx
<div className="mt-3">
    <div className="flex items-center justify-between mb-1">
        <p className="text-sm font-semibold">Titik Drop (opsional)</p>
        <Button type="button" size="xs" variant="plain" icon={<HiOutlinePlus />}
            disabled={titikDrop.length >= 10} onClick={tambahDrop}>Tambah Titik</Button>
    </div>
    <div className="flex flex-col gap-2">
        {titikDrop.map((lokasi, i) => (
            <div key={i} className="flex items-center gap-2">
                <span className="text-xs text-gray-400 w-5 text-right">{i + 1}.</span>
                <Input size="sm" placeholder={`Titik drop ${i + 1}...`} value={lokasi}
                    onChange={e => ubahDrop(i, e.target.value)} />
                <button type="button" onClick={() => hapusDrop(i)}
                    className="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/20 dark:text-red-400 transition-colors">
                    <HiOutlineTrash />
                </button>
            </div>
        ))}
    </div>
</div>
```
Payload submit: `titik_drop: titikDrop.map(d => d.trim()).filter(Boolean)`. Saat buka dialog edit: `setTitikDrop(record.titik_drop ?? [])`; saat buka dialog create: `setTitikDrop([])`.

- [ ] **Step 2: Verifikasi** — `npm run lint` di folder frontend (tanpa build). Expected: tidak ada error baru.
- [ ] **Step 3: Checkpoint** — laporkan diff; minta user rebuild & cek visual dialog penugasan internal + vendor. Jangan commit.

---

### Task 8: Frontend — detail trip (drop + biaya tagihan), trip monitor, konsolidasi klien

**Files:**
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/trip.service.ts` (field `titik_drop`, `sudah_difakturkan`; method `updateTitikDrop`)
- Modify: `TMN-TRANSPORT-FRONTEND/src/constants/api.constant.ts` (`TRIP_TITIK_DROP: (id) => \`/api/proxy/trip/${id}/titik-drop\``)
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/laporanPerjalanan service` yang dipakai halaman trip (tipe `biaya_tagihan`)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/trip/[id]/page.tsx`
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/trip/TripAktifTab.tsx`
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/konsolidasiKlien.service.ts` (tipe trip: `titik_drop: string[]`, `biaya_tambahan: number`)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/konsolidasi-klien/page.tsx`

**Interfaces:**
- Consumes: API Task 3 (PUT titik-drop, detail trip), Task 4 (payload `biaya_tagihan` laporan), Task 5 (field rekap).

- [ ] **Step 1: trip.service.ts**
```ts
async updateTitikDrop(idTrip: string, titikDrop: string[]) {
    const { data } = await axios.put(API_ENDPOINTS.TRIP_TITIK_DROP(idTrip), { titik_drop: titikDrop })
    return data.data as { titik_drop: string[] }
},
```
Tambah field pada tipe detail trip: `titik_drop?: string[]`, `sudah_difakturkan?: boolean`.

- [ ] **Step 2: trip/[id]/page.tsx**
- Tampilkan rangkaian drop di kartu info trip: `Asal → drop1 → drop2 …` (Tag kecil per titik, urut). Bila kosong → tampilkan tujuan rute seperti sekarang.
- Tombol "Ubah Titik Drop" (ikon `HiOutlinePencilAlt`, disabled bila `sudah_difakturkan`) membuka Dialog berisi daftar baris dinamis persis pola Task 7 → simpan via `tripService.updateTitikDrop`, toast sukses, refetch detail.
- Di bagian laporan perjalanan: blok "Biaya Tagihan Klien" — baris dinamis `nama_biaya` (Input) + `nominal` (Input prefix Rp, digit-only) + hapus, tombol "Tambah Biaya", maksimal 10; nilai awal dari `laporan.biaya_tagihan`; ikut payload penyimpanan laporan (`biaya_tagihan: rows.filter(r => r.nama_biaya.trim())`). Disabled + keterangan "Terkunci — trip sudah difakturkan" bila `sudah_difakturkan`.

- [ ] **Step 3: TripAktifTab.tsx** — di kolom/sel rute, bila `trip.titik_drop?.length` tampilkan baris kecil di bawah nama rute: `<p className="text-xs text-gray-400">{trip.titik_drop.join(' → ')}</p>`.

- [ ] **Step 4: konsolidasi-klien/page.tsx**
- Kolom Tujuan: `row.original.titik_drop?.length ? row.original.titik_drop.join(' → ') : (row.original.tujuan ?? '—')`.
- Kolom baru "Biaya Tambahan" (size 130, format `formatRupiah`, tampil '—' bila 0) setelah kolom Tarif.
- `totalEstimasi` trip terpilih: `acc + (t.tarif?.harga ?? 0) + (t.biaya_tambahan ?? 0)`.

- [ ] **Step 5: Verifikasi** — `npm run lint`. Expected: bersih.
- [ ] **Step 6: Checkpoint** — laporkan diff; minta user rebuild + `php artisan migrate`, lalu uji alur end-to-end: buat penugasan ber-drop → mulai trip → koreksi drop & isi biaya di detail trip → cek konsolidasi (kolom & total) → buat faktur → verifikasi item faktur & angka 900rb+150rb=1.050.000 → pastikan biaya terkunci setelah faktur. Jangan commit.
