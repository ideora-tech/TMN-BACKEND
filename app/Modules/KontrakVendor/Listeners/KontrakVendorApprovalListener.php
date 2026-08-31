<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\KontrakVendor\KontrakVendorService;

class KontrakVendorApprovalListener
{
    public function __construct(private readonly KontrakVendorService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'kontrak_vendor') {
            return;
        }

        $this->service->terapkanKeputusanApproval(
            $event->idReferensi,
            $event->idPerusahaan,
            $event->keputusan,
            $event->alasanDitolak,
        );
    }
}
