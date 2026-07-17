<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok;

use App\Helpers\ApiResponse;
use App\Modules\ParameterBok\Requests\StoreParameterBokRequest;
use App\Modules\ParameterBok\Requests\UpdateParameterBokRequest;
use App\Modules\ParameterBok\Resources\ParameterBokResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ParameterBokController extends Controller
{
    public function __construct(private readonly ParameterBokService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            $request->query('search'),
        );

        return ApiResponse::paginated(ParameterBokResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findDetailOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new ParameterBokResource($record));
    }

    public function store(StoreParameterBokRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan],
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new ParameterBokResource($record), 'Parameter BOK berhasil ditambahkan', 201);
    }

    public function update(UpdateParameterBokRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new ParameterBokResource($record), 'Parameter BOK berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Parameter BOK berhasil dihapus');
    }
}
