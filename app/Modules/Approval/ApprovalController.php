<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Helpers\ApiResponse;
use App\Modules\Approval\Exports\PersetujuanSayaWorkbookExport;
use App\Modules\Approval\Requests\KeputusanApprovalRequest;
use App\Modules\Approval\Requests\StoreConfigApproverRequest;
use App\Modules\Approval\Requests\StoreEventTypeRequest;
use App\Modules\Approval\Requests\UpdateEventTypeRequest;
use App\Modules\Approval\Resources\ApprovalPengajuanResource;
use App\Modules\Approval\Resources\ApprovalRiwayatSayaResource;
use App\Modules\Approval\Resources\EventTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function destroyEventType(Request $request, string $id): JsonResponse
    {
        $this->service->hapusEventType(
            $id,
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(null, 'Jenis pengajuan dihapus');
    }

    public function indexConfigApprover(Request $request, string $idEventType): JsonResponse
    {
        $data = $this->service->listConfigApprover($idEventType, (string) $request->user()->id_perusahaan);
        return ApiResponse::success($data);
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

    public function statusReferensi(Request $request): JsonResponse
    {
        $data = $this->service->statusUntukReferensi(
            (string) $request->query('kode'),
            (string) $request->query('id_referensi'),
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success($data);
    }

    public function uploadLampiran(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kode'         => ['required', 'string', 'max:100'],
            'id_referensi' => ['required', 'string', 'max:36'],
            'lampiran'     => ['required', 'array', 'min:1', 'max:10'],
            'lampiran.*'   => ['file', 'mimes:jpg,jpeg,png,pdf,xls,xlsx,doc,docx', 'max:5120'],
        ], [
            'lampiran.required' => 'Minimal 1 file lampiran wajib disertakan',
        ]);

        $data = $this->service->tambahLampiranUntukReferensi(
            $validated['kode'],
            $validated['id_referensi'],
            (string) $request->user()->id_perusahaan,
            $request->file('lampiran'),
        );

        return ApiResponse::success($data, 'Lampiran berhasil diunggah', 201);
    }

    public function menungguSaya(Request $request): JsonResponse
    {
        $data = $this->service->menungguApprovalSaya(
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(ApprovalPengajuanResource::collection($data));
    }

    public function riwayatSaya(Request $request): JsonResponse
    {
        $data = $this->service->riwayatApprovalSaya(
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(ApprovalRiwayatSayaResource::collection($data));
    }

    public function exportSaya(Request $request): BinaryFileResponse
    {
        $idPengguna = (string) $request->user()->id_pengguna;
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $menunggu = $this->service->menungguApprovalSaya($idPengguna, $idPerusahaan);
        $riwayat = $this->service->riwayatApprovalSaya($idPengguna, $idPerusahaan);

        return Excel::download(
            new PersetujuanSayaWorkbookExport($menunggu, $riwayat),
            'persetujuan-saya.xlsx'
        );
    }

    public function putuskan(KeputusanApprovalRequest $request, string $id): JsonResponse
    {
        $record = $this->service->putuskan(
            $id,
            (string) $request->user()->id_pengguna,
            $request->validated('keputusan'),
            $request->validated('catatan'),
            (string) $request->user()->id_perusahaan,
        );
        return ApiResponse::success(new ApprovalPengajuanResource($record));
    }
}
