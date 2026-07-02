<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Helpers\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Prevent Laravel from redirecting to named route 'login' on API requests.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return ApiResponse::error('Tidak terautentikasi', null, 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    public function render($request, Throwable $e)
    {
        // Auto-log 5xx server errors (not validation or auth errors)
        if (!($e instanceof ValidationException)
            && !($e instanceof AuthenticationException)
            && !($e instanceof HttpException && $e->getStatusCode() < 500)
        ) {
            try {
                app(\App\Modules\LogError\LogErrorService::class)->write('error', $e->getMessage(), $e, $request);
            } catch (\Throwable) {
                // Never let log writing crash the response
            }
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            if ($e instanceof ValidationException) {
                return ApiResponse::error('Validasi gagal', $e->errors(), 422);
            }
            if ($e instanceof HttpException) {
                return ApiResponse::error($e->getMessage() ?: 'Terjadi kesalahan', null, $e->getStatusCode());
            }
            return ApiResponse::error('Terjadi kesalahan server', null, 500);
        }

        return parent::render($request, $e);
    }
}
