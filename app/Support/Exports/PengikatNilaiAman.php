<?php

declare(strict_types=1);

namespace App\Support\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\IValueBinder;

class PengikatNilaiAman extends DefaultValueBinder implements IValueBinder
{
    private const AWALAN_BERBAHAYA = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::AWALAN_BERBAHAYA, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
