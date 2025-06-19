<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerOrder extends Model
{
    use HasFactory;
    protected $table = 'wp_wc_orders';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function meta()
    {
        return $this->hasMany(OrderMeta::class, 'post_id', 'id');
    }
}
