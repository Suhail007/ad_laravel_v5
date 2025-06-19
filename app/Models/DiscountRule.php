<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountRule extends Model
{
    use HasFactory;
    protected $table = 'wp_wdr_rules'; 

    protected $primaryKey = 'id'; 
        protected $casts = [
        'product_adjustments' => 'array', 
        'filters' => 'array', 
        'additional' => 'array', 
        'conditions' => 'array', 
        'cart_adjustments' => 'array', 
        'buy_x_get_x_adjustments' => 'array', 
        'buy_x_get_y_adjustments' => 'array', 
        'bulk_adjustments' => 'array', 
        'set_adjustments' => 'array', 
        'rule_language' => 'array', 
        'advanced_discount_message' => 'array', 
        'used_coupons' => 'array', 
    ];
}
