<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingPage extends Model
{
    use HasFactory;
    protected $fillable = [
        'page_title',
        'page_slug',
        'content_id',
        'isPublished',
        'author',
        'date'
    ];

    public function content()
    {
        return $this->belongsTo(LandingPageContent::class);
    }
}
