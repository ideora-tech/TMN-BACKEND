<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Resources\NotifikasiResource;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NotifikasiController extends Controller
{
    public function __construct(private readonly NotifikasiService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        if (!$user->id_perusahaan) {
            return ApiResponse::paginated(collect([]), ['page'=>1,'limit'=>20,'total'=>0,'totalPages'=>0]);
        }
        $result = $this->service->list(
            $user->id_pengguna ?? $user->id,
            $user->id_perusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 20),
            $request->query('tipe'),
            $request->has('dibaca') ? (int) $request->query('dibaca') : null,
        );

        return ApiResponse::paginated(NotifikasiResource::collection($result['data']), $result['meta']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user  = $request->user();
        if (!$user->id_perusahaan) {
            return ApiResponse::success(['count' => 0]);
        }
        $count = $this->service->unreadCount($user->id_pengguna ?? $user->id, $user->id_perusahaan);

        return ApiResponse::success(['count' => $count]);
    }

    public function baca(Request $request, string $id): JsonResponse
    {
        $n = $this->service->markRead($id);

        return ApiResponse::success(new NotifikasiResource($n), 'Notifikasi ditandai sudah dibaca');
    }

    public function bacaSemua(Request $request): JsonResponse
    {
        $user  = $request->user();
        if (!$user->id_perusahaan) {
            return ApiResponse::success(['updated' => 0], 'Semua notifikasi ditandai sudah dibaca');
        }
        $count = $this->service->markAllRead($user->id_pengguna ?? $user->id, $user->id_perusahaan);

        return ApiResponse::success(['updated' => $count], 'Semua notifikasi ditandai sudah dibaca');
    }
}