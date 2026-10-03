<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip;

use App\Helpers\ApiResponse;
use App\Modules\ParameterTagihanTrip\Requests\SimpanParameterTagihanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ParameterTagihanTripController extends Controller
{
    public function __construct(private readonly ParameterTagihanTripService $service) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->detail($id, (string) $request->user()->id_perusahaan));
    }

    public function update(SimpanParameterTagihanRequest $request, string $id): JsonResponse
    {
        $data = $this->service->simpan($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success($data, 'Parameter tagihan berhasil disimpan');
    }
}
