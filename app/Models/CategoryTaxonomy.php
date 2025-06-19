<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryTaxonomy extends Model
{
    use HasFactory;
    protected $table = 'wp_term_taxonomy';
    protected $primaryKey = 'term_taxonomy_id';
    public function term()
    {
        return $this->belongsTo(Category::class, 'term_id', 'term_id');
    }
    public function parentTerm()
    {
        return $this->belongsTo(CategoryTaxonomy::class, 'parent', 'term_taxonomy_id');
    }

    public function childTerms()
    {
        return $this->hasMany(CategoryTaxonomy::class, 'parent', 'term_taxonomy_id');
    }
    
}
