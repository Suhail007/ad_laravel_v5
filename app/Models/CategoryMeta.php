<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryMeta extends Model
{
    use HasFactory;
    protected $table = 'wp_termmeta';
    protected $primaryKey = 'meta_id';
    public function category(){
        return $this->belongsTo(Category::class,'term_id','term_id');
    }
}
