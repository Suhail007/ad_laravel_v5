<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $table = 'wp_posts';
    protected $primaryKey = 'ID';
    public function meta()
    {
        return $this->hasMany(ProductMeta::class, 'post_id', 'ID');
    }
    public function variations()
    {
        return $this->hasMany(Variation::class, 'post_parent', 'ID')
            ->where('post_type', 'product_variation');
    }
    public function thumbnail()
    {
        return $this->hasOne(Product::class, 'post_parent', 'ID')
            ->where('post_type', 'attachment');
    }
    public function imageGallery()
{
    return $this->hasMany(ProductMeta::class, 'post_id', 'ID')
                ->where('meta_key', '_product_image_gallery');
}

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'wp_term_relationships', 'object_id', 'term_taxonomy_id')
            ->with('taxonomies');
    }
    public function getThumbnailUrlAttribute()
    {
        $thumbnailId = $this->meta()->where('meta_key', '_thumbnail_id')->value('meta_value');
        if ($thumbnailId) {
            return ProductMeta::where('post_id', $thumbnailId)->where('meta_key', '_wp_attached_file')->value('meta_value');
        }
        return null;
    }

    public function getVisibilityAttribute()
    {
        return $this->meta()->where('meta_key', '_stock_status')->value('meta_value');
    }
}
