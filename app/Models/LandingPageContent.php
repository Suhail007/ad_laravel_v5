<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingPageContent extends Model
{
    use HasFactory;
    protected $fillable = [
        'serial',
        'layout_type',
        'layout_content'
    ];

    protected $casts = [
        'layout_content' => 'array'
    ];

    public function landingPages()
    {
        return $this->hasMany(LandingPage::class);
    }
}
