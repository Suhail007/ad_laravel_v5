<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCoupon extends Model
{
    protected $guarded = [];
    protected $casts = [
        'meta' => 'array' 
    ];
}
