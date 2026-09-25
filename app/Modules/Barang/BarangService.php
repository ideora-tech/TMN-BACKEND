<?php

declare(strict_types=1);

namespace App\Modules\Barang;

use App\Modules\Barang\Contracts\BarangRepositoryInterface;
use App\Modules\Notifikasi\NotifikasiService;
use App\Support\KodeOtomatis;
use Illuminate\Support\Facades\DB;

class BarangService
{
    public const SATUAN_SARAN = ['pcs', 'box', 'rim', 'pak', 'lusin', 'set', 'unit', 'liter', 'kg', 'meter'];

    private const MENU_MASTER_BARANG = ['/master-barang'];

    public function __construct(
        private readonly BarangRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasiService,
    ) {}

    public function list(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategori, bool $stokMenipis): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $idKategori, $stokMenipis);
        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
                'ringkasan'  => ['stok_menipis' => $this->repo->hitungMenipis($idPerusahaan)],
            ],
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Barang tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        $this->pastikanKategoriMilik($data['id_kategori_barang'] ?? null, $idPerusahaan);
        return $this->repo->create([
            'id_perusahaan'      => $idPerusahaan,
            'kode'               => KodeOtomatis::berikutnya($idPerusahaan, 'barang'),
            'nama'               => trim((string) $data['nama']),
            'id_kategori_barang' => $data['id_kategori_barang'] ?? null,
            'satuan'             => $data['satuan'] ?? 'pcs',
            'harga_standar'      => (float) ($data['harga_standar'] ?? 0),
            'stok'               => 0,
            'stok_minimum'       => (int) ($data['stok_minimum'] ?? 0),
            'aktif'              => (int) ($data['aktif'] ?? 1),
        ]);
    }

    public function buatCepat(array $data, string $idPerusahaan): object
    {
        return $this->create($data, $idPerusahaan);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        if (array_key_exists('id_kategori_barang', $data)) {
            $this->pastikanKategoriMilik($data['id_kategori_barang'], $idPerusahaan);
        }
        $ubah = [];
        foreach (['nama', 'id_kategori_barang', 'satuan', 'harga_standar', 'stok_minimum', 'aktif'] as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $ubah[$kolom] = $data[$kolom];
            }
        }
        if (isset($ubah['aktif'])) {
            $ubah['aktif'] = (int) $ubah['aktif'];
        }
        return $this->repo->update($record, $ubah);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        if ($this->repo->dipakaiRelasiLain($record->id_barang)) {
            abort(422, 'Barang sudah dipakai di mutasi stok atau permintaan pembelian, tidak bisa dihapus');
        }
        $this->repo->softDelete($record);
    }

    public function listMutasi(string $id, string $idPerusahaan, int $page, int $limit): array
    {
        $this->findOrFail($id, $idPerusahaan);
        $result = $this->repo->paginateMutasi($id, $page, $limit);
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

    public function pemakaian(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);
        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $terkunci = $this->repo->findByIdForUpdate($id);
            $qty = (int) $data['qty'];
            if ((int) $terkunci->stok < $qty) {
                abort(422, "Stok {$terkunci->nama} tidak cukup (tersedia {$terkunci->stok} {$terkunci->satuan})");
            }
            $stokBaru = (int) $terkunci->stok - $qty;
            $this->repo->setStok($id, $stokBaru);
            $this->repo->insertMutasi([
                'id_barang'  => $id,
                'jenis'      => 'keluar',
                'qty'        => $qty,
                'harga'      => null,
                'pemakai'    => $data['pemakai'],
                'keterangan' => $data['keterangan'] ?? null,
                'tanggal'    => $data['tanggal'],
            ]);
            $this->peringatanStokMenipis($terkunci, $stokBaru, $idPerusahaan);
            return $this->findOrFail($id, $idPerusahaan);
        });
    }

    public function penyesuaian(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);
        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $terkunci = $this->repo->findByIdForUpdate($id);
            $stokBaru = (int) $data['stok_baru'];
            $delta = $stokBaru - (int) $terkunci->stok;
            if ($delta === 0) {
                abort(422, 'Stok baru sama dengan stok saat ini');
            }
            $this->repo->setStok($id, $stokBaru);
            $this->repo->insertMutasi([
                'id_barang'  => $id,
                'jenis'      => 'penyesuaian',
                'qty'        => $delta,
                'harga'      => null,
                'keterangan' => $data['keterangan'],
                'tanggal'    => now()->toDateString(),
            ]);
            return $this->findOrFail($id, $idPerusahaan);
        });
    }

    public function tambahStokDariPenerimaan(string $idBarang, int $qty, float $harga, string $idPermintaan, string $nomorPermintaan, string $tanggal): void
    {
        $terkunci = $this->repo->findByIdForUpdate($idBarang);
        if ($terkunci === null) {
            abort(422, 'Barang pada permintaan tidak ditemukan');
        }
        $this->repo->setStok($idBarang, (int) $terkunci->stok + $qty);
        $this->repo->insertMutasi([
            'id_barang'               => $idBarang,
            'jenis'                   => 'masuk',
            'qty'                     => $qty,
            'harga'                   => $harga,
            'id_permintaan_pembelian' => $idPermintaan,
            'keterangan'              => 'Penerimaan ' . $nomorPermintaan,
            'tanggal'                 => $tanggal,
        ]);
    }

    public function perbaruiHargaStandar(string $idBarang, float $harga): void
    {
        $this->repo->setHargaStandar($idBarang, $harga);
    }

    /** @return array<string, object> */
    public function pastikanMilik(array $ids, string $idPerusahaan): array
    {
        $hasil = [];
        foreach (array_unique($ids) as $id) {
            $record = $this->repo->findById((string) $id);
            if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
                abort(422, 'Barang tidak ditemukan di perusahaan Anda');
            }
            $hasil[$record->id_barang] = $record;
        }
        return $hasil;
    }

    public function listKategori(string $idPerusahaan, bool $hanyaAktif): array
    {
        return $this->repo->listKategori($idPerusahaan, $hanyaAktif);
    }

    public function createKategori(array $data, string $idPerusahaan): object
    {
        if ($this->repo->findKategoriByNama($idPerusahaan, $data['nama']) !== null) {
            abort(422, 'Nama kategori sudah dipakai');
        }
        return $this->repo->createKategori([
            'id_perusahaan' => $idPerusahaan,
            'nama'          => trim((string) $data['nama']),
            'keterangan'    => $data['keterangan'] ?? null,
            'aktif'         => (int) ($data['aktif'] ?? 1),
        ]);
    }

    public function updateKategori(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->kategoriOrFail($id, $idPerusahaan);
        if (isset($data['nama']) && $this->repo->findKategoriByNama($idPerusahaan, $data['nama'], $id) !== null) {
            abort(422, 'Nama kategori sudah dipakai');
        }
        $ubah = [];
        foreach (['nama', 'keterangan', 'aktif'] as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $ubah[$kolom] = $kolom === 'aktif' ? (int) $data[$kolom] : $data[$kolom];
            }
        }
        return $this->repo->updateKategori($record, $ubah);
    }

    public function deleteKategori(string $id, string $idPerusahaan): void
    {
        $record = $this->kategoriOrFail($id, $idPerusahaan);
        if ($this->repo->kategoriDipakai($id)) {
            abort(422, 'Kategori masih dipakai oleh barang, tidak bisa dihapus');
        }
        $this->repo->softDeleteKategori($record);
    }

    private function kategoriOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findKategori($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kategori barang tidak ditemukan');
        }
        return $record;
    }

    private function pastikanKategoriMilik(?string $idKategori, string $idPerusahaan): void
    {
        if ($idKategori === null || $idKategori === '') {
            return;
        }
        $this->kategoriOrFail($idKategori, $idPerusahaan);
    }

    private function peringatanStokMenipis(object $barang, int $stokBaru, string $idPerusahaan): void
    {
        if ((int) $barang->stok_minimum <= 0 || $stokBaru >= (int) $barang->stok_minimum) {
            return;
        }
        $this->notifikasiService->kirimKePemilikIzinMenu(
            self::MENU_MASTER_BARANG,
            $idPerusahaan,
            "Stok {$barang->nama} menipis",
            "Sisa {$stokBaru} {$barang->satuan}, di bawah minimum {$barang->stok_minimum}",
            'stok_barang',
            'barang',
            (string) $barang->id_barang,
            '/master-barang',
            null,
            'ubah',
        );
    }
}
