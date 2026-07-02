<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Helpers\ApiResponse;
use App\Modules\Menu\Requests\StoreMenuRequest;
use App\Modules\Menu\Requests\UpdateMenuRequest;
use App\Modules\Menu\Resources\MenuResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MenuController extends Controller
{
    public function __construct(private readonly MenuService $service) {}

    public function index(Request $request): JsonResponse
    {
        $kodeModul = $request->get('kode_modul');

        if ($request->boolean('all') || $kodeModul !== null) {
            $data = $this->service->listAktif($kodeModul);
            return ApiResponse::success(MenuResource::collection($data));
        }

        $result = $this->service->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            MenuResource::collection($result['data']),
            $result['meta']
        );
    }

    public function tree(): JsonResponse
    {
        $data = $this->service->tree();
        return ApiResponse::success(MenuResource::collection($data));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new MenuResource($this->service->findOrFail($id)));
    }

    public function store(StoreMenuRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new MenuResource($record), 'Menu berhasil dibuat', 201);
    }

    public function update(UpdateMenuRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new MenuResource($record), 'Menu berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Menu berhasil dihapus');
    }
}
