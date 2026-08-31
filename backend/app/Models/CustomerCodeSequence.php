<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCodeSequence extends Model
{
    protected $fillable = [
        'last_value',
    ];
}
