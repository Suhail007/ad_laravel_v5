<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItemMeta extends Model
{
    use HasFactory;
    protected $table = 'wp_woocommerce_order_itemmeta';
    protected $primaryKey = 'meta_id';
    public $timestamps = false;

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id', 'order_item_id');
    }
}