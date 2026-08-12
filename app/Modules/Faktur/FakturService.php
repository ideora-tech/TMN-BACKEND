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

    public function findOrFail(string $id, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Faktur tidak ditemukan');
        }
        return $record;
    }

    public function untukCetak(string $id, string $idPerusahaan): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $record->nama_klien  = $record->id_klien ? $this->repo->namaKlien((string) $record->id_klien, $idPerusahaan) : null;
        $record->nama_proyek = $record->id_proyek ? $this->repo->namaProyek((string) $record->id_proyek, $idPerusahaan) : null;

        return $record;
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    public function create(array $data): FakturModel
    {
        if ($this->repo->findByNomor($data['nomor_faktur'], $data['id_perusahaan'])) {
            abort(409, 'Nomor faktur sudah digunakan');
        }

        $items = $data['items'] ?? [];
        $total = collect($items)->sum(fn($i) => $i['qty'] * $i['harga_satuan']);
        $data['total'] = $total;
        unset($data['items']);

        $faktur = $this->repo->create($data);

        foreach ($items as $item) {
            $item['id_faktur'] = $faktur->id_faktur;
            $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
            $this->itemRepo->create($item);
        }

        return $this->repo->findById($faktur->id_faktur);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['nomor_faktur']) && $data['nomor_faktur'] !== $record->nomor_faktur) {
            if ($this->repo->findByNomor($data['nomor_faktur'], $record->id_perusahaan)) {
                abort(409, 'Nomor faktur sudah digunakan');
            }
        }

        // If items are provided, replace them
        if (isset($data['items'])) {
            $items = $data['items'];
            $total = collect($items)->sum(fn($i) => $i['qty'] * $i['harga_satuan']);
            $data['total'] = $total;
            unset($data['items']);

            $this->itemRepo->deleteByFaktur($record->id_faktur);

            foreach ($items as $item) {
                $item['id_faktur'] = $record->id_faktur;
                $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
                $this->itemRepo->create($item);
            }
        }

        return $this->repo->update($record, $data);
    }

    public function updateStatus(string $id, string $status, ?string $idPerusahaan = null): FakturModel
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            abort(422, 'Status tidak valid');
        }

        $record = $this->findOrFail($id, $idPerusahaan);

        return $this->repo->update($record, ['status' => $status]);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->itemRepo->deleteByFaktur($record->id_faktur);
        $this->repo->delete($record);
    }
}
