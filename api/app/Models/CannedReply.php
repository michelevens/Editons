<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A reply staff can start from. Managed by the owner. */
class CannedReply extends Model
{
    protected $fillable = ['title', 'body', 'created_by'];
}
