<?php
namespace App\Modules\Dashboard;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller {
    public function __construct(private readonly DashboardService $service) {}

    public function stats(Request $request): JsonResponse {
        $id = $request->user()->id_perusahaan;

        return ApiResponse::success($this->service->getStats($id));
    }
}
