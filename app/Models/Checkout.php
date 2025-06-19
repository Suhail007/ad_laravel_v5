<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Checkout extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'billing' => 'array', 
        'shipping' => 'array', 
        'extra' => 'array'
    ];
}
