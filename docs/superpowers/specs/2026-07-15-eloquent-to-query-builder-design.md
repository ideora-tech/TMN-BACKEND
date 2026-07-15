# Migrasi Eloquent ORM ke Query Builder — Desain

**Tanggal:** 2026-07-15
**Status:** Disetujui, siap masuk fase perencanaan implementasi

## Latar Belakang

Backend TMN Transport (Laravel 11) dibangun di atas Eloquent ORM di seluruh 37+ modul (`app/Modules/*`). Setiap modul punya `*Model.php` yang extends `App\Models\BaseModel`, yang lewat trait (`HasUuidPrimaryKey`, `HasAuditColumns`, `HasSoftDeleteColumns`) otomatis menangani:

- Generate UUID primary key saat `creating`
- Isi kolom audit (`dibuat_pada`, `dibuat_oleh`, `diubah_pada`, `diubah_oleh`) saat `creating`/`updating`
- Filter soft-delete via `scopeActive()` (`whereNull('dihapus_pada')`)
- `softDelete()` untuk isi `dihapus_pada`/`dihapus_oleh`

Keputusan: pindah seluruh query dari Eloquent Model ke Laravel Query Builder (`DB::table()`) murni, karena preferensi eksplisit tim untuk menghindari abstraksi ORM (relationship magic, model events tersembunyi, N+1 yang sulit dilacak).

Satu modul (KontrakVendor) sudah dikonversi sebagai pola rujukan: Repository memakai `DB::table()` untuk query dasar + query builder manual untuk lookup nama vendor (menggantikan `belongsTo`/`with()`), tanpa Eloquent relationship sama sekali.

## Cakupan

### Dihapus total
Semua `*Model.php` di `app/Modules/*` (Armada, Trip, Karyawan, dst) — dihapus sepenuhnya, bukan disisakan sebagai class dokumentasi. Repository berbicara langsung ke tabel via `DB::table('nama_tabel')`.

### Dikecualikan — Auth & Pengguna
`App\Models\Pengguna` (dipakai oleh modul `Auth` dan `Pengguna`) **tetap Eloquent**. Alasan: class ini extends `Illuminate\Foundation\Auth\User` dan memakai trait `Laravel\Sanctum\HasApiTokens`, yang secara arsitektur mensyaratkan model Eloquent (Sanctum membuat relasi `tokens()` ke tabel `personal_access_tokens` dan bergantung pada mekanisme Eloquent untuk `$user->createToken()`). Memaksa modul ini ke Query Builder akan merusak sistem login/token.

Modul lain yang butuh identitas user login (misal `auth()->id()` untuk kolom audit) tidak terpengaruh — mereka cuma butuh ID dari session, bukan instance model Pengguna.

## Komponen Baru

### `app/Support/RecordHelper.php`

Class statis biasa (bukan trait, bukan dicampur ke model apa pun) — pengganti eksplisit untuk 3 perilaku otomatis BaseModel:

```php
final class RecordHelper
{
    public static function stampCreate(array $data, string $primaryKey): array
    {
        $data[$primaryKey] ??= (string) Str::uuid();
        $data['dibuat_pada'] = now();
        $data['dibuat_oleh'] = auth()->id();
        return $data;
    }

    public static function stampUpdate(array $data): array
    {
        $data['diubah_pada'] = now();
        $data['diubah_oleh'] = auth()->id();
        return $data;
    }

    public static function stampDelete(): array
    {
        return [
            'dihapus_pada' => now(),
            'dihapus_oleh' => auth()->id(),
        ];
    }
}
```

Repository memanggil ini eksplisit sebelum `insert()`/`update()`. Tidak ada hook otomatis — semua terlihat jelas di kode pemanggil.

### Pola Repository standar (pengganti Eloquent)

```php
public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
{
    return DB::table('armada')
        ->whereNull('dihapus_pada')
        ->where('id_perusahaan', $idPerusahaan)
        ->orderBy('dibuat_pada', 'desc')
        ->paginate($limit, ['*'], 'page', $page);
}

public function findById(string $id): ?object
{
    // DB::table()->find() default-nya cari kolom "id" — tabel ini pakai
    // primary key custom (id_armada), jadi harus where() eksplisit, bukan find().
    return DB::table('armada')->whereNull('dihapus_pada')->where('id_armada', $id)->first();
}

public function create(array $data): object
{
    $data = RecordHelper::stampCreate($data, 'id_armada');
    DB::table('armada')->insert($data);
    return $this->findById($data['id_armada']);
}

public function update(string $id, array $data): object
{
    DB::table('armada')->where('id_armada', $id)->update(RecordHelper::stampUpdate($data));
    return $this->findById($id);
}

public function delete(string $id): void
{
    DB::table('armada')->where('id_armada', $id)->update(RecordHelper::stampDelete());
}
```

`DB::table()->paginate()` dan `->find()` didukung native oleh Laravel Query Builder — tidak perlu Eloquent.

### Tipe data pengganti Model

Semua `Contracts/*RepositoryInterface.php` dan `*Service.php` yang sebelumnya type-hint `?ArmadaModel`/`ArmadaModel` diganti `?object`/`object`. `stdClass` hasil `DB::table()` sudah terbukti kompatibel langsung dengan `JsonResource` (Resource baca `$this->nama_kolom` tanpa perubahan) — dikonfirmasi lewat fix KontrakVendor.

### Relasi/join manual

14 file yang masih pakai `belongsTo`/`hasMany`/`with()`/`whenLoaded` (Departemen, Faktur, Karyawan, LaporanPerjalanan, Menu, Rekonsiliasi) dikonversi ke lookup manual via `DB::table()`, pola sama seperti `attachNamaVendor()` di `KontrakVendorRepository` — satu query batch `whereIn()` per halaman, ditempel sebagai field tambahan ke tiap record, dibaca langsung oleh Resource.

## Rollout Bertahap

Tanpa test otomatis (cuma `ExampleTest.php` bawaan), migrasi dilakukan per kelompok — tiap kelompok diverifikasi (rebuild Docker + smoke test API manual + `route:list` bersih) sebelum lanjut ke kelompok berikutnya. Sekaligus menambahkan PHPUnit feature test dasar (list/create/update/delete) per modul yang dikonversi, menutup kekosongan test coverage sambil jalan.

1. **Pilot** — JenisKendaraan, LokasiKantor, Departemen, Jabatan, StatusTrip
2. **HR & Master** — Karyawan, KaryawanExit, Supir, Rute, Klien, Vendor
3. **Fleet** — Armada, DokumenArmada, PerawatanArmada, Penawaran, Proyek
4. **Operasional** — Trip, Penugasan, JadwalKeberangkatan, BriefingSupir, EvaluasiTrip, LaporanProyek, LaporanPerjalanan, plus modul yang dibuat user sendiri di luar sesi ini (BiayaLainTrip, FotoLaporanPerjalanan, DokumenVendor, LaporanOperasional) — daftar final diinventarisir ulang di awal eksekusi fase ini karena modul masih bertambah
5. **Keuangan & Sistem** — Faktur, Rekonsiliasi, Perusahaan, Menu, Peran, IzinPeran, Notifikasi, LogError, Dashboard

**Sudah selesai (di luar fase ini):** KontrakVendor (dipakai sebagai pola rujukan).

**Dikecualikan permanen:** Auth, Pengguna (lihat bagian Cakupan).

## Verifikasi per Kelompok

1. `docker compose -f docker-compose.local.yml build backend`
2. Restart container, cek `docker logs` bersih (tidak crash-loop)
3. `php artisan route:list` — pastikan boot tanpa error kelas hilang
4. Smoke test tiap endpoint yang diubah via `curl` + token superadmin (replikasi skenario list/create/update/delete)
5. Jalankan PHPUnit feature test yang ditambahkan untuk kelompok tersebut

## Risiko & Catatan

- **Modul terus bertambah** selama migrasi berjalan (user aktif mengedit backend di luar sesi Claude) — daftar modul per kelompok bisa berubah; inventarisir ulang sebelum mulai tiap kelompok, bukan cuma sekali di awal.
- **`softDelete()` dipanggil di banyak tempat** di luar Repository (perlu digrep ulang saat eksekusi) — pastikan semua caller pindah ke pola baru, bukan cuma Repository method-nya.
- **`$casts` Eloquent** (misal `'aktif' => 'boolean'`, `'nilai_kontrak' => 'float'`) hilang begitu Model dihapus — Resource harus melakukan cast manual (`(bool)`, `(float)`) sendiri, sudah jadi kebiasaan di banyak Resource yang ada.
- **Auth/Pengguna dikecualikan permanen** — bukan "nanti juga dikonversi", ini keputusan arsitektur final karena batasan Sanctum.
