<?php

declare(strict_types=1);

namespace App\Modules\Modul;

use App\Helpers\ApiResponse;
use App\Modules\Modul\Requests\StoreModulRequest;
use App\Modules\Modul\Requests\UpdateModulRequest;
use App\Modules\Modul\Resources\ModulResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ModulController extends Controller
{
    public function __construct(private readonly ModulService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            $data = $this->service->listAll();
            return ApiResponse::success(ModulResource::collection($data));
        }

        $result = $this->service->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            ModulResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new ModulResource($this->service->findOrFail($id)));
    }

    public function store(StoreModulRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new ModulResource($record), 'Modul berhasil dibuat', 201);
    }

    public function update(UpdateModulRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new ModulResource($record), 'Modul berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Modul berhasil dihapus');
    }
}
