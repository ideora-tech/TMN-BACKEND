<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\Faktur\FakturService;

class FakturApprovalListener
{
    public function __construct(private readonly FakturService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'faktur') {
            return;
        }

        $this->service->terapkanKeputusanApproval(
            $event->idReferensi,
            $event->idPerusahaan,
            $event->idPengguna,
            $event->keputusan,
            $event->alasanDitolak,
        );
    }
}
