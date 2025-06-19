<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariationMeta extends Model
{
    protected $table = 'wp_postmeta';
    protected $primaryKey = 'meta_id';

    public $timestamps = false;
    public function varient(){
        return $this->belongsTo(Variation::class, 'post_id');
    }
}
