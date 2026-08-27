<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\ArusKas\ArusKasService;

class ArusKasApprovalListener
{
    public function __construct(private readonly ArusKasService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType === ArusKasService::KODE_PERSETUJUAN_TRANSFER) {
            $this->service->terapkanKeputusanPersetujuanTransfer(
                $event->idReferensi,
                $event->idPerusahaan,
                $event->keputusan,
                $event->alasanDitolak,
                $event->idPengguna,
            );
            return;
        }

        if (!in_array($event->kodeEventType, ArusKasService::KODE_EVENT_PENGELUARAN, true)) {
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
