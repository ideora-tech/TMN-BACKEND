<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Helpers\ApiResponse;
use App\Modules\Faktur\Resources\FakturResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PenagihanTripController extends Controller
{
    public function __construct(private readonly PenagihanTripService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_proyek' => ['required', 'string', 'max:36'],
            'dari'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sampai'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $rows = $this->service->daftar(
            $validated['id_proyek'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
        );

        return ApiResponse::success($rows);
    }

    public function buatFaktur(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_proyek'      => ['required', 'string', 'max:36'],
            'trip_ids'       => ['required', 'array', 'min:1'],
            'trip_ids.*'     => ['required', 'string', 'max:36'],
            'tanggal_faktur' => ['required', 'date'],
            'jatuh_tempo'    => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_faktur'],
            'keterangan'     => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $faktur = $this->service->buatDraftFaktur($validated, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new FakturResource($faktur), 'Draft invoice berhasil dibuat', 201);
    }
}
