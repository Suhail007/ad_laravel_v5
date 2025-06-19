<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomBrand;
use App\Models\CustomCategory;
use App\Models\Product;
use App\Traits\LocationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Automattic\WooCommerce\Client;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    use LocationTrait;
    protected $woocommerce;
    private function getThumbnailUrl($thumbnailId)
    {
        if (!$thumbnailId) {
            return null;
        }
        $attachment = DB::table('wp_posts')->where('ID', $thumbnailId)->first();
        if ($attachment) {
            return $attachment->guid;
        }
        return null;
    }
    public function rewealLocation(Request $request){
        $ip = $request->ip();
        $userLocation = $this->getUserLocationFromIP($ip);
        return response()->json(['ip' => $ip, 'userLocation' => $userLocation]);
    }
    public function sidebar()
    {
        $category = CustomCategory::get();
        $brand = CustomBrand::where('category', '!=', '')->get();
        $response = response()->json(['category' => $category, 'brands' => $brand]);
        $response->header('Cache-Control', 'public, max-age=600');
        return $response;
    }
    public function dummyProductList()
    {
        return [209210, 209212, 209218, 209216, 209228, 209219, 209230, 209220, 209232, 209227, 209235];
    }
    public function categoryProductV2(Request $request, $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $priceRangeMin = $request->query('min', 0);
        $priceRangeMax = $request->query('max', 0);
        $type = $request->query('type', 'cat'); // brand , flavor
        $flavor = $request->query('flavor', '');  // 
        $flavor = $flavor ? explode(',', $flavor) : [];
        
        $taxo = $request->query('taxo', []); //

        $slug = explode(',', $slug);
        $auth = false;
        $priceRange = [
            'min' => (int) $priceRangeMin,
            'max' => (int) $priceRangeMax
        ];
        $priceTier = '_price';
        try {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                $priceTier = $user->price_tier ?? '_price';
                if ($user->ID) {
                    $auth = true;
                }
            } catch (\Throwable $th) {
                $auth = false;
            }
            $products = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                },
                'variations' => function ($query) use ($priceTier) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price','attribute_.*', '_stock_status', '_sku', '_thumbnail_id', $priceTier])
                                    ->orWhere(function ($query) {
                                        $query->where('meta_key', 'like', 'attribute_%');
                                    });
                            }
                        ]);
                },
                'thumbnail'
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug, $taxo) {
                    if (!empty($taxo)) {
                        $query->whereIn('slug', $slug)
                              ->where('taxonomy', 'product_brand');
                    } else {
                        $query->whereIn('slug', $slug)
                              ->where('taxonomy', 'product_cat');
                    }
                });
                if($auth == false){
                    $products->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                }
                if ($type == 'flavor' && !empty($flavor)) {
                    $products->where(function ($query) use ($flavor) {
                        $query->whereHas('variations.varients', function ($variationQuery) use ($flavor) {
                            $variationQuery->where('meta_key', 'like', 'attribute_%')
                                ->whereIn('meta_value', $flavor);  
                        });
                
                        // $query->orWhereHas('meta', function ($metaQuery) use ($flavor) {
                        //     $metaQuery->where('meta_key', 'attribute_flavor')
                        //         ->whereIn('meta_value', $flavor); 
                        // });
                    });
                }
            if ($priceRange['min'] > 0 && $priceRange['max'] > 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });
                });
            } elseif ($priceRange['min'] > 0 && $priceRange['max'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                });
            } elseif ($priceRange['max'] > 0 && $priceRange['min'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                    break;
                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                    break;
                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }
            $products = $products->paginate($perPage, ['*'], 'page', $page);

            $allAttributeValues = collect($products->pluck('variations.*.varients.*'))
            ->flatten()
            ->filter(function ($meta) {
                return str_starts_with($meta['meta_key'], 'attribute_');
            })
            ->pluck('meta_value')
            ->unique()
            ->values()
            ->all();

            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                $galleryImages = [];
                if ($galleryImageIds) {
                    $imageIds = explode(',', $galleryImageIds);
                    $images = Product::whereIn('ID', $imageIds)->get();
                    foreach ($images as $image) {
                        $galleryImages[] = $image->guid;
                    }
                }
                $ad_price = null;
                // login
                if($auth == false){
                    $ad_price = null;
                } else {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                            if ($variationPrice) {
                                $ad_price = $variationPrice;
                                break;
                            }
                        }
                    }
                    if ($ad_price === null) {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                    }
                }
                if($auth == false){
                    $metaArray = $product->meta->map(function ($meta) {
                        return [
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $meta->meta_value
                        ];
                    })->toArray();
                    $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                        return $meta['meta_key'] !== '_price';
                    }));    
                }
                
                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'gallery_images' => $galleryImages, // Add gallery images here
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy =  $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $auth?$product->meta:$meta,
                    'variations' => $product->variations,
                    'post_modified' => $product->post_modified
                ];
            });

            return response()->json(['data'=>$products,'favorList'=>$allAttributeValues]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()]);
        }
    }
    public function brandProductV2(Request $request, $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $priceRangeMin = $request->query('min', 0);
        $priceRangeMax = $request->query('max', 0);
        $slug = explode(',', $slug);
        $auth = false;
        $priceRange = [
            'min' => (int) $priceRangeMin,
            'max' => (int) $priceRangeMax
        ];
        $priceTier = '_price';
        try {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                $priceTier = $user->price_tier ?? '_price';
                if ($user->ID) {
                    $auth = true;
                }
            } catch (\Throwable $th) {
                $auth = false;
            }
            $products = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                },
                'variations' => function ($query) use ($priceTier) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            }
                        ]);
                },
                'thumbnail'
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->whereIn('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                });
                if($auth == false){
                    $products->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                }
            if ($priceRange['min'] > 0 && $priceRange['max'] > 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });
                });
            } elseif ($priceRange['min'] > 0 && $priceRange['max'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                });
            } elseif ($priceRange['max'] > 0 && $priceRange['min'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                    break;
                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                    break;
                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }
            $products = $products->paginate($perPage, ['*'], 'page', $page);
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                $galleryImages = [];
                if ($galleryImageIds) {
                    $imageIds = explode(',', $galleryImageIds);
                    $images = Product::whereIn('ID', $imageIds)->get();
                    foreach ($images as $image) {
                        $galleryImages[] = $image->guid;
                    }
                }
                $ad_price = null;
                // login
                if($auth == false){
                    $ad_price = null;
                } else {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                            if ($variationPrice) {
                                $ad_price = $variationPrice;
                                break;
                            }
                        }
                    }
                    if ($ad_price === null) {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                    }
                }
                if($auth == false){
                    $metaArray = $product->meta->map(function ($meta) {
                        return [
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $meta->meta_value
                        ];
                    })->toArray();
                    $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                        return $meta['meta_key'] !== '_price';
                    }));    
                }
                
                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'gallery_images' => $galleryImages, // Add gallery images here
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy =  $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $auth?$product->meta:$meta,
                    'variations' => $product->variations,
                    'post_modified' => $product->post_modified
                ];
            });

            return response()->json($products);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()]);
        }
    }
    public function searchProductV2(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $searchTerm = $request->input('searchTerm', '');
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $priceRangeMin = $request->query('min', 0);
        $priceRangeMax = $request->query('max', 0);
        $flavor = $request->query('flavor', '');  // 
        $flavor = $flavor ? explode(',', $flavor) : [];
        $type = $request->query('type', 'cat'); // brand , flavor
        // $slug = explode(',', $slug);
        $auth = false;
        $priceRange = [
            'min' => (int) $priceRangeMin,
            'max' => (int) $priceRangeMax
        ];
        $priceTier = '_price';
        try {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                $priceTier = $user->price_tier ?? '_price';
                if ($user->ID) {
                    $auth = true;
                }
            } catch (\Throwable $th) {
                $auth = false;
            }
            $products = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                },
                'variations' => function ($query) use ($priceTier) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price','attribute_.*', '_stock_status', '_sku', '_thumbnail_id', $priceTier])
                                    ->orWhere(function ($query) {
                                        $query->where('meta_key', 'like', 'attribute_%');
                                    });
                            }
                        ]);
                },
                'thumbnail'
                ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                });
                // ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                //     $query->whereIn('slug', $slug)
                //         ->where('taxonomy', 'product_brand');
                // });
                if($auth == false){
                    $products->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                }
                if (!empty($searchTerm)) {
                    $searchWords = preg_split('/\s+/', $searchTerm);
                    $regexPattern = implode('.*', array_map(function ($word) {
                        return "(?=.*" . preg_quote($word) . ")";
                    }, $searchWords));
    
                    $products->where(function ($query) use ($regexPattern) {
                        $query->where('post_title', 'REGEXP', $regexPattern)
                            ->orWhereHas('meta', function ($query) use ($regexPattern) {
                                $query->where('meta_key', '_sku')
                                    ->where('meta_value', 'REGEXP', $regexPattern);
                            });
                    });
                }
                if ($type == 'flavor' && !empty($flavor)) {
                    $products->where(function ($query) use ($flavor) {
                        $query->whereHas('variations.varients', function ($variationQuery) use ($flavor) {
                            $variationQuery->where('meta_key', 'like', 'attribute_%')
                                ->whereIn('meta_value', $flavor);  
                        });
                
                        // $query->orWhereHas('meta', function ($metaQuery) use ($flavor) {
                        //     $metaQuery->where('meta_key', 'attribute_flavor')
                        //         ->whereIn('meta_value', $flavor); 
                        // });
                    });
                }
            if ($priceRange['min'] > 0 && $priceRange['max'] > 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });
                });
            } elseif ($priceRange['min'] > 0 && $priceRange['max'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                });
            } elseif ($priceRange['max'] > 0 && $priceRange['min'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                    break;
                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                    break;
                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }
            $products = $products->paginate($perPage, ['*'], 'page', $page);

            
            $allAttributeValues = collect($products->pluck('variations.*.varients.*'))
                ->flatten()
                ->filter(function ($meta) {
                    return str_starts_with($meta['meta_key'], 'attribute_');
                })
                ->pluck('meta_value')
                ->unique()
                ->values()
                ->all();
            $allCategoryNames = collect($products->pluck('categories.*.name'))
                ->flatten()
                ->unique()
                ->values()
                ->all();

            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                $galleryImages = [];
                if ($galleryImageIds) {
                    $imageIds = explode(',', $galleryImageIds);
                    $images = Product::whereIn('ID', $imageIds)->get();
                    foreach ($images as $image) {
                        $galleryImages[] = $image->guid;
                    }
                }
                $ad_price = null;
                // login
                if($auth == false){
                    $ad_price = null;
                } else {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                            if ($variationPrice) {
                                $ad_price = $variationPrice;
                                break;
                            }
                        }
                    }
                    if ($ad_price === null) {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                    }
                }
                if($auth == false){
                    $metaArray = $product->meta->map(function ($meta) {
                        return [
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $meta->meta_value
                        ];
                    })->toArray();
                    $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                        return $meta['meta_key'] !== '_price';
                    }));    
                }
                
                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'gallery_images' => $galleryImages, // Add gallery images here
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy =  $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $auth?$product->meta:$meta,
                    'variations' => $product->variations,
                    'post_modified' => $product->post_modified
                ];
            });

            return response()->json(['data'=>$products,'favorList'=>$allAttributeValues,'categories'=>$allCategoryNames]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()]);
        }
    }

    // caching functions v3
    public function categoryProductV3(Request $request, $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $priceRangeMin = $request->query('min', 0);
        $priceRangeMax = $request->query('max', 0);
        $type = $request->query('type', 'cat'); // brand , flavor
        $flavor = $request->query('flavor', '');  // 
        $flavor = $flavor ? explode(',', $flavor) : [];
        $taxo = $request->query('taxo', []); //
        $slug = explode(',', $slug);
        $auth = false;
        $priceRange = [
            'min' => (int) $priceRangeMin,
            'max' => (int) $priceRangeMax
        ];
        $priceTier = '_price';

        try {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                if ($user->ID) {
                    $priceTier = $user->price_tier ?? '_price';
                    $auth = true;
                }
            } catch (\Throwable $th) {
                $auth = false;
            }
            // return $priceTier;
            // Generate cache key based on all request parameters
            $cacheKey = 'category_products_v3_'. $priceTier . '_' . md5(json_encode([
                'slug' => $slug,
                'sortBy' => $sortBy,
                'page' => $page,
                'perPage' => $perPage,
                'priceRange' => $priceRange,
                'flavor' => $flavor,
                'type' => $type,
                'taxo' => $taxo,
                'auth' => $auth
            ]));

            // Try to get cached results
            $cachedResults = Cache::get($cacheKey);
            if ($cachedResults) {
                // Update stock status and prices for cached results
                $productIds = collect($cachedResults['data'])->pluck('ID')->toArray();
                
                // Get fresh stock status
                $freshStockData = Product::whereIn('ID', $productIds)
                    ->with(['meta' => function($query) {
                        $query->where('meta_key', '_stock_status');
                    }])
                    ->get()
                    ->mapWithKeys(function($product) {
                        return [$product->ID => $product->meta->first()->meta_value ?? 'instock'];
                    });

                // Get fresh prices
                $freshPriceData = [];
                if ($auth) {
                    $freshPriceData = DB::table('wp_postmeta')
                        ->whereIn('post_id', $productIds)
                        ->where('meta_key', $priceTier)
                        ->get()
                        ->mapWithKeys(function($meta) {
                            return [$meta->post_id => $meta->meta_value];
                        });

                    // Get variation prices for products without direct price
                    $productsNeedingVariationPrice = array_diff($productIds, array_keys($freshPriceData->toArray()));
                    if (!empty($productsNeedingVariationPrice)) {
                        $variationPrices = DB::table('wp_posts as variations')
                            ->join('wp_postmeta as variation_meta', 'variations.ID', '=', 'variation_meta.post_id')
                            ->whereIn('variations.post_parent', $productsNeedingVariationPrice)
                            ->where('variations.post_type', 'product_variation')
                            ->where('variation_meta.meta_key', $priceTier)
                            ->select('variations.post_parent', 'variation_meta.meta_value')
                            ->get()
                            ->groupBy('post_parent')
                            ->map(function($group) {
                                return collect($group)->first()->meta_value;
                            });
                        
                        foreach($variationPrices as $productId => $price) {
                            $freshPriceData->put($productId, $price);
                        }
                    }
                }

                $cachedResults['data'] = collect($cachedResults['data'])->map(function($product) use ($freshStockData, $freshPriceData, $auth) {
                    // Update stock status
                    if (isset($product['meta']) && is_array($product['meta'])) {
                        $product['meta'] = collect($product['meta'])->map(function($meta) use ($product, $freshStockData) {
                            if (isset($meta['meta_key']) && $meta['meta_key'] === '_stock_status') {
                                $meta['meta_value'] = $freshStockData[$product['ID']] ?? 'instock';
                            }
                            return $meta;
                        })->toArray();
                    }

                    // Update price if authenticated
                    if ($auth && isset($product['ID'])) {
                        $product['ad_price'] = $freshPriceData->get($product['ID'], null);
                    }

                    return $product;
                })->toArray();

                return response()->json($cachedResults);
            }

            $products = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                },
                'variations' => function ($query) use ($priceTier) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price','attribute_.*', '_stock_status', '_sku', '_thumbnail_id', $priceTier])
                                    ->orWhere(function ($query) {
                                        $query->where('meta_key', 'like', 'attribute_%');
                                    });
                            }
                        ]);
                },
                'thumbnail'
            ])
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->whereHas('categories.taxonomies', function ($query) use ($slug, $taxo) {
                if (!empty($taxo)) {
                    $query->whereIn('slug', $slug)
                          ->where('taxonomy', 'product_brand');
                } else {
                    $query->whereIn('slug', $slug)
                          ->where('taxonomy', 'product_cat');
                }
            });

            if($auth == false){
                $products->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            }

            if ($type == 'flavor' && !empty($flavor)) {
                $products->where(function ($query) use ($flavor) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($flavor) {
                        $variationQuery->where('meta_key', 'like', 'attribute_%')
                            ->whereIn('meta_value', $flavor);  
                    });
                });
            }

            if ($priceRange['min'] > 0 && $priceRange['max'] > 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });
                });
            } elseif ($priceRange['min'] > 0 && $priceRange['max'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                });
            } elseif ($priceRange['max'] > 0 && $priceRange['min'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });
                });
            }

            switch ($sortBy) {
                case 'popul':
                    $products->orderByRaw("
                        CAST((SELECT meta_value FROM wp_postmeta 
                              WHERE wp_postmeta.post_id = wp_posts.ID 
                              AND wp_postmeta.meta_key = 'total_sales' 
                              LIMIT 1) AS UNSIGNED) DESC
                    ");
                    break;
                case 'plh':
                    $products->orderByRaw("
                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                              WHERE wp_postmeta.post_id = wp_posts.ID 
                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                    ");
                    break;
                case 'phl':
                    $products->orderByRaw("
                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                              WHERE wp_postmeta.post_id = wp_posts.ID 
                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                    ");
                    break;
                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }

            $products = $products->paginate($perPage, ['*'], 'page', $page);

            $allAttributeValues = collect($products->pluck('variations.*.varients.*'))
                ->flatten()
                ->filter(function ($meta) {
                    return str_starts_with($meta['meta_key'], 'attribute_');
                })
                ->pluck('meta_value')
                ->unique()
                ->values()
                ->all();

            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                $galleryImages = [];
                if ($galleryImageIds) {
                    $imageIds = explode(',', $galleryImageIds);
                    $images = Product::whereIn('ID', $imageIds)->get();
                    foreach ($images as $image) {
                        $galleryImages[] = $image->guid;
                    }
                }
                $ad_price = null;
                if($auth == false){
                    $ad_price = null;
                } else {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                            if ($variationPrice) {
                                $ad_price = $variationPrice;
                                break;
                            }
                        }
                    }
                    if ($ad_price === null) {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                    }
                }
                if($auth == false){
                    $metaArray = $product->meta->map(function ($meta) {
                        return [
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $meta->meta_value
                        ];
                    })->toArray();
                    $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                        return $meta['meta_key'] !== '_price';
                    }));    
                } else {
                    $meta = $product->meta;
                }
                
                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'gallery_images' => $galleryImages,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy =  $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $meta,
                    'variations' => $product->variations,
                    'post_modified' => $product->post_modified
                ];
            });

            $data = [
                'data' => $products,
                'flavorList' => $allAttributeValues
            ];

            // Cache the results for 1 hour
            Cache::put($cacheKey, $data, now()->addHour());

            return response()->json($data);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()]);
        }
    }
    public function searchProductV3(Request $request){
        $perPage = $request->query('perPage', 15);
        $searchTerm = $request->input('searchTerm', '');
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $priceRangeMin = $request->query('min', 0);
        $priceRangeMax = $request->query('max', 0);
        $flavor = $request->query('flavor', '');
        $flavor = $flavor ? explode(',', $flavor) : [];
        $type = $request->query('type', 'cat');
        $auth = false;
        $priceRange = [
            'min' => (int) $priceRangeMin,
            'max' => (int) $priceRangeMax
        ];
        $priceTier = '_price';
        try {
            try {
                $user = JWTAuth::parseToken()->authenticate();
                $priceTier = $user->price_tier ?? '_price';
                if ($user->ID) {
                    $auth = true;
                }
            } catch (\Throwable $th) {
                $auth = false;
            }
            $cacheKey = 'search_products_v3_' . $priceTier . '_' . md5(json_encode([
                'searchTerm' => $searchTerm,
                'sortBy' => $sortBy,
                'page' => $page,
                'perPage' => $perPage,
                'priceRange' => $priceRange,
                'flavor' => $flavor,
                'type' => $type,
                'priceTier' => $priceTier,
                'auth' => $auth
            ]));
            $cachedResults = Cache::get($cacheKey);
            if ($cachedResults) {
                $productIds = collect($cachedResults['data'])->pluck('ID')->toArray();
                $freshStockData = Product::whereIn('ID', $productIds)
                    ->with(['meta' => function($query) {
                        $query->where('meta_key', '_stock_status');
                    }])
                    ->get()
                    ->mapWithKeys(function($product) {
                        return [$product->ID => $product->meta->first()->meta_value ?? 'instock'];
                    });
                $cachedResults['data'] = collect($cachedResults['data'])->map(function($product) use ($freshStockData) {
                    if (isset($product['meta']) && is_array($product['meta'])) {
                        $product['meta'] = collect($product['meta'])->map(function($meta) use ($product, $freshStockData) {
                            if (isset($meta['meta_key']) && $meta['meta_key'] === '_stock_status') {
                                $meta['meta_value'] = $freshStockData[$product['ID']] ?? 'instock';
                            }
                            return $meta;
                        })->toArray();
                    }
                    return $product;
                })->toArray();
                return response()->json($cachedResults);
            }
            $products = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                },
                'variations' => function ($query) use ($priceTier) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price','attribute_.*', '_stock_status', '_sku', '_thumbnail_id', $priceTier])
                                    ->orWhere(function ($query) {
                                        $query->where('meta_key', 'like', 'attribute_%');
                                    });
                            }
                        ]);
                },
                'thumbnail'
            ])
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            });

            // Handle protected products for non-authenticated users
            if ($auth == false) {
                $products->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            }

            // Handle search term
            if (!empty($searchTerm)) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $products->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }
            if ($type == 'flavor' && !empty($flavor)) {
                $products->where(function ($query) use ($flavor) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($flavor) {
                        $variationQuery->where('meta_key', 'like', 'attribute_%')
                            ->whereIn('meta_value', $flavor);  
                    });
                });
            }
            if ($priceRange['min'] > 0 && $priceRange['max'] > 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ? AND CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['min'], $priceRange['max']]
                            );
                    });
                });
            } elseif ($priceRange['min'] > 0 && $priceRange['max'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) >= ?",
                                [$priceRange['min']]
                            );
                    });
                });
            } elseif ($priceRange['max'] > 0 && $priceRange['min'] == 0) {
                $products->where(function ($query) use ($priceRange, $priceTier) {
                    $query->whereHas('variations.varients', function ($variationQuery) use ($priceRange, $priceTier) {
                        $variationQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });

                    $query->orWhereHas('meta', function ($metaQuery) use ($priceRange, $priceTier) {
                        $metaQuery->where('meta_key', $priceTier)
                            ->whereRaw(
                                "CAST(meta_value AS DECIMAL(10,2)) <= ?",
                                [$priceRange['max']]
                            );
                    });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                    break;
                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                    break;
                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }

            $products = $products->paginate($perPage, ['*'], 'page', $page);
            
            // Fetch all attribute values (flavors) from the variations of the products
            $allAttributeValues = collect($products->pluck('variations.*.varients.*'))
                ->flatten()
                ->filter(function ($meta) {
                    return is_array($meta) && isset($meta['meta_key']) && str_starts_with($meta['meta_key'], 'attribute_');
                })
                ->pluck('meta_value')
                ->unique()
                ->values()
                ->all();

            $allCategoryNames = collect($products->pluck('categories.*.name'))
                ->flatten()
                ->unique()
                ->values()
                ->all();
            
                $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                    $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                    $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                    $galleryImages = [];
                    if ($galleryImageIds) {
                        $imageIds = explode(',', $galleryImageIds);
                        $images = Product::whereIn('ID', $imageIds)->get();
                        foreach ($images as $image) {
                            $galleryImages[] = $image->guid;
                        }
                    }
                    $ad_price = null;
                    // login
                    if($auth == false){
                        $ad_price = null;
                    } else {
                        if ($product->variations->isNotEmpty()) {
                            foreach ($product->variations as $variation) {
                                $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                                if ($variationPrice) {
                                    $ad_price = $variationPrice;
                                    break;
                                }
                            }
                        }
                        if ($ad_price === null) {
                            $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                        }
                    }
                    if($auth == false){
                        $metaArray = $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        })->toArray();
                        $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                            return $meta['meta_key'] !== '_price';
                        }));    
                    } else {
                        $meta = $product->meta;
                    }
                    
                    return [
                        'ID' => $product->ID,
                        'ad_price' => $ad_price,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'gallery_images' => $galleryImages, // Add gallery images here
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            $taxonomy =  $category->taxonomies->taxonomy;
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'slug' => $category->slug,
                                'visibility' => $visibility ? $visibility : 'public',
                                'taxonomy' => $taxonomy ? $taxonomy : 'public',
                            ];
                        }),
                        'meta' => $meta,
                        'variations' => $product->variations,
                        'post_modified' => $product->post_modified
                    ];
                });

                $data = [
                    'data' => $products,
                    'flavorList' => $allAttributeValues,
                    'categories' => $allCategoryNames
                ];
                Cache::put($cacheKey, $data, now()->addHour());
                return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()]);
        }
    }

    // live ad function v1 
    public function categoryProduct(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);

        $slug = explode(',', $slug);
        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';

            if ($user->ID) {
                $auth = true;
                if ($slug == 'new-arrivals') {
                    if ($user->ID == 5417) {
                        $products = Product::with([
                            'meta' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            },
                            'categories' => function ($query) {
                                $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                    ->with([
                                        'categorymeta' => function ($query) {
                                            $query->select('term_id', 'meta_key', 'meta_value')
                                                ->where('meta_key', 'visibility');
                                        },
                                        'taxonomies' => function ($query) {
                                            $query->select('term_id', 'taxonomy');
                                        }
                                    ]);
                            }
                        ])
                            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                            ->where('post_type', 'product')
                            ->where('post_status', 'trash')
                            ->whereIn('ID', $this->dummyProductList())
                            ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                                $query->whereIn('slug', $slug)
                                    ->where('taxonomy', 'product_cat');
                            });
                    } else {
                        $products = Product::with([
                            'meta' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            },
                            'categories' => function ($query) {
                                $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                    ->with([
                                        'categorymeta' => function ($query) {
                                            $query->select('term_id', 'meta_key', 'meta_value')
                                                ->where('meta_key', 'visibility');
                                        },
                                        'taxonomies' => function ($query) {
                                            $query->select('term_id', 'taxonomy');
                                        }
                                    ]);
                            }
                        ])
                            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                            ->where('post_type', 'product')
                            ->where('post_status', 'publish')
                            ->whereHas('meta', function ($query) {
                                $query->where('meta_key', '_stock_status')
                                    ->where('meta_value', 'instock');
                            })
                            ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                                $query->whereIn('slug', $slug)
                                    ->where('taxonomy', 'product_cat');
                            });
                    }


                    switch ($sortBy) {
                        case 'popul':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $products->orderBy('post_date', 'desc');
                            break;
                    }

                    $products = $products->paginate($perPage, ['*'], 'page', $page);
                } else {

                    if ($user->ID == 5417) {
                        $products = Product::with([
                            'meta' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            },
                            'categories' => function ($query) {
                                $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                    ->with([
                                        'categorymeta' => function ($query) {
                                            $query->select('term_id', 'meta_key', 'meta_value')
                                                ->where('meta_key', 'visibility');
                                        },
                                        'taxonomies' => function ($query) {
                                            $query->select('term_id', 'taxonomy');
                                        }
                                    ]);
                            }
                        ])
                            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                            ->where('post_type', 'product')
                            ->where('post_status', 'trash')
                            ->whereIn('ID', $this->dummyProductList())
                            // ->whereHas('meta', function ($query) {
                            //     $query->where('meta_key', '_stock_status')
                            //         ->where('meta_value', 'instock');
                            // })
                            ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                                $query->whereIn('slug', $slug)
                                    ->where('taxonomy', 'product_cat');
                            });
                    } else {
                        $products = Product::with([
                            'meta' => function ($query) use ($priceTier) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                            },
                            'categories' => function ($query) {
                                $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                    ->with([
                                        'categorymeta' => function ($query) {
                                            $query->select('term_id', 'meta_key', 'meta_value')
                                                ->where('meta_key', 'visibility');
                                        },
                                        'taxonomies' => function ($query) {
                                            $query->select('term_id', 'taxonomy');
                                        }
                                    ]);
                            }
                        ])
                            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                            ->where('post_type', 'product')
                            ->where('post_status', 'publish')
                            ->whereHas('meta', function ($query) {
                                $query->where('meta_key', '_stock_status')
                                    ->where('meta_value', 'instock');
                            })
                            ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                                $query->whereIn('slug', $slug)
                                    ->where('taxonomy', 'product_cat');
                            });
                    }

                    switch ($sortBy) {
                        case 'popul':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                            break;

                        case 'plh':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                            break;

                        case 'phl':
                            $products->with(['meta' => function ($query) use ($priceTier){
                                $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                            }])
                                ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                            break;

                        default:
                            $products->orderBy('post_date', 'desc');
                            break;
                    }

                    $products = $products->paginate($perPage, ['*'], 'page', $page);
                }
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            if ($slug == 'new-arrivals') {
                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                },
                                'taxonomies' => function ($query) {
                                    $query->select('term_id', 'taxonomy');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->where('post_status', 'publish')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->whereIn('slug', $slug)
                            ->where('taxonomy', 'product_cat');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            } else {
                // $products = Product::with([
                //     'meta' => function ($query) {
                //         $query->select('post_id', 'meta_key', 'meta_value')
                //             ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                //     },
                //     'categories' => function ($query) {
                //         $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                //             ->with([
                //                 'categorymeta' => function ($query) {
                //                     $query->select('term_id', 'meta_key', 'meta_value')
                //                         ->where('meta_key', 'visibility');
                //                 },
                //                 'taxonomies' => function ($query) {
                //                     $query->select('term_id', 'taxonomy')->where('product_visibility',);
                //                 }
                //             ]);
                //     }
                // ])
                //     ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                //     ->where('post_type', 'product')
                //     ->whereHas('meta', function ($query) {
                //         $query->where('meta_key', '_stock_status')
                //             ->where('meta_value', 'instock');
                //     })
                //     ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                //         $query->where('slug', $slug)
                //             ->where('taxonomy', 'product_cat');
                //     })
                //     ->orderBy('post_date', 'desc')
                //     ->paginate($perPage, ['*'], 'page', $page);



                $products = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                },
                                'taxonomies' => function ($query) {
                                    $query->select('term_id', 'taxonomy');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->where('post_status', 'publish')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                        $query->whereIn('slug', $slug)
                            ->where('taxonomy', 'product_cat');
                    })
                    ->whereDoesntHave('categories.categorymeta', function ($query) {
                        $query->where('meta_key', 'visibility')
                            ->where('meta_value', 'protected');
                    });
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }
    public function brandProducts(Request $request, string $slug)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);

        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                if ($user->ID == 5417) {
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'trash')
                        ->whereIn('ID', $this->dummyProductList())
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        })
                        ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                            $query->where('slug', $slug)
                                ->where('taxonomy', 'product_brand');
                        });
                } else {
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'publish')
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        })
                        ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                            $query->where('slug', $slug)
                                ->where('taxonomy', 'product_brand');
                        });
                }
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                CAST((SELECT meta_value FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = 'total_sales' 
                                      LIMIT 1) AS UNSIGNED) DESC
                            ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                            ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) use ($priceTier){
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                        }])
                            ->orderByRaw("
                                CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                            ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }

                $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->where('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                                CAST((SELECT meta_value FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = 'total_sales' 
                                      LIMIT 1) AS UNSIGNED) DESC
                            ");
                    break;

                case 'plh':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                                CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                            ");
                    break;

                case 'phl':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                                CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                      WHERE wp_postmeta.post_id = wp_posts.ID 
                                      AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                            ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }

            $products = $products->paginate($perPage, ['*'], 'page', $page);
        }

        $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
            $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
            if (!$auth) {
                $ad_price = null;
            } else {
                try {
                    $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                    if ($ad_price == '') {
                        $ad_price = $this->getVariations($product->ID, $priceTier);
                        $ad_price = $ad_price[0];
                    }
                } catch (\Throwable $th) {
                    $ad_price = null;
                }
            }
            $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

            $metaArray = $product->meta->map(function ($meta) {
                return [
                    'meta_key' => $meta->meta_key,
                    'meta_value' => $meta->meta_value
                ];
            })->toArray(); // Ensure metaArray is a plain array

            // Filter meta based on authentication status
            $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                return $meta['meta_key'] !== '_price';
            }));

            return [
                'ID' => $product->ID,
                'ad_price' => $ad_price,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'thumbnail_url' => $thumbnailUrl,
                'categories' => $product->categories->map(function ($category) {
                    $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                    $taxonomy =  $category->taxonomies->taxonomy;
                    return [
                        'term_id' => $category->term_id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'visibility' => $visibility ? $visibility : 'public',
                        'taxonomy' => $taxonomy ? $taxonomy : 'public',
                    ];
                }),
                'meta' => $filteredMeta,
                'post_modified' => $product->post_modified
            ];
        });

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }
        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }

    public function searchProducts(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);
        $searchTerm = $request->input('searchTerm', '');
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $query = Product::with([
                    'meta' => function ($query) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name')
                    ->where('post_type', 'product')
                    ->where('post_status', 'publish');
            }
        } catch (\Throwable $th) {
            $query = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish');
        }


        if (!empty($searchTerm)) {
            $searchWords = preg_split('/\s+/', $searchTerm);
            $regexPattern = implode('.*', array_map(function ($word) {
                return "(?=.*" . preg_quote($word) . ")";
            }, $searchWords));

            $query->where(function ($query) use ($regexPattern) {
                $query->where('post_title', 'REGEXP', $regexPattern)
                    ->orWhere('post_name', 'REGEXP', $regexPattern);
            });
        }
        $products = $query->orderBy('post_date', 'desc')->paginate($perPage, ['*'], 'page', $page);

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $products->getCollection()->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });
            }
            return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
        } catch (\Throwable $th) {
            try {
                $originalCollection = $products->getCollection();

                $filteredCollection = $originalCollection->filter(function ($product) {
                    $hasProtectedCategory = $product->categories->contains(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        return $visibility === 'protected';
                    });
                    return !$hasProtectedCategory;
                });

                $transformedCollection = $filteredCollection->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });

                $products->setCollection($transformedCollection->values());

                return response()->json(['status' => 'no-auth', 'products' => $products]);
            } catch (\Throwable $th) {
                return response()->json(['status' => 'no-auth', 'message' => $th->getMessage()], 500);
            }
        }
    }

    public function searchProductsAll(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);

        $auth = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            $auth = true;
            if ($user->ID == 5417) {
                $query = Product::with([
                    'meta' => function ($query) use ($priceTier) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                },
                                'taxonomies' => function ($query) {
                                    $query->select('term_id', 'taxonomy');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')
                    ->where('post_status', 'trash')
                    ->whereIn('ID', $this->dummyProductList())
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    });
            } else {
                $query = Product::with([
                    'meta' => function ($query) use ($priceTier) {
                        $query->select('post_id', 'meta_key', 'meta_value')
                            ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                    },
                    'categories' => function ($query) {
                        $query->select('wp_terms.term_id', 'wp_terms.name')
                            ->with([
                                'categorymeta' => function ($query) {
                                    $query->select('term_id', 'meta_key', 'meta_value')
                                        ->where('meta_key', 'visibility');
                                },
                                'taxonomies' => function ($query) {
                                    $query->select('term_id', 'taxonomy');
                                }
                            ]);
                    }
                ])
                    ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                    ->where('post_type', 'product')->where('post_status', 'publish')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    });
            }

            if (!empty($searchTerm)) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $query->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $query->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT meta_value FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = 'total_sales' 
                          LIMIT 1) AS UNSIGNED) DESC
                ");
                    break;

                case 'plh':
                    $query->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                ");
                    break;

                case 'phl':
                    $query->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                ");
                    break;

                default:
                    $query->orderBy('post_date', 'desc');
                    break;
            }

            $products = $query->paginate($perPage, ['*'], 'page', $page);
        } catch (\Throwable $th) {
            $priceTier = '';

            $query = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                })
                ->where('post_type', 'product')->where('post_status', 'publish');

            if (!empty($searchTerm)) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $query->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT meta_value FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = 'total_sales' 
                          LIMIT 1) AS UNSIGNED) DESC
                ");
                    break;

                case 'plh':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                ");
                    break;

                case 'phl':
                    $products->with(['meta' => function ($query) use ($priceTier){
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id',$priceTier]);
                    }])
                        ->orderByRaw("
                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                          WHERE wp_postmeta.post_id = wp_posts.ID 
                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                ");
                    break;

                default:
                    $query->orderBy('post_date', 'desc');
                    break;
            }

            $products = $query->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user) {
                $products->getCollection()->transform(function ($product) use ($priceTier) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                    return [
                        'ID' => $product->ID,
                        'ad_price' => $ad_price,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            $taxonomy = $category->taxonomies->taxonomy;
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'slug' => $category->slug,
                                'visibility' => $visibility ? $visibility : 'public',
                                'taxonomy' => $taxonomy ? $taxonomy : 'public',
                            ];
                        }),
                        'meta' => $product->meta->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        }),
                        'post_modified' => $product->post_modified
                    ];
                });

                //cache
                // $userId = $user->ID;
                // $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
                // $etag = md5($userId . implode(',', $productModifiedTimestamps));
                // if ($request->header('If-None-Match') === $etag) {
                //     return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products], Response::HTTP_NOT_MODIFIED);
                // }
                // $response = response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
                // $response->header('ETag', $etag);

                // if ($auth) {
                //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                //     // $response->header('Cache-Control', 'public, max-age=300');
                // }
                // return $response;
                return response()->json(['status' => 'auth', 'user' => $user, 'products' => $products]);
            }
        } catch (\Throwable $th) {
            try {
                $originalCollection = $products->getCollection();

                $filteredCollection = $originalCollection->filter(function ($product) {
                    $hasProtectedCategory = $product->categories->contains(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        return $visibility === 'protected';
                    });
                    return !$hasProtectedCategory;
                });

                $transformedCollection = $filteredCollection->transform(function ($product) {
                    $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                    $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);

                    return [
                        'ID' => $product->ID,
                        'ad_price' => null,
                        'title' => $product->post_title,
                        'slug' => $product->post_name,
                        'thumbnail_url' => $thumbnailUrl,
                        'categories' => $product->categories->map(function ($category) {
                            $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                            return [
                                'term_id' => $category->term_id,
                                'name' => $category->name,
                                'visibility' => $visibility ? $visibility : 'public',
                            ];
                        }),
                        'meta' =>  $product->meta->filter(function ($meta) {
                            return $meta->meta_key !== '_price';
                        })->map(function ($meta) {
                            return [
                                'meta_key' => $meta->meta_key,
                                'meta_value' => $meta->meta_value
                            ];
                        })->values(),
                        'post_modified' => $product->post_modified
                    ];
                });

                $products->setCollection($transformedCollection->values());

                //cache
                // $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
                // if ($request->header('If-None-Match') === $etag) {
                //     return response()->json(['status' => 'no-auth', 'products' => $products], Response::HTTP_NOT_MODIFIED);
                // }
                // $response = response()->json(['status' => 'no-auth', 'products' => $products]);
                // $response->header('ETag', $etag);

                // if ($auth) {
                //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                //     // $response->header('Cache-Control', 'public, max-age=300');
                // }
                // return $response;

                return response()->json(['status' => 'no-auth', 'products' => $products]);
            } catch (\Throwable $th) {
                return response()->json(['status' => 'no-auth', 'message' => $th->getMessage()], 500);
            }
        }
    }

    public function getRelatedProducts($id)
    {
        // Fetch the product
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $subcatIds = $product->categories()
            ->whereHas('taxonomies', function ($query) {
                $query->where('parent', '!=', 0);
            })
            ->pluck('term_id');

        if ($subcatIds->isEmpty()) {
            return response()->json(['error' => 'No subcategories found for this product'], 404);
        }

        $relatedProducts = Product::whereHas('categories', function ($query) use ($subcatIds) {
            $query->whereIn('term_taxonomy_id', $subcatIds);
        })->where('post_status', 'publish')->orderBy('post_date', 'desc')->take(20)->get();
        if ($relatedProducts->isEmpty()) {
            return response()->json(['error' => 'No related products found'], 404);
        }

        $relatedProductsData = $relatedProducts->map(function ($relatedProduct) {
            $categoryVisibility = $relatedProduct->categories->map(function ($category) {
                return $category->visibility;
            })->toArray();

            return [
                'ID' => $relatedProduct->ID,
                'name' => $relatedProduct->post_title,
                'slug' => $relatedProduct->post_name,
                'thumbnail' => $relatedProduct->thumbnail_url,
                'product_visibility' => $relatedProduct->visibility,
                'date' => $relatedProduct->post_modified_gmt,
            ];
        });
        return response()->json(['related_products' => $relatedProductsData], 200);
    }
    public function getRelatedProductV2(Request $request,$id){
        $perPage = $request->query('perPage', 50);
        $page = $request->query('page', 1);

        // product
        $product = Product::with(['categories.taxonomies', 'meta'])->find($id);
        if (!$product) {
            return response()->json(['error' => 'Product not found']);
        }

        // get parent
        $parentCategory = null;
        foreach ($product->categories as $category) {
            if ($category->taxonomies->taxonomy === 'product_cat' && $category->taxonomies->parent == 0) {
                $t = $category['children'];
                if (!empty($t) && count($t) > 0) {
                    $parentCategory = $category;
                    break;
                }
            }
        }
        if (!$parentCategory) {
            return response()->json(['error' => 'Parent category not found']);
        }
        // get parent category brands
        $brands = Brand::where('term_id', $parentCategory->term_id)->orderBy('priority','desc')->get();
        $sideBrand = $brands;
        $highlyRecommendList = [];
        foreach ($brands as $brand) {
            if (!empty(json_decode($brand->meta))) {
                $highlyRecommendList = array_merge($highlyRecommendList, array_map('intval', json_decode($brand->meta)));
            }
        }
        // clean array 
        $highlyRecommendList = array_unique($highlyRecommendList);
        $highlyRecommendList = array_values($highlyRecommendList);
        $relatedProducts = Product::whereHas('categories.taxonomies', function ($query) use ($brands) {
            $query->whereIn('slug', $brands->pluck('brandUrl'))
                ->where('taxonomy', 'product_brand');
        })->where('post_status', 'publish')
        ->whereHas('meta', function ($query) {
            $query->where('meta_key', '_stock_status')
                ->where('meta_value', 'instock');
        })
            ->orderBy('post_date', 'desc')
            ->take(50)
            ->pluck('ID')
            ->toArray();
        // clean less recommend product
        $finalProductList = array_merge($highlyRecommendList, $relatedProducts);
        $finalProductList = array_unique($finalProductList);
        $finalProductList = array_values($finalProductList);
        $priceTier = '_price';
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '_price';
            if ($user->ID) {
                $auth = true;
            }
        } catch (\Throwable $th) {
            $auth = false;
        }
        $finalProducts = Product::with([
            'meta' => function ($query) use ($priceTier) {
                $query->select('post_id', 'meta_key', 'meta_value')
                    ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', $priceTier]);
            },
            'categories' => function ($query) {
                $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                    ->with([
                        'categorymeta' => function ($query) {
                            $query->select('term_id', 'meta_key', 'meta_value')
                                ->where('meta_key', 'visibility');
                        },
                        'taxonomies' => function ($query) {
                            $query->select('term_id', 'taxonomy');
                        }
                    ]);
            },
            'variations' => function ($query) use ($priceTier) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })->whereIn('ID',$finalProductList);
            if($auth == false){
                $finalProducts->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            }
            $finalProducts = $finalProducts->paginate($perPage, ['*'], 'page', $page);
            $finalProducts->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailUrl = $product->thumbnail ? $product->thumbnail->guid : null;
                $galleryImageIds = $product->meta->where('meta_key', '_product_image_gallery')->pluck('meta_value')->first();
                $galleryImages = [];
                if ($galleryImageIds) {
                    $imageIds = explode(',', $galleryImageIds);
                    $images = Product::whereIn('ID', $imageIds)->get();
                    foreach ($images as $image) {
                        $galleryImages[] = $image->guid;
                    }
                }
                $ad_price = null;
                if($auth == false){
                    $ad_price = null;
                } else {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $variationPrice = $variation->varients->where('meta_key', $priceTier)->pluck('meta_value')->first();
                            if ($variationPrice) {
                                $ad_price = $variationPrice;
                                break;
                            }
                        }
                    }
                    if ($ad_price === null) {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first();
                    }
                }
                if($auth == false){
                    $metaArray = $product->meta->map(function ($meta) {
                        return [
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $meta->meta_value
                        ];
                    })->toArray();
                    $meta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                        return $meta['meta_key'] !== '_price';
                    }));    
                }
                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'gallery_images' => $galleryImages, // Add gallery images here
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy =  $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $auth?$product->meta:$meta,
                    'variations' => $product->variations,
                    'post_modified' => $product->post_modified
                ];
            });

        // top left related product 
        $subcatIds = $product->categories()
            ->whereHas('taxonomies', function ($query) {
                $query->where('parent', '!=', 0);
            })
            ->pluck('term_id');

        if ($subcatIds->isEmpty()) {
            return response()->json(['error' => 'No subcategories found for this product'], 404);
        }

        $relatedProducts = Product::whereHas('categories', function ($query) use ($subcatIds) {
            $query->whereIn('term_taxonomy_id', $subcatIds);
        })
        ->whereHas('meta', function ($query) {
            $query->where('meta_key', '_stock_status')
                ->where('meta_value', 'instock');
        })->where('post_status', 'publish')->orderBy('post_date', 'desc')->take(10)->get();
        if ($relatedProducts->isEmpty()) {
            return response()->json(['error' => 'No related products found'], 404);
        }

        $relatedProductsData = $relatedProducts->map(function ($relatedProduct) {
            $categoryVisibility = $relatedProduct->categories->map(function ($category) {
                return $category->visibility;
            })->toArray();

            return [
                'ID' => $relatedProduct->ID,
                'name' => $relatedProduct->post_title,
                'slug' => $relatedProduct->post_name,
                'thumbnail' => $relatedProduct->thumbnail_url,
                'product_visibility' => $relatedProduct->visibility,
                'date' => $relatedProduct->post_modified_gmt,
            ];
        });
        return response()->json( ['brands'=>$sideBrand,'products'=>$finalProducts, 'top_products'=>$relatedProductsData]);
    }
    private function getVariations($productId, $priceTier = '')
    {
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                // Filter variations to include only those in stock
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with('meta')
            ->get()
            ->map(function ($variation) use ($priceTier) {
                // Get meta data as an array
                $metaData = $variation->meta->pluck('meta_value', 'meta_key')->toArray();

                // Construct the regex pattern to include the price tier
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

                // Filter meta data to include only the selected fields
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return $adPrice;
            });

        return $variations;
    }
    public function productList(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $auth = false;
        $productIDArray = $request->input('productIDs', []);
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                if ($user->ID == 5417) {
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'trash')
                        ->whereIn('ID', $this->dummyProductList())
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        });
                } else {
                    $products = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'publish')
                        ->whereIn('ID', $productIDArray)
                        ->whereHas('meta', function ($query) {
                            $query->where('meta_key', '_stock_status')
                                ->where('meta_value', 'instock');
                        });
                }
                switch ($sortBy) {
                    case 'popul':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                        break;

                    case 'plh':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                        break;

                    case 'phl':
                        $products->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                        break;

                    default:
                        $products->orderBy('post_date', 'desc');
                        break;
                }
                $products = $products->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_stock_status', '_sku', '_thumbnail_id']);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereIn('ID', $productIDArray)
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            switch ($sortBy) {
                case 'popul':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                    break;

                case 'plh':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                    break;

                case 'phl':
                    $products->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                    break;

                default:
                    $products->orderBy('post_date', 'desc');
                    break;
            }
            $products = $products->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }
    public function combineProducts(Request $request, string $slug = null)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sort', 'latest');
        $page = $request->query('page', 1);
        $searchTerm = $request->input('searchTerm', null);
        $auth = false;
        $catIDArray = $request->input('catIDs', []);
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $priceTier = $user->price_tier ?? '';
            if ($user->ID) {
                $auth = true;
                if ($user->ID == 5417) {
                    $productsQuery = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'trash')->whereIn('ID', $this->dummyProductList());
                } else {
                    $productsQuery = Product::with([
                        'meta' => function ($query) use ($priceTier) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                        },
                        'categories' => function ($query) {
                            $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                                ->with([
                                    'categorymeta' => function ($query) {
                                        $query->select('term_id', 'meta_key', 'meta_value')
                                            ->where('meta_key', 'visibility');
                                    },
                                    'taxonomies' => function ($query) {
                                        $query->select('term_id', 'taxonomy');
                                    }
                                ]);
                        }
                    ])
                        ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                        ->where('post_type', 'product')
                        ->where('post_status', 'publish');
                }



                // Apply category filter
                if (!empty($catIDArray)) {
                    $productsQuery->whereHas('categories.taxonomies', function ($query) use ($catIDArray) {
                        $query->whereIn('term_id', $catIDArray)
                            ->where('taxonomy', 'product_cat');
                    });
                }

                // Apply search filter
                if ($searchTerm) {
                    $searchWords = preg_split('/\s+/', $searchTerm);
                    $regexPattern = implode('.*', array_map(function ($word) {
                        return "(?=.*" . preg_quote($word) . ")";
                    }, $searchWords));

                    $productsQuery->where(function ($query) use ($regexPattern) {
                        $query->where('post_title', 'REGEXP', $regexPattern)
                            ->orWhereHas('meta', function ($query) use ($regexPattern) {
                                $query->where('meta_key', '_sku')
                                    ->where('meta_value', 'REGEXP', $regexPattern);
                            });
                    });
                }
                switch ($sortBy) {
                    case 'popul':
                        $productsQuery->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT meta_value FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = 'total_sales' 
                                              LIMIT 1) AS UNSIGNED) DESC
                                    ");
                        break;

                    case 'plh':
                        $productsQuery->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                    ");
                        break;

                    case 'phl':
                        $productsQuery->with(['meta' => function ($query) {
                            $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                        }])
                            ->orderByRaw("
                                        CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                              WHERE wp_postmeta.post_id = wp_posts.ID 
                                              AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                    ");
                        break;

                    default:
                        $productsQuery->orderBy('post_date', 'desc');
                        break;
                }
                $products = $productsQuery->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $priceTier = '';
            $productsQuery = Product::with([
                'meta' => function ($query) use ($priceTier) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id', $priceTier]);
                },
                'categories' => function ($query) {
                    $query->select('wp_terms.term_id', 'wp_terms.name', 'wp_terms.slug')
                        ->with([
                            'categorymeta' => function ($query) {
                                $query->select('term_id', 'meta_key', 'meta_value')
                                    ->where('meta_key', 'visibility');
                            },
                            'taxonomies' => function ($query) {
                                $query->select('term_id', 'taxonomy');
                            }
                        ]);
                }
            ])
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where('post_type', 'product')
                ->where('post_status', 'publish')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereDoesntHave('categories.categorymeta', function ($query) {
                    $query->where('meta_key', 'visibility')
                        ->where('meta_value', 'protected');
                });
            // Apply category filter
            if (!empty($catIDArray)) {
                $productsQuery->whereHas('categories.taxonomies', function ($query) use ($catIDArray) {
                    $query->whereIn('term_id', $catIDArray)
                        ->where('taxonomy', 'product_cat');
                });
            }

            // Apply search filter
            if ($searchTerm) {
                $searchWords = preg_split('/\s+/', $searchTerm);
                $regexPattern = implode('.*', array_map(function ($word) {
                    return "(?=.*" . preg_quote($word) . ")";
                }, $searchWords));

                $productsQuery->where(function ($query) use ($regexPattern) {
                    $query->where('post_title', 'REGEXP', $regexPattern)
                        ->orWhereHas('meta', function ($query) use ($regexPattern) {
                            $query->where('meta_key', '_sku')
                                ->where('meta_value', 'REGEXP', $regexPattern);
                        });
                });
            }

            switch ($sortBy) {
                case 'popul':
                    $productsQuery->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT meta_value FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = 'total_sales' 
                                          LIMIT 1) AS UNSIGNED) DESC
                                ");
                    break;

                case 'plh':
                    $productsQuery->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT MIN(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) ASC
                                ");
                    break;

                case 'phl':
                    $productsQuery->with(['meta' => function ($query) {
                        $query->whereIn('meta_key', ['total_sales', '_price', '_stock_status', '_sku', '_thumbnail_id']);
                    }])
                        ->orderByRaw("
                                    CAST((SELECT MAX(meta_value) FROM wp_postmeta 
                                          WHERE wp_postmeta.post_id = wp_posts.ID 
                                          AND wp_postmeta.meta_key = '_price') AS DECIMAL(10,2)) DESC
                                ");
                    break;

                default:
                    $productsQuery->orderBy('post_date', 'desc');
                    break;
            }
            $products = $productsQuery->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
                $thumbnailId = $product->meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->first();
                if (!$auth) {
                    $ad_price = null;
                } else {
                    try {
                        $ad_price = $product->meta->where('meta_key', $priceTier)->pluck('meta_value')->first() ?? '';
                        if ($ad_price == '') {
                            $ad_price = $this->getVariations($product->ID, $priceTier);
                            $ad_price = $ad_price[0];
                        }
                    } catch (\Throwable $th) {
                        $ad_price = null;
                    }
                }
                $thumbnailUrl = $this->getThumbnailUrl($thumbnailId);
                $metaArray = $product->meta->map(function ($meta) {
                    return [
                        'meta_key' => $meta->meta_key,
                        'meta_value' => $meta->meta_value
                    ];
                })->toArray(); // Ensure metaArray is a plain array

                // Filter meta based on authentication status
                $filteredMeta = $auth ? $metaArray : array_values(array_filter($metaArray, function ($meta) {
                    return $meta['meta_key'] !== '_price';
                }));

                return [
                    'ID' => $product->ID,
                    'ad_price' => $ad_price,
                    'title' => $product->post_title,
                    'slug' => $product->post_name,
                    'thumbnail_url' => $thumbnailUrl,
                    'categories' => $product->categories->map(function ($category) {
                        $visibility = $category->categorymeta->where('meta_key', 'visibility')->pluck('meta_value')->first();
                        $taxonomy = $category->taxonomies->taxonomy;
                        return [
                            'term_id' => $category->term_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'visibility' => $visibility ? $visibility : 'public',
                            'taxonomy' => $taxonomy ? $taxonomy : 'public',
                        ];
                    }),
                    'meta' => $filteredMeta,
                    'post_modified' => $product->post_modified
                ];
            });
        } catch (\Throwable $th) {
            return response()->json($th);
        }

        // //cache
        // if ($auth) {
        //     $userId = $user->ID;
        //     $productModifiedTimestamps = $products->pluck('post_modified')->toArray();
        //     $etag = md5($userId . implode(',', $productModifiedTimestamps));
        // } else {
        //     $etag = md5(implode(',', $products->pluck('post_modified')->toArray()));
        // }

        // if ($request->header('If-None-Match') === $etag) {
        //     return response()->json($products, Response::HTTP_NOT_MODIFIED);
        // }
        // $response = response()->json($products);
        // $response->header('ETag', $etag);

        // if ($auth) {
        //     $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        //     // $response->header('Cache-Control', 'public, max-age=300');
        // }
        // return $response;
        return response()->json($products);
    }

    
}
