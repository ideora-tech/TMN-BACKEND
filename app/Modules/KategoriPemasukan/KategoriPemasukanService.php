<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan;

use App\Modules\KategoriPemasukan\Contracts\KategoriPemasukanRepositoryInterface;
use Illuminate\Support\Str;

/**
 * Master kategori pemasukan manual (Kas Bank). `pemasukan.kategori` menyimpan `kode`;
 * `pos_psak` menentukan baris laporan arus kas PSAK tempat pemasukan itu dijumlahkan.
 */
class KategoriPemasukanService
{
    public const POS_PSAK = [
        'pelanggan'    => 'Operasi — Penerimaan dari pelanggan',
        'pengembalian' => 'Operasi — Penerimaan pengembalian dana',
        'operasi_lain' => 'Operasi — Penerimaan operasional lainnya',
        'investasi'    => 'Investasi — Penerimaan penjualan aset',
        'pendanaan'    => 'Pendanaan — Penerimaan modal/pinjaman',
    ];

    /** Kategori bawaan: kodenya dipakai langsung oleh fitur lain (mis. Kasbon → pengembalian_dana). */
    public const BAWAAN = [
        'pendapatan_jasa'   => ['nama' => 'Pendapatan Jasa',   'pos_psak' => 'pelanggan',    'urutan' => 1],
        'penjualan_aset'    => ['nama' => 'Penjualan Aset',    'pos_psak' => 'investasi',    'urutan' => 2],
        'pengembalian_dana' => ['nama' => 'Pengembalian Dana', 'pos_psak' => 'pengembalian', 'urutan' => 3],
        'modal_pinjaman'    => ['nama' => 'Modal/Pinjaman',    'pos_psak' => 'pendanaan',    'urutan' => 4],
        'lainnya'           => ['nama' => 'Lainnya',           'pos_psak' => 'operasi_lain', 'urutan' => 99],
    ];

    /** Kode yang dipakai sebagai nilai khusus di laporan (filter "Pembayaran Klien"). */
    private const KODE_TERLARANG = ['invoice', 'faktur'];

    private const PANJANG_KODE = 30;

    /** @var array<string, bool> */
    private array $sudahDilengkapi = [];

    public function __construct(private readonly KategoriPemasukanRepositoryInterface $repo) {}

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

    /** @return array<string, string> kode → nama */
    public function petaLabel(string $idPerusahaan): array
    {
        return collect($this->opsi($idPerusahaan))->mapWithKeys(fn (object $k) => [(string) $k->kode => (string) $k->nama])->all();
    }

    /** @return array<string, string> kode → pos_psak */
    public function petaPosPsak(string $idPerusahaan): array
    {
        return collect($this->opsi($idPerusahaan))->mapWithKeys(fn (object $k) => [(string) $k->kode => (string) $k->pos_psak])->all();
    }

    /** Kode boleh dipilih di form pemasukan: ada, dan aktif (atau sama dengan kode lama saat edit). */
    public function kodeBolehDipakai(string $idPerusahaan, string $kode, ?string $kodeLama = null): bool
    {
        $this->pastikanBawaan($idPerusahaan);
        $record = $this->repo->findByKode($idPerusahaan, $kode);

        return $record !== null && ((int) $record->aktif === 1 || $kode === $kodeLama);
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kategori pemasukan tidak ditemukan');
        }

        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        $this->pastikanBawaan($idPerusahaan);
        $this->tolakNamaGanda($idPerusahaan, (string) $data['nama']);

        return $this->repo->create([
            'id_perusahaan' => $idPerusahaan,
            'kode'          => $this->kodeBaru($idPerusahaan, (string) $data['nama']),
            'nama'          => trim((string) $data['nama']),
            'pos_psak'      => $data['pos_psak'],
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
        if (array_key_exists('pos_psak', $data)) {
            $ubah['pos_psak'] = $data['pos_psak'];
        }
        if (array_key_exists('aktif', $data)) {
            $ubah['aktif'] = (int) (bool) $data['aktif'];
        }

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
            abort(422, "Kategori masih dipakai di {$dipakai} pemasukan, tidak bisa dihapus. Nonaktifkan saja agar tidak muncul di pilihan.");
        }

        $this->repo->delete($record);
    }

    /** Lengkapi kategori bawaan yang belum ada (perusahaan baru / data lama). */
    public function pastikanBawaan(string $idPerusahaan): void
    {
        if (isset($this->sudahDilengkapi[$idPerusahaan])) {
            return;
        }

        $ada = $this->repo->kodeTerpakai($idPerusahaan);
        foreach (self::BAWAAN as $kode => $isi) {
            if (in_array($kode, $ada, true)) {
                continue;
            }
            $this->repo->create([
                'id_perusahaan' => $idPerusahaan,
                'kode'          => $kode,
                'nama'          => $isi['nama'],
                'pos_psak'      => $isi['pos_psak'],
                'sistem'        => 1,
                'aktif'         => 1,
                'urutan'        => $isi['urutan'],
            ]);
        }

        $this->sudahDilengkapi[$idPerusahaan] = true;
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
        $terpakai = array_merge($this->repo->kodeTerpakai($idPerusahaan), self::KODE_TERLARANG, array_keys(self::BAWAAN));

        $kode = $dasar;
        for ($i = 2; in_array($kode, $terpakai, true); $i++) {
            $kode = $dasar . '_' . $i;
        }

        return $kode;
    }
}
