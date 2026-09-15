<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Helpers\ApiResponse;
use App\Modules\Penugasan\Exports\PenugasanUnitTemplateExport;
use App\Modules\Penugasan\Requests\AssignPenugasanHarianRequest;
use App\Modules\Penugasan\Requests\SinkronPenugasanProyekRequest;
use App\Modules\Penugasan\Requests\StorePenugasanRequest;
use App\Modules\Penugasan\Requests\UpdatePenugasanRequest;
use App\Modules\Penugasan\Resources\PenugasanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PenugasanController extends Controller
{
    public function __construct(private readonly PenugasanService $service) {}

    public function opsiArmadaVendor(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->opsiArmadaVendor($idPerusahaan));
    }

    public function templateUnit(): BinaryFileResponse
    {
        return Excel::download(new PenugasanUnitTemplateExport(), 'template-penugasan-unit.xlsx');
    }

    public function parseUnit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'      => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
            'id_proyek' => ['required', 'string'],
        ]);

        $result = $this->service->parseUnitExcel(
            $request->file('file'),
            (string) $validated['id_proyek'],
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success($result, 'File unit selesai diproses');
    }

    public function assignHarian(AssignPenugasanHarianRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $hasil = $this->service->assignHarian($request->validated(), $idPerusahaan);

        return ApiResponse::success([
            'sukses'     => $hasil['sukses'],
            'gagal'      => $hasil['gagal'],
            'dilewati'   => $hasil['dilewati'],
            'peringatan' => $hasil['peringatan'],
            'penugasan'  => PenugasanResource::collection($hasil['penugasan']),
        ], 'Penugasan harian diproses');
    }

    public function pratinjauSinkronProyek(SinkronPenugasanProyekRequest $request, string $idProyek, SinkronPenugasanProyekService $sinkron): JsonResponse
    {
        return ApiResponse::success(
            $sinkron->pratinjau($idProyek, (string) $request->user()->id_perusahaan, $request->validated())
        );
    }

    public function sinkronProyek(SinkronPenugasanProyekRequest $request, string $idProyek, SinkronPenugasanProyekService $sinkron): JsonResponse
    {
        return ApiResponse::success(
            $sinkron->jalankan($idProyek, (string) $request->user()->id_perusahaan, $request->validated()),
            'Penugasan disinkronkan dengan periode proyek'
        );
    }

    public function aktivitasBoard(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        return ApiResponse::success([
            'terakhir' => $this->service->stempelAktivitasBoard($idPerusahaan),
        ]);
    }

    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dari'   => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
        ]);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $hasil = $this->service->board($idPerusahaan, $data['dari'], $data['sampai']);

        return ApiResponse::success($hasil);
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $page   = (int) $request->get('page', 1);
        $limit  = (int) $request->get('limit', 10);
        $sumber = $request->filled('sumber') ? (string) $request->get('sumber') : null;
        $status = $request->filled('status') ? (string) $request->get('status') : null;

        if ($request->filled('id_armada')) {
            $result = $this->service->listByArmada((string) $request->get('id_armada'), $idPerusahaan, $page, $limit, $sumber, $status);
        } elseif ($request->filled('id_supir')) {
            $result = $this->service->listBySupir((string) $request->get('id_supir'), $idPerusahaan, $page, $limit, $sumber, $status);
        } elseif ($request->filled('id_proyek')) {
            $result = $this->service->list((string) $request->get('id_proyek'), $idPerusahaan, $page, $limit, $sumber, $status);
        } else {
            $result = $this->service->listByPerusahaan($idPerusahaan, $page, $limit, $sumber, $status);
        }

        $idList = array_map(fn ($r) => (string) $r->id_penugasan, [...$result['data']]);
        $map = $this->service->titikDropBanyak($idList);
        $detailMap = $this->service->titikDropDetailBanyak($idList);
        foreach ($result['data'] as $row) {
            $row->titik_drop = $map[$row->id_penugasan] ?? [];
            $row->titik_drop_detail = $detailMap[$row->id_penugasan] ?? [];
        }

        return ApiResponse::paginated(
            PenugasanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findMilikOrFail($id, (string) $request->user()->id_perusahaan);
        $record->titik_drop = $this->service->titikDropUntuk((string) $record->id_penugasan);
        $record->titik_drop_detail = $this->service->titikDropDetailUntuk((string) $record->id_penugasan);
        return ApiResponse::success(new PenugasanResource($record));
    }

    public function store(StorePenugasanRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil dibuat', 201);
    }

    public function update(UpdatePenugasanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Penugasan berhasil dihapus');
    }
}
