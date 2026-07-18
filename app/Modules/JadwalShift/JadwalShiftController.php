<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Helpers\ApiResponse;
use App\Modules\JadwalShift\Requests\StoreJadwalShiftRequest;
use App\Modules\JadwalShift\Requests\UpdateJadwalShiftRequest;
use App\Modules\JadwalShift\Resources\JadwalShiftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class JadwalShiftController extends Controller
{
    public function __construct(private readonly JadwalShiftService $service) {}

    public function index(Request $request): JsonResponse
    {
        Validator::make($request->query(), [
            'id_proyek' => ['required', 'string'],
            'dari'      => ['sometimes', 'date'],
            'sampai'    => ['sometimes', 'date'],
        ])->validate();

        $rows = $this->service->list(
            (string) $request->get('id_proyek'),
            (string) $request->user()->id_perusahaan,
            $request->get('dari'),
            $request->get('sampai')
        );

        return ApiResponse::success(JadwalShiftResource::collection(collect($rows)));
    }

    public function store(StoreJadwalShiftRequest $request): JsonResponse
    {
        $hasil = $this->service->createBatch(
            $request->validated(),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success($hasil, 'Jadwal shift diproses');
    }

    public function update(UpdateJadwalShiftRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateShift($id, (string) $request->validated()['id_shift']);
        return ApiResponse::success(new JadwalShiftResource($record), 'Jadwal shift berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jadwal shift berhasil dihapus');
    }
}
