<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\Penawaran\PenawaranService;

class PenawaranApprovalListener
{
    public function __construct(private readonly PenawaranService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'penawaran') {
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
