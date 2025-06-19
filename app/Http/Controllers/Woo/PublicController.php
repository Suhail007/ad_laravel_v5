<?php

namespace App\Http\Controllers\Woo;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class PublicController extends Controller
{
    public function show(Request $request, $slug)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $product = Product::with([
                    'meta',
                    'categories.taxonomies',
                    'categories.children',
                    'categories.categorymeta'
                ])->where('post_name', $slug)->firstOrFail();
            }
        } catch (\Throwable $th) {
            $product = Product::with([
                'meta',
                'categories.taxonomies',
                'categories.children',
                'categories.categorymeta'
            ])->whereDoesntHave('categories.categorymeta', function ($query) {
                $query->where('meta_key', 'visibility')
                    ->where('meta_value', 'protected');
            })
                ->where('post_name', $slug)->first();
            if (!$product) {
                return response()->json(['status' => false, 'message' => 'Product Not Found, Login to see Products']);
            }
        }


        // $product = Product::with([
        //     'meta',
        //     'categories.taxonomies',
        //     'categories.children',
        //     'categories.categorymeta'
        // ])->where('post_name', $slug)->firstOrFail();

        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });


        // $categories = $product->categories->map(function ($category) {
        //     return [
        //         'id' => $category->term_id,
        //         'name' => $category->name,
        //         'slug' => $category->slug,
        //         'taxonomy' => $category->taxonomies,
        //         'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
        //         'children' => $category->children,
        //     ];
        // });

        // $brands = $product->categories->filter(function ($category) {
        //     // Check if the category's taxonomy type is 'brand'
        //     return $this->getTaxonomyType($category->taxonomies) === 'brand';
        // })->map(function ($category) {
        //     return [
        //         'id' => $category->term_id,
        //         'name' => $category->name,
        //         'slug' => $category->slug,
        //         'taxonomy' => $category->taxonomies,
        //         'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
        //         'children' => $category->children,
        //     ];
        // });

        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $price = $metaData->where('key', '_price')->first()['value'] ?? '';


        $priceTier = '';
        $variations = $this->getVariations($product->ID, $priceTier);
        $descriptionHtml = $product->post_content;

        // Strip HTML tags to get plain text
        $descriptionPlainText = strip_tags($descriptionHtml);

        // Optionally, you might want to replace multiple new lines with a single line break
        $descriptionPlainText = preg_replace('/\s+/', ' ', $descriptionPlainText);

        // You can also trim the content to remove extra spaces at the beginning and end
        $descriptionPlainText = trim($descriptionPlainText);
        $response = [
            'id' => $product->ID,
            'name' => $product->post_title,
            'slug' => $product->post_name,
            'permalink' => url('/product/' . $product->post_name),
            'type' => $product->post_type,
            'status' => $product->post_status,
            'min_quantity' => $metaData->where('key', 'min_quantity')->first()['value'] ?? false,
            'max_quantity' => $metaData->where('key', 'max_quantity')->first()['value'] ?? null,
            'description' => $descriptionPlainText,
            'short_description' => $product->post_excerpt,
            'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
            'ad_price' => $wholesalePrice = ProductMeta::where('post_id', $product->ID)->where('meta_key', $priceTier)->value('meta_value') ?? $metaData->where('key', '_price')->first()['value']  ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $variations->ad_price ?? null,
            'price' => $price ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $metaData->where('key', '_price')->first()['value'] ?? null,
            'purchasable' => $product->post_status === 'publish',
            'catalog_visibility' => $metaData->where('key', '_visibility')->first()['value'] ?? 'visible',
            'tax_status' => $metaData->where('key', '_tax_status')->first()['value'] ?? 'taxable',
            'tax_class' => $metaData->where('key', '_tax_class')->first()['value'] ?? '',
            'stock_quantity' => $metaData->where('key', '_stock')->first()['value'] ?? null,
            'variations' => $variations,
            'thumbnail_url' => $thumbnailUrl,
            'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
        ];




        return response()->json($response);
    }

    public function getTaxonomyType($taxonomy)
    {
        if ($taxonomy->taxonomy === 'product_cat') {
            return 'category';
        } elseif ($taxonomy->taxonomy === 'product_brand') {
            return 'brand';
        }
        return 'unknown';
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
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status|max_quantity|min_quantity' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

                // Filter meta data to include only the selected fields
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);

                // Determine the price to use based on price tier or fallback to regular price
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return [
                    'id' => $variation->ID,
                    'date' => $variation->post_modified_gmt,
                    'meta' => $filteredMetaData,
                    'ad_price' => $adPrice,  // Include ad_price here
                    'thumbnail_url' => $this->getThumbnailUrl($variation->ID),  // Add variation thumbnail URL here
                ];
            });

        return $variations;
    }



    private function getThumbnailUrl($productId)
    {
        $thumbnailId = ProductMeta::where('post_id', $productId)->where('meta_key', '_thumbnail_id')->value('meta_value');
        if ($thumbnailId) {
            $url = Product::where('ID', $thumbnailId)->value('guid');
            if ($url) {
                return str_replace('http://localhost/ad', 'https://eadn-wc05-12948169.nxedge.io', $url);
            }
        }
        return null;
    }









    //temp functions 
    public function syncWoocat()
    {
        $categories = DB::table('wp_terms as terms')
            ->join('wp_term_taxonomy as taxonomy', 'terms.term_id', '=', 'taxonomy.term_id')
            ->leftJoin('wp_termmeta as termmeta', function ($join) {
                $join->on('terms.term_id', '=', 'termmeta.term_id')
                    ->where('termmeta.meta_key', '=', 'visibility');
            })
            ->select(
                'terms.term_id as id',
                'terms.name',
                'terms.slug',
                'taxonomy.parent',
                'taxonomy.taxonomy',
                'termmeta.meta_value as visibility'
            )
            ->where('taxonomy.taxonomy', 'product_cat') // Only categories
            ->get();
        // Build a nested structure of categories
        $parentCategories = $categories->where('parent', 0);
        $nestedCategories = $parentCategories->map(function ($parent) use ($categories) {
            $parent->children = $categories->where('parent', $parent->id)->values();
            return $parent;
        });
        return response()->json($nestedCategories);
    }
    public function getThumbnail($thumbnailId)
    {
        if (!$thumbnailId) {
            return null;
        }
        $attachment = DB::table('wp_posts')->where('ID', $thumbnailId)->first();
        if ($attachment) {
            $imageUrl = $attachment->guid;
            $new_domain = 'http://localhost/gsdwp';
            $position = strpos($imageUrl, '/wp-content/uploads/');
            if ($position !== false) {
                $imageUrl = $new_domain . substr($imageUrl, $position);
            }
            return $imageUrl;
        }
        return null;
    }
    public function syncWooBrand()
    {
        // Fetch data from WooCommerce
        $categories = DB::table('wp_terms as terms')
            ->join('wp_term_taxonomy as taxonomy', 'terms.term_id', '=', 'taxonomy.term_id')
            ->leftJoin('wp_termmeta as termmeta', function ($join) {
                $join->on('terms.term_id', '=', 'termmeta.term_id')
                    ->where('termmeta.meta_key', '=', 'thumbnail_id');
            })
            ->select(
                'terms.term_id as id',
                'terms.name',
                'terms.slug',
                'taxonomy.parent',
                'taxonomy.taxonomy',
                'termmeta.meta_value as thumbnail_id',
                DB::raw("'1' as business_id"), // Assuming business_id is constant
                DB::raw("'1' as created_by"), // Assuming created_by is constant
                DB::raw("'public' as visibility") // Default visibility
            )
            ->where('taxonomy.taxonomy', 'product_brand')
            ->get();
        // Process parent and child relationships
        $parentCategories = $categories->where('parent', 0);
        $nestedCategories = $parentCategories->map(function ($parent) use ($categories) {
            // Get logo URL for the brand
            $parent->logo = $this->getThumbnail($parent->thumbnail_id);
            // Map child categories
            $parent->children = $categories->where('parent', $parent->id)->map(function ($child) {
                $child->logo = $this->getThumbnail($child->thumbnail_id);
                return $child;
            })->values();
            return $parent;
        });
        // Map data into the desired structure
        $finalCategories = $nestedCategories->map(function ($category) {
            return [
                'id' => $category->id,
                'business_id' => $category->business_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => null, // Update if you have description data
                'created_by' => $category->created_by,
                'deleted_at' => null,
                'created_at' => null,
                'updated_at' => null,
                'visibility' => $category->visibility,
                'logo' => $category->logo,
                'banner' => null, // Update if you have banner data
                'body' => null, // Update if you have additional data
                'children' => $category->children->map(function ($child) {
                    return [
                        'id' => $child->id,
                        'business_id' => $child->business_id,
                        'name' => $child->name,
                        'description' => null, // Update if needed
                        'created_by' => $child->created_by,
                        'deleted_at' => null,
                        'created_at' => null,
                        'updated_at' => null,
                        'visibility' => $child->visibility,
                        'logo' => $child->logo,
                        'banner' => null,
                        'body' => null,
                    ];
                }),
            ];
        });
        return response()->json($finalCategories);
    }
    private function getGalleryImages($productId)
    {
        $galleryIds = ProductMeta::where('post_id', $productId)->where('meta_key', '_product_image_gallery')->value('meta_value');
        if ($galleryIds) {
            $galleryIdsArray = explode(',', $galleryIds);
            $galleryUrls = [];
            foreach ($galleryIdsArray as $id) {
                $url = Product::where('ID', $id)->value('guid');
                if ($url) {
                    $galleryUrls[] = str_replace('http://localhost/ad', 'https://eadn-wc05-12948169.nxedge.io', $url);
                }
            }
            return $galleryUrls;
        }
        return [];
    }
    public function wooProduct(Request $request, $slug = null)
    {
        // on first request give first product from wp_post table and
        $product = Product::with([
            'meta',
            'categories.taxonomies',
            'categories.children',
            'categories.categorymeta'
        ])->where('ID', $slug)
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->firstOrFail();
        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });
        $categories = $product->categories->filter(function ($category) {
            return $this->getTaxonomyType($category->taxonomies) === 'category';
        })->map(function ($category) {

            $displayType = null;

            foreach ($category->categorymeta as $meta) {
                if ($meta->meta_key === 'visibility') {
                    $displayType = $meta->meta_value;
                    break;
                }
            }
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'visibility' => $displayType
            ];
        });
        $brands = $product->categories->filter(function ($category) {
            return $this->getTaxonomyType($category->taxonomies) === 'brand';
        })->map(function ($category) {
            $displayType = null;
            foreach ($category->categorymeta as $meta) {
                if ($meta->meta_key === 'thumbnail_id') {
                    $displayType = (int) $meta->meta_value;
                    break;
                }
            }
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'thumbnail_id' => $displayType
            ];
        });
        try {
            //code...
            $thumbnailUrl = $this->getThumbnail($metaData->where('key', '_thumbnail_id')->first()['value']);
        } catch (\Throwable $th) {
            //throw $th;
            $thumbnailUrl =  'https://adfe.phantasm.solutions/img/default.png';
        }
        $galleryImagesUrls = $this->getGalleryImages($product->ID);
        $variations = $this->getVariation($product->ID);
        $response = [
            'id' => $product->ID,
            'name' => $product->post_title,
            'slug' => $product->post_name,
            'permalink' => url('/product/' . $product->post_name),
            'date_created' => $product->post_date,
            'date_created_gmt' => $product->post_date_gmt,
            'date_modified' => $product->post_modified,
            'date_modified_gmt' => $product->post_modified_gmt,
            'type' => $product->post_type,
            'status' => $product->post_status,
            'min_quantity' => $metaData->where('key', 'min_quantity')->first()['value'] ?? false,
            'max_quantity' => $metaData->where('key', 'max_quantity')->first()['value'] ?? false,
            'featured' => $metaData->where('key', '_featured')->first()['value'] ?? false,
            'catalog_visibility' => $metaData->where('key', '_visibility')->first()['value'] ?? 'visible',
            'description' => $product->post_content,
            'short_description' => $product->post_excerpt,
            'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
            'mm_product_upc' => $metaData->where('key', 'mm_product_upc')->first()['value'] ??  uniqid('product_', true),
            'ml' => $metaData->where('key', 'mm_product_basis_1')->first()['value'] ?? null,
            'ct' => $metaData->where('key', 'mm_product_basis_2')->first()['value'] ?? null,
            'price' => $metaData->where('key', '_price')->first()['value'] ?? '',
            'regular_price' => $metaData->where('key', '_regular_price')->first()['value'] ?? '',
            'purchasable' => $product->post_status === 'publish',
            'total_sales' => $metaData->where('key', 'total_sales')->first()['value'] ?? 0,
            'button_text' => $metaData->where('key', '_button_text')->first()['value'] ?? '',
            'tax_status' => $metaData->where('key', '_tax_status')->first()['value'] ?? 'taxable',
            'tax_class' => $metaData->where('key', '_tax_class')->first()['value'] ?? '',
            'manage_stock' => $metaData->where('key', '_manage_stock')->first()['value'] ?? false,
            'stock_quantity' => $metaData->where('key', '_stock')->first()['value'] ?? null,
            'low_stock_amount' => $metaData->where('key', '_low_stock_amount')->first()['value'] ?? null,
            'sold_individually' => $metaData->where('key', '_sold_individually')->first()['value'] ?? false,
            'weight' => $metaData->where('key', '_weight')->first()['value'] ?? '',
            'dimensions' => [
                'length' => $metaData->where('key', '_length')->first()['value'] ?? '',
                'width' => $metaData->where('key', '_width')->first()['value'] ?? '',
                'height' => $metaData->where('key', '_height')->first()['value'] ?? ''
            ],
            'shipping_required' => $metaData->where('key', '_shipping')->first()['value'] ?? true,
            'shipping_taxable' => $metaData->where('key', '_shipping_taxable')->first()['value'] ?? true,
            'shipping_class' => $metaData->where('key', '_shipping_class')->first()['value'] ?? '',
            'shipping_class_id' => $metaData->where('key', '_shipping_class_id')->first()['value'] ?? 0,
            'parent_id' => $product->post_parent,
            'categories' => $categories,
            'brands' => $brands,
            'images' => $galleryImagesUrls,
            'thumbnail_url' => $thumbnailUrl,
            'variations' => $variations,
            'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
            'meta' => $metaData ?? [],
        ];
        $nextProduct = Product::with(['meta'])
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->where('post_parent', 0)
            ->where('ID', '>', $product->ID)
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->orderBy('ID', 'asc')
            ->first();
        $nextProductSlug = $nextProduct ? $nextProduct->ID : null;
        return response()->json(['product' => $response, 'nextProduct' => $nextProductSlug]);
    }
    private function getVariation($productId, $priceTier = '')
    {
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                // Filter variations to include only those in stock
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with('meta')
            ->get();
        return $variations;
    }
    private function syncPorductVarients($productId)
    {
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with(['meta' => function ($query) {
                $query->whereIn('meta_key', [
                    '_sku',
                    'mm_indirect_tax_type',
                    // 'mm_product_basis_1',
                    // 'mm_product_basis_2',
                ]);
            }])
            ->get();
        return $variations->map(function ($variation) {
            return [
                'sku' => $variation->meta->where('meta_key', '_sku')->first()->meta_value ?? null,
                'mm_indirect_tax_type' => $variation->meta->where('meta_key', 'mm_indirect_tax_type')->first()->meta_value ?? null,
                // 'product_basis_1' => $variation->meta->where('meta_key', 'mm_product_basis_1')->first()->meta_value ?? null,
                // 'product_basis_2' => $variation->meta->where('meta_key', 'mm_product_basis_2')->first()->meta_value ?? null,
            ];
        });
    }

    public function syncProductMeta($id)
    {
        $product = Product::with(['meta' => function ($query) {
            $query->whereIn('meta_key', [
                'mm_indirect_tax_type',
                '_sku',
                'mm_product_basis_1',
                'mm_product_basis_2',
                'mm_product_basis_3',
            ]);
        }])->find($id);
        $skuResults = [];
        $skku = $product->post_name??null;
        $value = null;
        $variations = $this->syncPorductVarients($product->ID);
        $mmIndirectTaxType = null;
        foreach ($variations as $variation) {
            if (!empty($variation['mm_indirect_tax_type'])) {
                $mmIndirectTaxType = $variation['mm_indirect_tax_type'];
                break;
            }
        }
        if (!$mmIndirectTaxType) {
            $productMeta = $product->meta->where('meta_key', 'mm_indirect_tax_type')->first();

            if ($productMeta) {
                $mmIndirectTaxType = $productMeta->meta_value;
            }
        }
        if ($mmIndirectTaxType) {
            if ($mmIndirectTaxType == "14346") {
                $value = 1;
            } elseif ($mmIndirectTaxType == "14347") {
                $value = 6;
            } elseif ($mmIndirectTaxType == "14344") {
                $value = 2;
            } elseif ($mmIndirectTaxType == "14343") {
                $value = 5;
            } elseif ($mmIndirectTaxType == "14345") {
                $value = 3;
            }
        } 
        $nextProduct = Product::with(['meta'])
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->where('post_parent', 0)
            ->where('ID', '<', $product->ID)
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->orderBy('ID', 'desc')
            ->first();
        $nextPro = $nextProduct ? $nextProduct->ID : null;
        try {
            //code...
            return response()->json(['value'=>$value, 'next'=>$nextPro, 'sku'=>$skku]);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['value'=>$value, 'next'=>$nextPro]);
        }
    }
    public function syncUser(){
        $page = request()->get('page')??1;
        $perPage = request()->get('perPage')??100;
        $users = User::with('meta')->paginate($perPage,['*'],'page',$page);
        return response()->json(['users'=>$users,'status'=>true]);
    }
}
