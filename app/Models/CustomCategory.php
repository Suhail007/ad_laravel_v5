<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomCategory extends Model
{
    use HasFactory;
    protected $table = 'wp_custom_value_save_category';
    protected $primaryKey = 'id';
}
