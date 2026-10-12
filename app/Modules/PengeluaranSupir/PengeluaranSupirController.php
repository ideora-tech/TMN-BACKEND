<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir;

use App\Helpers\ApiResponse;
use App\Modules\PengeluaranSupir\Requests\StorePengeluaranSupirRequest;
use App\Modules\PengeluaranSupir\Resources\PengeluaranSupirResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PengeluaranSupirController extends Controller
{
    public function __construct(private readonly PengeluaranSupirService $service) {}

    public function opsi(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->opsi($this->supir($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page'   => ['nullable', 'integer', 'min:1'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $status = trim((string) ($validated['status'] ?? ''));
        $result = $this->service->list(
            $this->supir($request),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['limit'] ?? 10),
            $status !== '' ? $status : null,
        );

        return ApiResponse::paginated(PengeluaranSupirResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new PengeluaranSupirResource(
            $this->service->detailMilik($id, $this->supir($request))
        ));
    }

    public function store(StorePengeluaranSupirRequest $request): JsonResponse
    {
        $record = $this->service->create(
            $request->safe()->except('foto'),
            array_values($request->file('foto', [])),
            $this->supir($request),
        );

        return ApiResponse::success(new PengeluaranSupirResource($record), 'Pengajuan pengeluaran berhasil dikirim', 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->batalkan($id, $this->supir($request));

        return ApiResponse::success(null, 'Pengajuan pengeluaran dibatalkan');
    }

    private function supir(Request $request): object
    {
        $pengguna = $request->user();

        return $this->service->supirUntukPengguna((string) $pengguna->id_pengguna, (int) $pengguna->aktif === 1);
    }
}
