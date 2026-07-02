<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Helpers\ApiResponse;
use App\Modules\KaryawanExit\Requests\StoreKaryawanExitRequest;
use App\Modules\KaryawanExit\Resources\KaryawanExitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class KaryawanExitController extends Controller
{
    public function __construct(private readonly KaryawanExitService $service) {}

    public function store(StoreKaryawanExitRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KaryawanExitResource($record), 'Data exit karyawan berhasil disimpan', 201);
    }
}
