<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan;

use App\Modules\PaketLangganan\Contracts\PaketLanggananRepositoryInterface;

class PaketLanggananService
{
    public function __construct(private readonly PaketLanggananRepositoryInterface $repo) {}

    public function list(int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginate($page, $limit);

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

    public function findOrFail(string $id): PaketLanggananModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Paket langganan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PaketLanggananModel
    {
        if ($this->repo->findByKode($data['kode_paket'])) {
            abort(409, 'Kode paket sudah digunakan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): PaketLanggananModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_paket']) && $data['kode_paket'] !== $record->kode_paket) {
            if ($this->repo->findByKode($data['kode_paket'])) {
                abort(409, 'Kode paket sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
