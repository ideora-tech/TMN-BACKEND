<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\PermintaanVendor\PermintaanVendorService;

class PermintaanVendorApprovalListener
{
    public function __construct(private readonly PermintaanVendorService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'permintaan_vendor') {
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
