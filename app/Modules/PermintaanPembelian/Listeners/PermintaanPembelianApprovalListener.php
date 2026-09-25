<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Listeners;

use App\Events\ApprovalDiputuskan;
use App\Modules\PermintaanPembelian\PermintaanPembelianService;

class PermintaanPembelianApprovalListener
{
    public function __construct(private readonly PermintaanPembelianService $service) {}

    public function handle(ApprovalDiputuskan $event): void
    {
        if (!in_array($event->kodeEventType, [PermintaanPembelianService::KODE_APPROVAL, PermintaanPembelianService::KODE_APPROVAL_ASET], true)) {
            return;
        }
        $this->service->terapkanKeputusanApproval($event->idReferensi, $event->idPerusahaan, $event->keputusan, $event->alasanDitolak);
    }
}
