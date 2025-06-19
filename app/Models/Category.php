<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $table = 'wp_terms';
    protected $primaryKey = 'term_id';
    public function products()
    {
        return $this->belongsToMany(Product::class, 'wp_term_relationships', 'term_taxonomy_id', 'object_id');
    }
    public function categorymeta(){
        return $this->hasMany(CategoryMeta::class,'term_id','term_id');
    }

    public function taxonomies(){
        return $this->hasOne(CategoryTaxonomy::class,'term_id','term_id');
    }
    public function taxonomy()
    {
        return $this->hasOne(CategoryTaxonomy::class, 'term_id', 'term_id');
    }

    public function children()
    {
        return $this->hasManyThrough(
            Category::class,
            CategoryTaxonomy::class,
            'parent', // Foreign key on CategoryTaxonomy table
            'term_id', // Foreign key on Category table
            'term_id', // Local key on Category table
            'term_id' // Local key on CategoryTaxonomy table
        );
    }

    public function getVisibilityAttribute()
    {
        return $this->categorymeta()->where('meta_key', 'visibility')->value('meta_value');
    }

    public static function getCategoriesWithChildren()
{
    $categoryIds = BrandMenu::pluck('term_id')->toArray();

    $categories = self::with(['children' => function ($query) {
            $query->whereHas('products', function ($q) {
                $q->whereHas('meta', function ($metaQuery) {
                    $metaQuery->where('meta_key', '_stock_status')->where('meta_value', 'instock');
                });
            });
        }])
        ->whereHas('taxonomies', function ($query) {
            $query->where('taxonomy', 'product_cat');
        })
        ->whereDoesntHave('taxonomy', function ($query) {
            $query->where('parent', '>', 0)->where('count', '>', 0);
        })
        ->whereIn('term_id', $categoryIds)
        ->whereHas('products', function ($query) {
            $query->whereHas('meta', function ($metaQuery) {
                $metaQuery->where('meta_key', '_stock_status')->where('meta_value', 'instock');
            });
        })
        ->get()
        ->map(function ($category) {
            return [
                'parent' => [
                    'term_id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'visibility' => $category->visibility
                ],
                'children' => $category->children->map(function ($child) {
                    return [
                        'term_id' => $child->term_id,
                        'name' => $child->name,
                        'slug' => $child->slug,
                        'visibility' => $child->visibility
                    ];
                })->values()
            ];
        });

    return $categories;
}


    public static function getBrandWithChildren()
    {
        $categories = self::with('children')
            ->whereHas('taxonomies', function ($query) {
                $query->where('taxonomy', 'product_brand');
            })
            ->whereDoesntHave('taxonomy', function ($query) {
                $query->where('parent', '>', 0);
            })
            ->get()
            ->map(function ($category) {
                return [
                    'parent' => [
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'visibility' => $category->visibility
                    ],
                    'children' => $category->children->map(function ($child) {
                        return [
                            'name' => $child->name,
                            'slug' => $child->slug,
                            'visibility' => $child->visibility
                        ];
                    })
                ];
            });

        return $categories;
    }

    public static function getAllCategoryIdsBySlug($slug)
    {
        // Get the parent category using the slug
        $parentCategory = Category::whereHas('taxonomies', function ($query) use ($slug) {
            $query->where('taxonomy', 'product_cat')
                ->where('slug', $slug);
        })->first();

        // If the category is found, recursively get all child categories
        if ($parentCategory) {
            $categoryIds = self::getAllChildCategoryIds($parentCategory->term_id);
            array_unshift($categoryIds, $parentCategory->term_id); // Add the parent category itself
            return $categoryIds;
        }

        return [];
    }

    private static function getAllChildCategoryIds($termId)
    {
        $children = Category::whereHas('taxonomies', function ($query) use ($termId) {
            $query->where('parent', $termId);
        })->pluck('term_id')->toArray();

        foreach ($children as $childId) {
            $children = array_merge($children, self::getAllChildCategoryIds($childId)); // Recursively add child categories
        }

        return $children;
    }
}
