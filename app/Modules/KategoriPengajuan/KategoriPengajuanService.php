<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\KategoriPengajuan\Contracts\KategoriPengajuanRepositoryInterface;
use Illuminate\Support\Str;

/**
 * Master kategori pengajuan pengeluaran untuk dropdown form Tambah Pengajuan (manual).
 * Kategori otomatis dari modul (uang jalan, gaji, vendor, sparepart, PR, tambal ban, ...)
 * tetap ditentukan kode dan tidak masuk master.
 */
class KategoriPengajuanService
{
    /** Kategori bawaan: tidak bisa dihapus; kodenya juga dikenal kode lama (label/approval). */
    public const BAWAAN = [
        'legalitas'           => ['nama' => 'Legalitas',           'urutan' => 1],
        'perawatan'           => ['nama' => 'Perawatan',           'urutan' => 2],
        'pembelian_aset'      => ['nama' => 'Pembelian Aset',      'urutan' => 3],
        'pembayaran_pinjaman' => ['nama' => 'Pembayaran Pinjaman', 'urutan' => 4],
        'lainnya'             => ['nama' => 'Lainnya',             'urutan' => 99],
    ];

    /** Kode yang dipakai sistem untuk kategori otomatis / kode approval — tidak boleh dipakai kategori baru. */
    private const KODE_TERLARANG = ['pengajuan_pengeluaran', 'pengeluaran_supir', 'persetujuan_transfer', 'invoice'];

    private const PANJANG_KODE = 40;

    /** @var array<string, bool> */
    private array $sudahDilengkapi = [];

    /** @var array<string, array<string, string>> id_perusahaan → (kode → nama) */
    private array $cacheLabel = [];

    public function __construct(private readonly KategoriPengajuanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): array
    {
        $this->pastikanBawaan($idPerusahaan);
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    /** @return list<object> */
    public function opsi(string $idPerusahaan, bool $hanyaAktif = false): array
    {
        $this->pastikanBawaan($idPerusahaan);

        return $this->repo->semuaByPerusahaan($idPerusahaan, $hanyaAktif)->all();
    }

    /** @return array<string, string> kode → nama (semua kategori master perusahaan) */
    public function petaLabel(string $idPerusahaan): array
    {
        return $this->cacheLabel[$idPerusahaan] ??= collect($this->opsi($idPerusahaan))
            ->mapWithKeys(fn (object $k) => [(string) $k->kode => (string) $k->nama])
            ->all();
    }

    /**
     * Nama kategori dari master untuk perusahaan pengguna yang login. Dipakai label
     * kategori di seluruh modul; null bila tidak ada konteks login atau kode bukan master.
     */
    public function labelUntukPenggunaAktif(string $kode): ?string
    {
        $idPerusahaan = auth()->user()?->id_perusahaan;
        if ($idPerusahaan === null || $kode === '') {
            return null;
        }

        return $this->petaLabel((string) $idPerusahaan)[$kode] ?? null;
    }

    /** @return array<string, string> kode → nama untuk perusahaan pengguna yang login */
    public function petaLabelPenggunaAktif(): array
    {
        $idPerusahaan = auth()->user()?->id_perusahaan;

        return $idPerusahaan !== null ? $this->petaLabel((string) $idPerusahaan) : [];
    }

    /**
     * Kode kategori boleh dipakai pengajuan manual. Kode yang ada di master harus aktif
     * (atau sama dengan kode lama saat edit); kode lain harus termasuk $kodeTetap.
     *
     * @param list<string> $kodeTetap kategori non-master yang boleh dipilih lewat API
     */
    public function kodeBolehDipakai(string $idPerusahaan, string $kode, array $kodeTetap, ?string $kodeLama = null): bool
    {
        $this->pastikanBawaan($idPerusahaan);
        $record = $this->repo->findByKode($idPerusahaan, $kode);
        if ($record !== null) {
            return (int) $record->aktif === 1 || $kode === $kodeLama;
        }

        return in_array($kode, $kodeTetap, true);
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kategori pengajuan tidak ditemukan');
        }

        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        $this->pastikanBawaan($idPerusahaan);
        $this->tolakNamaGanda($idPerusahaan, (string) $data['nama']);
        unset($this->cacheLabel[$idPerusahaan]);

        return $this->repo->create([
            'id_perusahaan' => $idPerusahaan,
            'kode'          => $this->kodeBaru($idPerusahaan, (string) $data['nama']),
            'nama'          => trim((string) $data['nama']),
            'sistem'        => 0,
            'aktif'         => array_key_exists('aktif', $data) ? (int) (bool) $data['aktif'] : 1,
            'urutan'        => 50,
        ]);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $ubah = [];
        if (array_key_exists('nama', $data)) {
            $this->tolakNamaGanda($idPerusahaan, (string) $data['nama'], $id);
            $ubah['nama'] = trim((string) $data['nama']);
        }
        if (array_key_exists('aktif', $data)) {
            $ubah['aktif'] = (int) (bool) $data['aktif'];
        }
        unset($this->cacheLabel[$idPerusahaan]);

        return $ubah === [] ? $record : $this->repo->update($record, $ubah);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ((int) $record->sistem === 1) {
            abort(422, 'Kategori bawaan tidak bisa dihapus. Nonaktifkan saja bila tidak dipakai.');
        }
        $dipakai = $this->repo->jumlahPemakaian($idPerusahaan, (string) $record->kode);
        if ($dipakai > 0) {
            abort(422, "Kategori masih dipakai di {$dipakai} pengajuan, tidak bisa dihapus. Nonaktifkan saja agar tidak muncul di pilihan.");
        }

        $this->repo->delete($record);
        unset($this->cacheLabel[$idPerusahaan]);
    }

    /** Lengkapi kategori bawaan yang belum ada (perusahaan baru). */
    public function pastikanBawaan(string $idPerusahaan): void
    {
        if (isset($this->sudahDilengkapi[$idPerusahaan])) {
            return;
        }
        $this->sudahDilengkapi[$idPerusahaan] = true;

        $ada = $this->repo->kodeTerpakai($idPerusahaan);
        foreach (self::BAWAAN as $kode => $isi) {
            if (in_array($kode, $ada, true)) {
                continue;
            }
            $this->repo->create([
                'id_perusahaan' => $idPerusahaan,
                'kode'          => $kode,
                'nama'          => $isi['nama'],
                'sistem'        => 1,
                'aktif'         => 1,
                'urutan'        => $isi['urutan'],
            ]);
        }
    }

    private function tolakNamaGanda(string $idPerusahaan, string $nama, ?string $kecualiId = null): void
    {
        if ($this->repo->findByNama($idPerusahaan, $nama, $kecualiId) !== null) {
            abort(422, 'Nama kategori "' . trim($nama) . '" sudah ada');
        }
    }

    private function kodeBaru(string $idPerusahaan, string $nama): string
    {
        $dasar = Str::limit(Str::slug($nama, '_'), self::PANJANG_KODE - 3, '') ?: 'kategori';
        $terpakai = array_merge(
            $this->repo->kodeTerpakai($idPerusahaan),
            array_keys(ArusKasService::daftarLabelKategoriSistem()),
            self::KODE_TERLARANG,
        );

        $kode = $dasar;
        for ($i = 2; in_array($kode, $terpakai, true); $i++) {
            $kode = $dasar . '_' . $i;
        }

        return $kode;
    }
}
