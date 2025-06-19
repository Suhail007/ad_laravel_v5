<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MMTax extends Model
{
    use HasFactory;

    protected $table = 'wp_mm_indirect_tax';
    protected $primaryKey = 'id';
    public $timestamps = false;

}
