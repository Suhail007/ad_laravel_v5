<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeoRestrictionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'geo_restriction_id',
        'user_id',
        'action',
        'changes'
    ];

    protected $casts = [
        'changes' => 'array'
    ];

    public function geoRestriction()
    {
        return $this->belongsTo(GeoRestriction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
} 