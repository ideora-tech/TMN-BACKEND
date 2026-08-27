<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\InvoiceVendor\InvoiceVendorService;

class InvoiceVendorApprovalListener
{
    public function __construct(private readonly InvoiceVendorService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if ($event->kodeEventType !== 'invoice_vendor') {
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
