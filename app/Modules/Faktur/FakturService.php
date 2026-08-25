<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;
use App\Modules\Faktur\Contracts\FakturRepositoryInterface;

class FakturService
{
    private const ALLOWED_STATUSES = ['draft', 'terkirim', 'lunas', 'batal'];

    public function __construct(
        private readonly FakturRepositoryInterface $repo,
        private readonly FakturItemRepositoryInterface $itemRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status);
        $this->attachDibuatOlehNama($result->items());

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

    public function listByKlien(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 20): array
    {
        $result = $this->repo->paginateByKlien($idKlien, $idPerusahaan, $page, $limit);
        $this->attachDibuatOlehNama($result->items());

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

    /** @param FakturModel[] $items */
    private function attachDibuatOlehNama(array $items): void
    {
        $idPenggunaList = array_values(array_unique(array_filter(
            array_map(fn (FakturModel $item) => $item->dibuat_oleh, $items)
        )));
        $namaMap = $this->repo->namaPengguna($idPenggunaList);

        foreach ($items as $item) {
            $item->dibuat_oleh_nama = $item->dibuat_oleh !== null ? ($namaMap[$item->dibuat_oleh] ?? null) : null;
        }
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Invoice tidak ditemukan');
        }
        return $record;
    }

    public function untukCetak(string $id, string $idPerusahaan): FakturModel
    {
        return $this->denganReferensi($this->findOrFail($id, $idPerusahaan));
    }

    /**
     * Lengkapi faktur dengan nama proyek/klien dan referensi penawaran yang
     * jadi dasar harganya — jejak audit "tarif invoice ini dari kesepakatan
     * mana" untuk halaman detail maupun cetakan PDF/Excel.
     */
    public function denganReferensi(FakturModel $record): FakturModel
    {
        $idPerusahaan = (string) $record->id_perusahaan;

        $record->nama_klien  = $record->id_klien ? $this->repo->namaKlien((string) $record->id_klien, $idPerusahaan) : null;
        $record->nama_proyek = $record->id_proyek ? $this->repo->namaProyek((string) $record->id_proyek, $idPerusahaan) : null;

        $penawaran = $record->id_penawaran
            ? $this->repo->infoPenawaran((string) $record->id_penawaran, $idPerusahaan)
            : null;
        $record->nomor_penawaran = $penawaran->nomor_penawaran ?? null;
        $record->nilai_penawaran = isset($penawaran->nilai_penawaran) ? (float) $penawaran->nilai_penawaran : null;

        return $record;
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    public function create(array $data): FakturModel
    {
        if ($this->repo->findByNomor($data['nomor_faktur'], $data['id_perusahaan'])) {
            abort(409, 'Nomor invoice sudah digunakan');
        }

        $items = $data['items'] ?? [];
        $subtotal = collect($items)->sum(fn($i) => $i['qty'] * $i['harga_satuan']);
        $persenPajak = $data['persen_pajak'] ?? null;
        $data['total'] = $subtotal + ($persenPajak ? $subtotal * $persenPajak / 100 : 0);
        unset($data['items']);

        $faktur = $this->repo->create($data);

        foreach ($items as $item) {
            $item['id_faktur'] = $faktur->id_faktur;
            $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
            $this->itemRepo->create($item);
        }

        $this->repo->insertStatusLog((string) $faktur->id_faktur, (string) ($data['status'] ?? 'draft'), 'Invoice dibuat');

        return $this->repo->findById($faktur->id_faktur);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['nomor_faktur']) && $data['nomor_faktur'] !== $record->nomor_faktur) {
            if ($this->repo->findByNomor($data['nomor_faktur'], $record->id_perusahaan)) {
                abort(409, 'Nomor invoice sudah digunakan');
            }
        }

        // If items are provided, replace them
        $itemsBerubah = isset($data['items']);
        if ($itemsBerubah) {
            $items = $data['items'];
            unset($data['items']);

            $this->itemRepo->deleteByFaktur($record->id_faktur);

            foreach ($items as $item) {
                $item['id_faktur'] = $record->id_faktur;
                $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
                $this->itemRepo->create($item);
            }
        }

        // Total dihitung ulang bila item ATAU persen pajak berubah — subtotal
        // diambil dari item baru (bila diganti) atau item existing di DB.
        if ($itemsBerubah || array_key_exists('persen_pajak', $data)) {
            $subtotal = $itemsBerubah
                ? collect($items)->sum(fn ($i) => $i['qty'] * $i['harga_satuan'])
                : (float) $record->items()->sum('subtotal');
            $persenPajak = array_key_exists('persen_pajak', $data) ? $data['persen_pajak'] : $record->persen_pajak;
            $data['total'] = $subtotal + ($persenPajak ? $subtotal * $persenPajak / 100 : 0);
        }

        $updated = $this->repo->update($record, $data);
        $this->repo->insertStatusLog((string) $record->id_faktur, 'diedit', 'Data invoice diubah');

        return $updated;
    }

    public function denganAudit(FakturModel $record): FakturModel
    {
        $nama = $this->repo->namaPengguna(array_values(array_filter([
            $record->dibuat_oleh,
            $record->diubah_oleh,
        ])));

        $record->dibuat_oleh_nama = $record->dibuat_oleh !== null ? ($nama[$record->dibuat_oleh] ?? null) : null;
        $record->diubah_oleh_nama = $record->diubah_oleh !== null ? ($nama[$record->diubah_oleh] ?? null) : null;

        return $record;
    }

    public function denganTripTerkait(FakturModel $record): FakturModel
    {
        $record->trip_terkait = $this->repo->tripTerkait((string) $record->id_faktur);

        return $record;
    }

    public function updateStatus(string $id, string $status, ?string $idPerusahaan = null): FakturModel
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            abort(422, 'Status tidak valid');
        }

        $record = $this->findOrFail($id, $idPerusahaan);

        $updated = $this->repo->update($record, ['status' => $status]);
        $this->repo->insertStatusLog((string) $record->id_faktur, $status);

        return $updated;
    }

    public function riwayatStatus(FakturModel $record): array
    {
        $rows = $this->repo->listStatusLog((string) $record->id_faktur);

        $adaLogAwal = false;
        foreach ($rows as $row) {
            if ($row['status'] === 'draft') {
                $adaLogAwal = true;
                break;
            }
        }

        if (!$adaLogAwal) {
            $nama = $this->repo->namaPengguna(array_values(array_filter([$record->dibuat_oleh])));
            array_unshift($rows, [
                'status'     => 'draft',
                'keterangan' => 'Invoice dibuat',
                'waktu'      => $record->dibuat_pada,
                'oleh'       => $record->dibuat_oleh !== null ? ($nama[$record->dibuat_oleh] ?? null) : null,
            ]);
        }

        return $rows;
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->itemRepo->deleteByFaktur($record->id_faktur);
        $this->repo->delete($record);
    }
}
