<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Helpers\ApiResponse;
use App\Modules\Supir\Exports\SupirTemplateExport;
use App\Modules\Supir\Requests\ImportSupirRequest;
use App\Modules\Supir\Requests\StoreSupirRequest;
use App\Modules\Supir\Requests\UpdateSupirRequest;
use App\Modules\Supir\Resources\SupirResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupirController extends Controller
{
    public function __construct(private readonly SupirService $service) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new SupirTemplateExport(), 'template-import-supir.xlsx');
    }

    public function import(ImportSupirRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->import($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Import supir selesai diproses');
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->list($idPerusahaan, (int) $request->get('page', 1), (int) $request->get('limit', 10));
        return ApiResponse::paginated(SupirResource::collection($result['data']), $result['meta']);
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new SupirResource($this->service->findOrFail($id)));
    }

    public function me(Request $request): JsonResponse
    {
        $record = $this->service->findByPenggunaOrFail((string) $request->user()->id_pengguna);
        return ApiResponse::success(new SupirResource($record));
    }

    public function store(StoreSupirRequest $request): JsonResponse
    {
        $data = array_merge($request->validated(), ['id_perusahaan' => (string) $request->user()->id_perusahaan]);
        $record = $this->service->create($data);
        return ApiResponse::success(new SupirResource($record), 'Supir berhasil dibuat', 201);
    }

    public function update(UpdateSupirRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new SupirResource($record), 'Supir berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Supir berhasil dihapus');
    }
}
