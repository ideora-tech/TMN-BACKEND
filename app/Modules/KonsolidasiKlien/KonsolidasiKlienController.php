<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Helpers\ApiResponse;
use App\Modules\KonsolidasiKlien\Exports\KonsolidasiKlienExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KonsolidasiKlienController extends Controller
{
    public function __construct(private readonly KonsolidasiKlienService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validasi($request);

        return ApiResponse::success($this->service->rekap(
            $validated['id_klien'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
            $validated['sumber'] ?? null,
            $validated['id_proyek'] ?? null,
        ));
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $validated = $this->validasi($request);

        $rekap = $this->service->rekap(
            $validated['id_klien'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
            $validated['sumber'] ?? null,
            $validated['id_proyek'] ?? null,
        );

        $periode = ($validated['dari'] ?? null) || ($validated['sampai'] ?? null)
            ? 'Periode ' . ($validated['dari'] ?? '…') . ' s/d ' . ($validated['sampai'] ?? '…')
            : 'Semua periode';

        return Excel::download(
            new KonsolidasiKlienExport($rekap['klien']['nama_klien'], $periode, collect($rekap['trips'])),
            'konsolidasi-klien.xlsx'
        );
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'id_klien'  => ['required', 'string', 'max:36'],
            'dari'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sampai'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sumber'    => ['sometimes', 'nullable', 'in:internal,vendor'],
            'id_proyek' => ['sometimes', 'nullable', 'string'],
        ]);
    }
}
