<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor;

use App\Helpers\ApiResponse;
use App\Modules\KonsolidasiVendor\Exports\KonsolidasiVendorExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KonsolidasiVendorController extends Controller
{
    public function __construct(private readonly KonsolidasiVendorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validasi($request);

        return ApiResponse::success($this->service->rekap(
            $validated['id_vendor'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
        ));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $validated = $this->validasi($request);

        $rekap = $this->service->rekap(
            $validated['id_vendor'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
        );

        $periode = ($validated['dari'] ?? null) || ($validated['sampai'] ?? null)
            ? 'Periode ' . ($validated['dari'] ?? '…') . ' s/d ' . ($validated['sampai'] ?? '…')
            : 'Semua periode';

        return Excel::download(
            new KonsolidasiVendorExport($rekap['vendor']['nama_vendor'], $periode, collect($rekap['trips'])),
            'konsolidasi-vendor.xlsx'
        );
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'id_vendor' => ['required', 'string', 'max:36'],
            'dari'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sampai'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
    }
}
