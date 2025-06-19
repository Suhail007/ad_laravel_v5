<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomBrand extends Model
{
    use HasFactory;
    protected $table = 'wp_custom_value_save';
    protected $primaryKey = 'id';
}
