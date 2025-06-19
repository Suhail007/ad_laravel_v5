<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    protected $table = 'wp_posts';
    protected $primaryKey = 'ID';

    public function varients()
    {
        return $this->hasMany(VariationMeta::class, 'post_id', 'ID');
    }

    public function parentProduct()
    {
        return $this->belongsTo(Product::class, 'post_parent', 'ID');
    }

}
