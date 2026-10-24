<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved group of accounts, by rules (App\Growth\AudienceRules). */
class Audience extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
