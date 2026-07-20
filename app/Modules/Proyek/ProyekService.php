<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\ProyekRute\ProyekRuteService;
use Illuminate\Support\Facades\DB;

class ProyekService
{
    private const ALLOWED_STATUSES = ['draft', 'aktif', 'selesai', 'batal'];

    public function __construct(
        private readonly ProyekRepositoryInterface $repo,
        private readonly PenawaranRepositoryInterface $penawaranRepo,
        private readonly PenawaranItemRepositoryInterface $penawaranItemRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly ProyekRuteService $proyekRuteService,
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

    public function listByKlien(string $idKlien, int $page = 1, int $limit = 20): array
    {
        $result = $this->repo->paginateByKlien($idKlien, $page, $limit);

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

    public function findOrFail(string $id): ProyekModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ProyekModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
            abort(409, 'Kode proyek sudah digunakan');
        }

        $idPenawaran = $data['id_penawaran'] ?? null;
        $ruteManual  = $data['rute'] ?? [];
        unset($data['id_penawaran'], $data['rute']);

        return DB::transaction(function () use ($data, $idPenawaran, $ruteManual, $idPerusahaan) {
            $proyek = $this->repo->create($data);

            if ($idPenawaran !== null) {
                $penawaran = $this->penawaranRepo->findById($idPenawaran);
                if ($penawaran !== null && $penawaran->id_perusahaan === $idPerusahaan) {
                    $this->penawaranRepo->update($penawaran, ['id_proyek' => $proyek->id_proyek]);
                    $this->salinRuteDariPenawaran($proyek->id_proyek, $idPenawaran, $idPerusahaan);
                }
            }

            foreach ($ruteManual as $baris) {
                $this->proyekRuteService->create($proyek->id_proyek, $baris, $idPerusahaan);
            }

            return $proyek;
        });
    }

    private function salinRuteDariPenawaran(string $idProyek, string $idPenawaran, string $idPerusahaan): void
    {
        foreach ($this->penawaranItemRepo->listByPenawaran($idPenawaran) as $item) {
            $this->proyekRuteRepo->create([
                'id_perusahaan'      => $idPerusahaan,
                'id_proyek'          => $idProyek,
                'id_rute'            => $item->id_rute,
                'id_jenis_kendaraan' => $item->id_jenis_kendaraan,
                'id_tarif_rute'      => $item->id_tarif_rute,
                'harga_penawaran'    => $item->harga_satuan,
            ]);
        }
    }

    public function update(string $id, array $data, string $idPerusahaan): ProyekModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_proyek']) && $data['kode_proyek'] !== $record->kode_proyek) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
                abort(409, 'Kode proyek sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function updateStatus(string $id, string $status): ProyekModel
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
        $this->repo->delete($record);
    }
}
