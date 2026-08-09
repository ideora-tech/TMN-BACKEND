<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Helpers\ApiResponse;
use App\Modules\JadwalShift\Exports\JadwalShiftTemplateExport;
use App\Modules\JadwalShift\Requests\ImportJadwalShiftRequest;
use App\Modules\JadwalShift\Requests\StoreJadwalShiftRequest;
use App\Modules\JadwalShift\Requests\UpdateJadwalShiftRequest;
use App\Modules\JadwalShift\Resources\JadwalShiftResource;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class JadwalShiftController extends Controller
{
    public function __construct(
        private readonly JadwalShiftService $service,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    public function downloadTemplate(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'id_proyek' => ['required', 'string'],
            'dari'      => ['required', 'date_format:Y-m-d'],
            'sampai'    => ['required', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $data = $this->service->templateData(
            $validated['id_proyek'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'],
            $validated['sampai'],
        );

        return Excel::download(
            new JadwalShiftTemplateExport($data['supir'], $data['tanggal'], $data['nama_proyek'], $data['periode']),
            'template-jadwal-shift.xlsx'
        );
    }

    public function import(ImportJadwalShiftRequest $request): JsonResponse
    {
        $result = $this->service->importMatriks(
            $request->file('file'),
            (string) $request->input('id_proyek'),
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success($result, 'Import jadwal shift selesai diproses');
    }

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

    public function hariIniSaya(Request $request): JsonResponse
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $jadwal = $this->service->hariIniSaya((string) $supir->id_supir);

        return ApiResponse::success($jadwal !== null ? [
            'shift_nama'   => $jadwal->shift_nama,
            'jam_mulai'    => $jadwal->jam_mulai,
            'jam_selesai'  => $jadwal->jam_selesai,
            'nama_proyek'  => $jadwal->nama_proyek,
        ] : null);
    }
}
