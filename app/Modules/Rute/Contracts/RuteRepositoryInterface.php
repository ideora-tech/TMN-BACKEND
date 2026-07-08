<?php
namespace App\Modules\Rute\Contracts;
use App\Modules\Rute\RuteModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RuteRepositoryInterface {
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator;
    public function findById(string $id): ?RuteModel;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?RuteModel;
    public function create(array $data): RuteModel;
    public function update(RuteModel $model, array $data): RuteModel;
    public function delete(RuteModel $model): void;
}