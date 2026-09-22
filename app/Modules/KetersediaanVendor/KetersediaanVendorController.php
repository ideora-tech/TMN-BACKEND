<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor;

use App\Helpers\ApiResponse;
use App\Modules\KetersediaanVendor\Exports\KetersediaanVendorExport;
use App\Modules\KetersediaanVendor\Requests\FilterKetersediaanVendorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KetersediaanVendorController extends Controller
{
    public function __construct(private readonly KetersediaanVendorService $service) {}

    public function index(FilterKetersediaanVendorRequest $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            $request->filter(),
            $request->halaman(),
            $request->batas(),
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function show(Request $request, string $sumber, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->detail($sumber, $id, (string) $request->user()->id_perusahaan));
    }

    public function exportExcel(FilterKetersediaanVendorRequest $request): BinaryFileResponse
    {
        $data = $this->service->dataExport((string) $request->user()->id_perusahaan, $request->filter());

        return Excel::download(
            new KetersediaanVendorExport($data['unit'], $data['tanggal'], $data['status']),
            'ketersediaan-unit-' . date('Ymd') . '.xlsx'
        );
    }
}
