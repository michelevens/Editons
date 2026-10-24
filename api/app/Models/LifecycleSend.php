<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One lifecycle email to one account. Unique per campaign and account. */
class LifecycleSend extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
