<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\TokenPerangkat\Contracts\TokenPerangkatRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PengirimPush
{
    public function __construct(private readonly TokenPerangkatRepositoryInterface $tokenRepo) {}

    public function kirimKePengguna(string $idPengguna, string $judul, string $isi, array $data = []): void
    {
        try {
            $sa = $this->serviceAccount();
            if ($sa === null) {
                return;
            }

            $tokens = $this->tokenRepo->tokensUntukPengguna($idPengguna);
            if ($tokens === []) {
                return;
            }

            $accessToken = $this->accessToken($sa);
            if ($accessToken === null) {
                Log::warning('Push dilewati: gagal mendapatkan access token FCM');
                return;
            }

            foreach ($tokens as $token) {
                $this->kirimSatu($sa['project_id'], $accessToken, $token, $judul, $isi, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Push gagal: ' . $e->getMessage());
        }
    }

    private function serviceAccount(): ?array
    {
        $path = config('services.firebase.credentials');
        if (empty($path)) {
            Log::warning('Push dilewati: FIREBASE_CREDENTIALS tidak diset');
            return null;
        }
        if (!is_file($path)) {
            Log::warning("Push dilewati: file kredensial Firebase tidak ditemukan ({$path})");
            return null;
        }

        $sa = json_decode((string) file_get_contents($path), true);
        if (!is_array($sa) || empty($sa['project_id']) || empty($sa['client_email']) || empty($sa['private_key'])) {
            Log::warning('Push dilewati: file kredensial Firebase tidak valid');
            return null;
        }

        return $sa;
    }

    private function accessToken(array $sa): ?string
    {
        return Cache::remember('fcm_access_token', 3000, function () use ($sa) {
            $sekarang = time() - 60;
            $header = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64url((string) json_encode([
                'iss'   => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $sekarang,
                'exp'   => $sekarang + 3600,
            ]));

            $tandaTangan = '';
            if (!openssl_sign("{$header}.{$claims}", $tandaTangan, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                return null;
            }
            $jwt = "{$header}.{$claims}." . $this->b64url($tandaTangan);

            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            return $res->successful() ? $res->json('access_token') : null;
        });
    }

    private function kirimSatu(string $projectId, string $accessToken, string $token, string $judul, string $isi, array $data): void
    {
        $res = Http::withToken($accessToken)->post(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            [
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => $judul, 'body' => $isi],
                    'data'         => array_map('strval', $data),
                    'android'      => [
                        'priority'     => 'high',
                        'notification' => [
                            'channel_id' => 'tmn_penugasan_channel',
                            'sound'      => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => ['sound' => 'default'],
                        ],
                    ],
                ],
            ],
        );

        if ($res->successful()) {
            return;
        }

        $status = (string) $res->json('error.status');
        if ($res->status() === 404 || $status === 'UNREGISTERED' || $status === 'NOT_FOUND') {
            $this->tokenRepo->hapusTokenMati($token);
            Log::info('Token FCM mati dihapus');
            return;
        }

        Log::warning('Kirim push gagal (' . $res->status() . '): ' . $res->body());
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
