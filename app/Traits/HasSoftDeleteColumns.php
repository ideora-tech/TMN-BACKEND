<?php

namespace App\Traits;

trait HasSoftDeleteColumns
{
    public function softDelete(): void
    {
        $this->dihapus_pada = now();
        if (auth()->check()) {
            $this->dihapus_oleh = auth()->id();
        }
        $this->saveQuietly();
    }

    public function scopeActive($query)
    {
        return $query->whereNull($query->getModel()->getTable() . '.dihapus_pada');
    }
}
