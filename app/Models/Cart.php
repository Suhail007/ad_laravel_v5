<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'ID');
    }

    public function variation()
    {
        return $this->belongsTo(Product::class, 'variation_id', 'ID');
    }
}