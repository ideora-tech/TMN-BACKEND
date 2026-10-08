<?php
declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Helpers\ApiResponse;
use App\Modules\PembelianSparepart\PembelianSparepartService;
use App\Modules\PembelianSparepart\Resources\PembelianSparepartResource;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait MembuatPembelianLangsung
{
    protected function buatPembelianLangsung(array $payload): TestResponse
    {
        $bukti = $payload['bukti'] ?? [];
        unset($payload['bukti']);
        $idPerusahaan = (string) auth()->user()->id_perusahaan;

        try {
            $record = app(PembelianSparepartService::class)->create($payload, $bukti, $idPerusahaan);
        } catch (HttpExceptionInterface $e) {
            return TestResponse::fromBaseResponse(ApiResponse::error($e->getMessage(), null, $e->getStatusCode()));
        }

        return TestResponse::fromBaseResponse(
            ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan pembelian berhasil dibuat', 201)
        );
    }
}
