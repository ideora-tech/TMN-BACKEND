<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\Proyek\ProyekService;

class ProyekApprovalListener
{
    public function __construct(private readonly ProyekService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'proyek') {
            return;
        }

        $this->service->terapkanKeputusanApproval(
            $event->idReferensi,
            $event->idPerusahaan,
            $event->keputusan,
        );
    }
}
