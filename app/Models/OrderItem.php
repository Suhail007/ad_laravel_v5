<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;
    protected $table = 'wp_woocommerce_order_items';
    protected $primaryKey = 'order_item_id';
    public $timestamps = false;

    public function meta()
    {
        return $this->hasMany(OrderItemMeta::class, 'order_item_id', 'order_item_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'ID');
    }
}
