<?php
declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Testing\TestResponse;

trait MenerbitkanPo
{
    protected function payloadPesan(array $payloadDibeli): array
    {
        $payload = [
            'id_supplier' => $payloadDibeli['id_supplier'] ?? null,
            'tanggal_po'  => $payloadDibeli['tanggal_po'] ?? $payloadDibeli['tanggal_pembelian'] ?? now()->toDateString(),
            'items'       => $payloadDibeli['items'] ?? [],
        ];
        foreach (['diskon', 'ppn_persen', 'ongkir'] as $komponen) {
            if (array_key_exists($komponen, $payloadDibeli)) {
                $payload[$komponen] = $payloadDibeli[$komponen];
            }
        }
        return $payload;
    }

    protected function terbitkanPo(string $idPermintaan, array $payloadDibeli): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$idPermintaan}/pesan", $this->payloadPesan($payloadDibeli));
    }
}
