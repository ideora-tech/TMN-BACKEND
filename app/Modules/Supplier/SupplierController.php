<?php
declare(strict_types=1);

namespace App\Modules\Supplier;

use App\Helpers\ApiResponse;
use App\Modules\Supplier\Requests\StoreSupplierRequest;
use App\Modules\Supplier\Requests\UpdateSupplierRequest;
use App\Modules\Supplier\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Support\Impor\ImporExcelRequest;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->has('aktif') ? (int) $request->get('aktif') : null
        );

        return ApiResponse::paginated(
            SupplierResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new SupplierResource($record));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new SupplierResource($record), 'Supplier berhasil dibuat', 201);
    }

    public function update(UpdateSupplierRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new SupplierResource($record), 'Supplier berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Supplier berhasil dihapus');
    }

    public function downloadTemplate(Request $request): BinaryFileResponse
    {
        return Excel::download(
            $this->service->templateImpor((string) $request->user()->id_perusahaan),
            'template-import-supplier.xlsx'
        );
    }

    public function import(ImporExcelRequest $request): JsonResponse
    {
        $hasil = $this->service->import($request->file('file'), (string) $request->user()->id_perusahaan);

        return ApiResponse::success($hasil, 'Import supplier selesai diproses');
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(
            $this->service->exportExcel((string) $request->user()->id_perusahaan),
            'data-supplier-' . date('Ymd') . '.xlsx'
        );
    }
}
