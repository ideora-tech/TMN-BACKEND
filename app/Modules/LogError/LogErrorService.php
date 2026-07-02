<?php

declare(strict_types=1);

namespace App\Modules\LogError;

use App\Modules\LogError\Contracts\LogErrorRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LogErrorService
{
    public function __construct(private readonly LogErrorRepositoryInterface $repo) {}

    public function list(int $page = 1, int $limit = 20): array
    {
        $result = $this->repo->paginate($page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): LogErrorModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Log error tidak ditemukan');
        }
        return $record;
    }

    public function write(string $level, string $pesan, ?\Throwable $e = null, ?Request $request = null): void
    {
        $this->repo->create([
            'id_log_error' => (string) Str::uuid(),
            'level'        => $level,
            'pesan'        => $pesan,
            'stack_trace'  => $e?->getTraceAsString(),
            'metode_http'  => $request?->method(),
            'jalur'        => $request?->path(),
            'kode_status'  => $e instanceof HttpException
                                ? $e->getStatusCode()
                                : 500,
            'id_pengguna'  => auth()->id(),
            'dibuat_pada'  => now(),
        ]);
    }
}
