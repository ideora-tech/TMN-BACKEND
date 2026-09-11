<?php

declare(strict_types=1);

namespace App\Modules\Supir;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Collection;

class RiwayatSupirService
{
    private const MAKS_BARIS_EXPORT = 10000;

    public function __construct(
        private readonly SupirService $supirService,
        private readonly PenugasanRepositoryInterface $penugasanRepo,
        private readonly TripRepositoryInterface $tripRepo,
    ) {}

    /** @return array{supir: object, data: Collection} */
    public function riwayatArmada(string $idSupir, string $idPerusahaan): array
    {
        $supir = $this->supirService->findOrFail($idSupir, $idPerusahaan);

        return [
            'supir' => $supir,
            'data'  => collect($this->penugasanRepo->riwayatArmadaSupir($idSupir)),
        ];
    }

    /** @return array{supir: object, data: Collection} */
    public function riwayatTrip(string $idSupir, string $idPerusahaan): array
    {
        $supir = $this->supirService->findOrFail($idSupir, $idPerusahaan);

        return [
            'supir' => $supir,
            'data'  => $this->tripRepo->paginate($idPerusahaan, 1, self::MAKS_BARIS_EXPORT, null, null, $idSupir)->getCollection(),
        ];
    }
}
