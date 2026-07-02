<?php

namespace App\Traits;

trait HasAuditColumns
{
    protected static function bootHasAuditColumns(): void
    {
        static::creating(function ($model) {
            $model->dibuat_pada = now();
            if (auth()->check()) {
                $model->dibuat_oleh = auth()->id();
            }
        });

        static::updating(function ($model) {
            $model->diubah_pada = now();
            if (auth()->check()) {
                $model->diubah_oleh = auth()->id();
            }
        });
    }
}
