<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir;

use App\Helpers\ApiResponse;
use App\Modules\FeeSupir\Exports\FeeSupirExport;
use App\Modules\FeeSupir\Requests\FeeSupirRekapRequest;
use App\Modules\FeeSupir\Requests\FeeSupirUrutanTripRequest;
use App\Modules\FeeSupir\Requests\FeeSupirSayaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeeSupirController extends Controller
{
    public function __construct(private readonly FeeSupirService $service) {}

    public function feeSaya(FeeSupirSayaRequest $request): JsonResponse
    {
        $bulan = $request->validated()['bulan'] ?? null;

        return ApiResponse::success($this->service->feeSaya((string) $request->user()->id_pengguna, $bulan));
    }

    public function urutanTrip(FeeSupirUrutanTripRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->urutanTrip($request->validated(), (string) $request->user()->id_perusahaan));
    }

    public function index(FeeSupirRekapRequest $request): JsonResponse
    {
        $hasil = $this->service->rincian((string) $request->user()->id_perusahaan, $request->filter(), $request->halaman(), $request->batas());

        return ApiResponse::paginated($hasil['data'], $hasil['meta']);
    }

    public function rekap(FeeSupirRekapRequest $request): JsonResponse
    {
        $hasil = $this->service->rekap((string) $request->user()->id_perusahaan, $request->filter(), $request->halaman(), $request->batas());

        return ApiResponse::paginated($hasil['data'], $hasil['meta']);
    }

    public function ringkasan(FeeSupirRekapRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->ringkasan((string) $request->user()->id_perusahaan, $request->filter()));
    }

    public function exportExcel(FeeSupirRekapRequest $request): BinaryFileResponse
    {
        $laporan = $this->service->dataExport((string) $request->user()->id_perusahaan, $request->filter());
        $nama    = 'fee-supir-' . str_replace('-', '', $laporan['dari']) . '-' . str_replace('-', '', $laporan['sampai']) . '.xlsx';

        return Excel::download(new FeeSupirExport($laporan), $nama);
    }
}
