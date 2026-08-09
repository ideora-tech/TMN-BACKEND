<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AbsensiSupirController extends Controller
{
    public function __construct(
        private readonly AbsensiSupirService $service,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    public function hariIniSaya(Request $request): JsonResponse
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        return ApiResponse::success($this->service->hariIni((string) $supir->id_supir));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'      => ['required', 'in:hadir,berhalangan'],
            'keterangan'  => ['nullable', 'string', 'max:500'],
            'foto'        => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'skor_wajah'  => ['nullable', 'numeric', 'between:0,1'],
            'wajah_cocok' => ['nullable', 'boolean'],
        ]);

        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $absen = $this->service->absen(
            (string) $supir->id_supir,
            (string) $request->user()->id_perusahaan,
            $validated['status'],
            $validated['keterangan'] ?? null,
            $request->file('foto'),
            isset($validated['skor_wajah']) ? (float) $validated['skor_wajah'] : null,
            isset($validated['wajah_cocok']) ? filter_var($validated['wajah_cocok'], FILTER_VALIDATE_BOOLEAN) : null,
        );

        return ApiResponse::success($absen, 'Absensi tercatat', 201);
    }
}
