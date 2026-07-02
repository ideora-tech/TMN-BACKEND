<?php

declare(strict_types=1);

namespace App\Modules\LogError;

use App\Helpers\ApiResponse;
use App\Modules\LogError\Resources\LogErrorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LogErrorController extends Controller
{
    public function __construct(private readonly LogErrorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 20)
        );

        return ApiResponse::paginated(
            LogErrorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new LogErrorResource($this->service->findOrFail($id)));
    }
}
