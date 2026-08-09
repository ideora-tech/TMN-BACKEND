<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat;

use App\Modules\TokenPerangkat\Contracts\TokenPerangkatRepositoryInterface;

class TokenPerangkatRepository implements TokenPerangkatRepositoryInterface
{
    public function upsert(string $idPengguna, string $token, string $platform): void
    {
        $ada = TokenPerangkatModel::where('token', $token)->first();

        if ($ada !== null) {
            $ada->id_pengguna  = $idPengguna;
            $ada->platform     = $platform;
            $ada->dihapus_pada = null;
            $ada->dihapus_oleh = null;
            $ada->save();
            return;
        }

        TokenPerangkatModel::create([
            'id_pengguna' => $idPengguna,
            'token'       => $token,
            'platform'    => $platform,
        ]);
    }

    public function hapusByToken(string $idPengguna, string $token): void
    {
        $ada = TokenPerangkatModel::active()
            ->where('token', $token)
            ->where('id_pengguna', $idPengguna)
            ->first();

        $ada?->softDelete();
    }

    public function tokensUntukPengguna(string $idPengguna): array
    {
        return TokenPerangkatModel::active()
            ->where('id_pengguna', $idPengguna)
            ->pluck('token')
            ->all();
    }

    public function hapusTokenMati(string $token): void
    {
        TokenPerangkatModel::active()->where('token', $token)->first()?->softDelete();
    }
}
