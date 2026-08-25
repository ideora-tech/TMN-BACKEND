<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ApprovalDiputuskan
{
    use Dispatchable;

    public function __construct(
        public readonly string $kodeEventType,
        public readonly string $idReferensi,
        public readonly string $keputusan,
        public readonly ?string $alasanDitolak,
    ) {}
}
