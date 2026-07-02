<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir;

use App\Modules\BriefingSupir\Contracts\BriefingSupirRepositoryInterface;

class BriefingSupirService
{
    public function __construct(private readonly BriefingSupirRepositoryInterface $repo) {}

    public function list(string $idPenugasan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPenugasan($idPenugasan, $page, $limit);

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

    public function findOrFail(string $id): BriefingSupirModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Briefing supir tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): BriefingSupirModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): BriefingSupirModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
