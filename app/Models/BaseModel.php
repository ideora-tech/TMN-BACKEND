<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasSoftDeleteColumns;
use App\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use HasUuidPrimaryKey, HasAuditColumns, HasSoftDeleteColumns;

    public $timestamps = false;
}
