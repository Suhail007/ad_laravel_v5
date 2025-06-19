<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    protected $table = 'wp_posts';
    protected $primaryKey = 'ID';
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'ID');
    }

    public function meta()
    {
        return $this->hasMany(OrderMeta::class, 'post_id', 'ID');
    }
}

