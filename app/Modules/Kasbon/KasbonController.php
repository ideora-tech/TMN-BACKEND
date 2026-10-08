<?php

declare(strict_types=1);

namespace App\Modules\Kasbon;

use App\Helpers\ApiResponse;
use App\Modules\Kasbon\Exports\KasbonExport;
use App\Modules\Kasbon\Requests\CatatPelunasanKasbonRequest;
use App\Modules\Kasbon\Requests\StoreKasbonRequest;
use App\Modules\Kasbon\Requests\UbahCicilanKasbonRequest;
use App\Modules\Kasbon\Requests\UpdateKasbonRequest;
use App\Modules\Kasbon\Resources\KasbonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KasbonController extends Controller
{
    public function __construct(private readonly KasbonService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            max(1, (int) $request->get('page', 1)),
            min(100, max(1, (int) $request->get('limit', 10))),
            $this->teks($request, 'search'),
            $this->teks($request, 'status'),
            $this->teks($request, 'id_karyawan'),
        );

        return ApiResponse::paginated(KasbonResource::collection($result['data']), $result['meta']);
    }

    public function ringkasan(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->ringkasan((string) $request->user()->id_perusahaan));
    }

    public function opsiKaryawan(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->opsiKaryawan((string) $request->user()->id_perusahaan));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $status = $this->teks($request, 'status');
        $rows   = $this->service->semua(
            (string) $request->user()->id_perusahaan,
            $this->teks($request, 'search'),
            $status,
            $this->teks($request, 'id_karyawan'),
        );

        return Excel::download(new KasbonExport($rows, $status), 'kasbon-' . date('Ymd') . '.xlsx');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new KasbonResource($this->service->detail($id, (string) $request->user()->id_perusahaan)));
    }

    public function riwayat(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->riwayat($id, (string) $request->user()->id_perusahaan));
    }

    public function store(StoreKasbonRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasbonResource($record), 'Kasbon berhasil dibuat', 201);
    }

    public function update(UpdateKasbonRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasbonResource($record), 'Kasbon berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Kasbon berhasil dihapus');
    }

    public function ubahCicilan(UbahCicilanKasbonRequest $request, string $id): JsonResponse
    {
        $record = $this->service->ubahCicilan($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasbonResource($record), 'Cicilan kasbon berhasil diperbarui');
    }

    public function catatPelunasan(CatatPelunasanKasbonRequest $request, string $id): JsonResponse
    {
        $record = $this->service->catatPelunasan($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasbonResource($record), 'Pelunasan kasbon berhasil dicatat', 201);
    }

    public function hapusPelunasan(Request $request, string $id, string $idPembayaran): JsonResponse
    {
        $record = $this->service->hapusPelunasan($id, $idPembayaran, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasbonResource($record), 'Pelunasan kasbon berhasil dihapus');
    }

    private function teks(Request $request, string $kunci): ?string
    {
        $nilai = $request->query($kunci);

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
