<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Helpers\ApiResponse;
use App\Modules\Approval\Requests\StoreConfigApproverRequest;
use App\Modules\Approval\Requests\StoreEventTypeRequest;
use App\Modules\Approval\Requests\UpdateEventTypeRequest;
use App\Modules\Approval\Resources\EventTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $service) {}

    public function indexEventType(Request $request): JsonResponse
    {
        $data = $this->service->listEventType((string) $request->user()->id_perusahaan);
        return ApiResponse::success(EventTypeResource::collection($data));
    }

    public function storeEventType(StoreEventTypeRequest $request): JsonResponse
    {
        $record = $this->service->createEventType(
            $request->validated(),
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(new EventTypeResource($record), 'Event type dibuat', 201);
    }

    public function updateEventType(UpdateEventTypeRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateEventType(
            $id,
            $request->validated(),
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(new EventTypeResource($record));
    }

    public function storeConfigApprover(StoreConfigApproverRequest $request, string $idEventType): JsonResponse
    {
        $idConfig = $this->service->tambahConfigApprover(
            $idEventType,
            $request->validated(),
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(['id_config' => $idConfig], 'Approver ditambahkan', 201);
    }

    public function destroyConfigApprover(Request $request, string $idEventType, string $idConfig): JsonResponse
    {
        $this->service->hapusConfigApprover(
            $idEventType,
            $idConfig,
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(null, 'Approver dihapus');
    }
}
