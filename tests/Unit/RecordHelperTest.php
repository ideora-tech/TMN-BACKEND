<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RecordHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_stamp_create_generates_uuid_when_missing(): void
    {
        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertArrayHasKey('id_test', $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $result['id_test']
        );
        $this->assertArrayHasKey('dibuat_pada', $result);
    }

    public function test_stamp_create_keeps_existing_id(): void
    {
        $result = RecordHelper::stampCreate(['id_test' => 'sudah-ada', 'nama' => 'Test'], 'id_test');

        $this->assertSame('sudah-ada', $result['id_test']);
    }

    public function test_stamp_create_fills_dibuat_oleh_when_authenticated(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertSame($pengguna->id_pengguna, $result['dibuat_oleh']);
    }

    public function test_stamp_create_tidak_isi_dibuat_oleh_saat_tidak_login(): void
    {
        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertArrayNotHasKey('dibuat_oleh', $result);
    }

    public function test_stamp_update_fills_diubah_pada(): void
    {
        $result = RecordHelper::stampUpdate(['nama' => 'Diubah']);

        $this->assertArrayHasKey('diubah_pada', $result);
        $this->assertSame('Diubah', $result['nama']);
    }

    public function test_stamp_update_fills_diubah_oleh_when_authenticated(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $result = RecordHelper::stampUpdate(['nama' => 'Diubah']);

        $this->assertSame($pengguna->id_pengguna, $result['diubah_oleh']);
    }

    public function test_stamp_delete_fills_dihapus_pada(): void
    {
        $result = RecordHelper::stampDelete();

        $this->assertArrayHasKey('dihapus_pada', $result);
    }

    public function test_stamp_delete_fills_dihapus_oleh_when_authenticated(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $result = RecordHelper::stampDelete();

        $this->assertSame($pengguna->id_pengguna, $result['dihapus_oleh']);
    }
}
