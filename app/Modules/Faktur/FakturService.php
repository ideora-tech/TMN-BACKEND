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

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

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

    public function findOrFail(string $id): FakturModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Faktur tidak ditemukan');
        }
        return $record;
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

    public function update(string $id, array $data): FakturModel
    {
        $record = $this->findOrFail($id);

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

    public function updateStatus(string $id, string $status): FakturModel
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            abort(422, 'Status tidak valid');
        }

        $record = $this->findOrFail($id);

        return $this->repo->update($record, ['status' => $status]);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->itemRepo->deleteByFaktur($record->id_faktur);
        $this->repo->delete($record);
    }
}
