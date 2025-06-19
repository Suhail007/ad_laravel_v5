<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Woo\ProductController;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use App\Services\GeoRestrictionService;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Automattic\WooCommerce\Client;
use Illuminate\Support\Facades\DB;

class WooCommerceController extends Controller
{

    public function getTaxonomyType($taxonomy)
    {
        if ($taxonomy->taxonomy === 'product_cat') {
            return 'category';
        } elseif ($taxonomy->taxonomy === 'product_brand') {
            return 'brand';
        }
        return 'unknown';
    }

    public function showOld(Request $request, $slug)
    {
        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                if ($user->ID == 5417) {
                    $dummyProductList = (new ProductController())->dummyProductList();
                    $product = Product::with([
                        'meta',
                        'categories.taxonomies',
                        'categories.children',
                        'categories.categorymeta'
                    ])->where('post_name', $slug) ->where('post_status', 'trash')

                    ->whereIn('ID', $dummyProductList) 
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->firstOrFail();
                } else {
                    $role=  key($user->capabilities); // "mm_price_2"
                    $product = Product::with([
                        'meta',
                        'categories.taxonomies',
                        'categories.children',
                        'categories.categorymeta'
                    ])->where('post_name', $slug) ->where('post_status', 'publish')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->firstOrFail();

                    
                    //user role match with category visibility 
                    // foreach($product->categories as $cat){
                    //     foreach($cat->categorymeta as $meta){
                    //         if($meta->meta_key == "user_roles"){
                    //             $user_roles = unserialize($meta->meta_value);
                    //             if (!in_array($role, $user_roles)) {
                    //                 return response()->json(['status'=>false, 'message'=>'Product not available for you']);
                    //             }
                    //         }
                    //     }
                    // }
                    
                    
                    // //user role match with category visibility 
                    $check1 = false;
                    $check2 = false;
                    foreach($product->categories as $cat){
                        foreach($cat->categorymeta as $meta){
                            if($meta->meta_key == "user_roles"){
                                $user_roles = unserialize($meta->meta_value);
                                if (!in_array($role, $user_roles)) {
                                    $check1 = true;
                                }
                            }
                            if($meta->meta_key == "visibility" && $meta->meta_value =="protected"){
                                $check2 = true;
                            }
                            if($check1 == true && $check2 == true){
                                return response()->json(['status'=>false, 'message'=>'Product not available for you']);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $th) {
            $product = Product::with([
                'meta',
                'categories.taxonomies',
                'categories.children',
                'categories.categorymeta'
            ]) 
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->whereDoesntHave('categories.categorymeta', function ($query) {
                $query->where('meta_key', 'visibility')
                    ->where('meta_value', 'protected');
            })
                ->where('post_name', $slug)->first();
            if (!$product) {
                return response()->json(['status' => false, 'message' => 'Product Not Found, Login to see Products']);
            }
        }

        $metaData = $product->meta->map(function ($meta) {
            return [
                'id' => $meta->meta_id,
                'key' => $meta->meta_key,
                'value' => $meta->meta_value,
            ];
        });

        $categories = $product->categories->map(function ($category) {
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'taxonomy' => $category->taxonomies,
                'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
                'children' => $category->children,
            ];
        });

        $brands = $product->categories->filter(function ($category) {
            // Check if the category's taxonomy type is 'brand'
            return $this->getTaxonomyType($category->taxonomies) === 'brand';
        })->map(function ($category) {
            $meta = $category->categorymeta->pluck('meta_value', 'meta_key')->toArray();
            if (isset($meta['thumbnail_id'])) {
                $meta['thumbnail_url']  = Product::where('ID', $meta['thumbnail_id'])->value('guid')??null;
            }
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'taxonomy' => $category->taxonomies,
                'meta' => $meta,
                'children' => $category->children,
            ];
        });

        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $galleryImagesUrls = $this->getGalleryImagesUrls($product->ID);
        $price = $metaData->where('key', '_price')->first()['value'] ?? '';
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $priceTier = $user->price_tier ?? '';
                $variations = $this->getVariations($product->ID, $priceTier);
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
                    'price' => $price ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $metaData->where('key', '_price')->first()['value'] ?? null,
                    'ad_price' => ProductMeta::where('post_id', $product->ID)->where('meta_key', $priceTier)->value('meta_value') ?? $this->getVariationsPrice($product->ID, $priceTier),//?? $metaData->where('key', '_price')->first()['value']  ?? $metaData->where('key', '_regular_price')->first()['value'] ?? $variations->ad_price ?? null,
                    'regular_price' => $metaData->where('key', '_regular_price')->first()['value'] ?? '',
                    'sale_price' => $metaData->where('key', '_sale_price')->first()['value'] ?? '',
                    'date_on_sale_from' => $metaData->where('key', '_sale_price_dates_from')->first()['value'] ?? null,
                    'date_on_sale_from_gmt' => $metaData->where('key', '_sale_price_dates_from_gmt')->first()['value'] ?? null,
                    'date_on_sale_to' => $metaData->where('key', '_sale_price_dates_to')->first()['value'] ?? null,
                    'date_on_sale_to_gmt' => $metaData->where('key', '_sale_price_dates_to_gmt')->first()['value'] ?? null,
                    // 'on_sale' => optional($metaData->where('key', '_sale_price')->first())->value ? true : false,
                    'purchasable' => $product->post_status === 'publish',
                    'total_sales' => $metaData->where('key', 'total_sales')->first()['value'] ?? 0,
                    'virtual' => $metaData->where('key', '_virtual')->first()['value'] ?? false,
                    'downloadable' => $metaData->where('key', '_downloadable')->first()['value'] ?? false,
                    'downloads' => [],  // Add logic for downloads if needed
                    'download_limit' => $metaData->where('key', '_download_limit')->first()['value'] ?? -1,
                    'download_expiry' => $metaData->where('key', '_download_expiry')->first()['value'] ?? -1,
                    'external_url' => $metaData->where('key', '_product_url')->first()['value'] ?? '',
                    'button_text' => $metaData->where('key', '_button_text')->first()['value'] ?? '',
                    'tax_status' => $metaData->where('key', '_tax_status')->first()['value'] ?? 'taxable',
                    'tax_class' => $metaData->where('key', '_tax_class')->first()['value'] ?? '',
                    'manage_stock' => $metaData->where('key', '_manage_stock')->first()['value'] ?? false,
                    'stock_quantity' => $metaData->where('key', '_stock')->first()['value'] ?? null,
                    'backorders' => $metaData->where('key', '_backorders')->first()['value'] ?? 'no',
                    'backorders_allowed' => $metaData->where('key', '_backorders')->first()['value'] === 'yes' ? true : false,
                    'backordered' => $metaData->where('key', '_backorders')->first()['value'] === 'notify' ? true : false,
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
                    'reviews_allowed' => $product->comment_status === 'open',
                    'average_rating' => $metaData->where('key', '_wc_average_rating')->first()['value'] ?? '0.00',
                    'rating_count' => $metaData->where('key', '_wc_rating_count')->first()['value'] ?? 0,
                    'parent_id' => $product->post_parent,
                    'purchase_note' => $metaData->where('key', '_purchase_note')->first()['value'] ?? '',
                    'categories' => $categories,
                    'brands' => $brands,
                    'images' => $galleryImagesUrls,
                    'thumbnail_url' => $thumbnailUrl,
                    'variations' => $variations,
                    'menu_order' => $product->menu_order,
                    'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
                    'has_options' => $metaData->where('key', '_has_options')->first()['value'] ?? true,
                    'post_password' => $product->post_password,
                    '_links' => [
                        'self' => [
                            ['href' => url('/wp-json/wc/v3/products/' . $product->ID)]
                        ],
                        'collection' => [
                            ['href' => url('/wp-json/wc/v3/products')]
                        ]
                    ],
                ];
            }
        } catch (\Throwable $th) {
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
                'featured' => $metaData->where('key', '_featured')->first()['value'] ?? false,
                'catalog_visibility' => $metaData->where('key', '_visibility')->first()['value'] ?? 'visible',
                'description' => $product->post_content,
                'short_description' => $product->post_excerpt,
                'sku' => $metaData->where('key', '_sku')->first()['value'] ?? '',
                'weight' => $metaData->where('key', '_weight')->first()['value'] ?? '',
                'dimensions' => [
                    'length' => $metaData->where('key', '_length')->first()['value'] ?? '',
                    'width' => $metaData->where('key', '_width')->first()['value'] ?? '',
                    'height' => $metaData->where('key', '_height')->first()['value'] ?? ''
                ],

                'parent_id' => $product->post_parent,
                'categories' => $categories,
                'brands' => $brands,
                'images' => $galleryImagesUrls,
                'thumbnail_url' => $thumbnailUrl,
                'variations' => [],
                'menu_order' => $product->menu_order,
                'meta_data' => $metaData,
                'stock_status' => $metaData->where('key', '_stock_status')->first()['value'] ?? 'instock',
                'has_options' => $metaData->where('key', '_has_options')->first()['value'] ?? true,
                '_links' => [
                    'self' => [
                        ['href' => url('/wp-json/wc/v3/products/' . $product->ID)]
                    ],
                    'collection' => [
                        ['href' => url('/wp-json/wc/v3/products')]
                    ]
                ],
            ];
        }


        return response()->json($response);
    }

    public function show(Request $request, $slug)
    {
        $auth = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                if ($user->ID == 5417) {
                    $dummyProductList = (new ProductController())->dummyProductList();
                    $product = Product::with([
                        'meta',
                        'categories.taxonomies',
                        'categories.children',
                        'categories.categorymeta'
                    ])->where('post_name', $slug) ->where('post_status', 'trash')

                    ->whereIn('ID', $dummyProductList) 
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->firstOrFail();
                } else {
                    $role=  key($user->capabilities); // "mm_price_2"
                    $product = Product::with([
                        'meta',
                        'categories.taxonomies',
                        'categories.children',
                        'categories.categorymeta'
                    ])->where('post_name', $slug) ->where('post_status', 'publish')
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->firstOrFail();

                    
                    //user role match with category visibility 
                    // foreach($product->categories as $cat){
                    //     foreach($cat->categorymeta as $meta){
                    //         if($meta->meta_key == "user_roles"){
                    //             $user_roles = unserialize($meta->meta_value);
                    //             if (!in_array($role, $user_roles)) {
                    //                 return response()->json(['status'=>false, 'message'=>'Product not available for you']);
                    //             }
                    //         }
                    //     }
                    // }
                    
                    
                    // //user role match with category visibility 
                    $check1 = false;
                    $check2 = false;
                    foreach($product->categories as $cat){
                        foreach($cat->categorymeta as $meta){
                            if($meta->meta_key == "user_roles"){
                                $user_roles = unserialize($meta->meta_value);
                                if (!in_array($role, $user_roles)) {
                                    $check1 = true;
                                }
                            }
                            if($meta->meta_key == "visibility" && $meta->meta_value =="protected"){
                                $check2 = true;
                            }
                            if($check1 == true && $check2 == true){
                                return response()->json(['status'=>false, 'message'=>'Product not available for you']);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $th) {
            $product = Product::with([
                'meta',
                'categories.taxonomies',
                'categories.children',
                'categories.categorymeta'
            ]) 
            ->whereHas('meta', function ($query) {
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->whereDoesntHave('categories.categorymeta', function ($query) {
                $query->where('meta_key', 'visibility')
                    ->where('meta_value', 'protected');
            })
                ->where('post_name', $slug)->first();
            if (!$product) {
                return response()->json(['status' => false, 'message' => 'Product Not Found, Login to see Products']);
            }
        }

        // Get all meta data in a single query
        $metaData = ProductMeta::where('post_id', $product->ID)
            ->get()
            ->pluck('meta_value', 'meta_key')
            ->toArray();

        $categories = $product->categories->map(function ($category) {
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'taxonomy' => $category->taxonomies,
                'meta' => $category->categorymeta->pluck('meta_value', 'meta_key')->toArray(),
                'children' => $category->children,
            ];
        });

        $brands = $product->categories->filter(function ($category) {
            // Check if the category's taxonomy type is 'brand'
            return $this->getTaxonomyType($category->taxonomies) === 'brand';
        })->map(function ($category) {
            $meta = $category->categorymeta->pluck('meta_value', 'meta_key')->toArray();
            if (isset($meta['thumbnail_id'])) {
                $meta['thumbnail_url']  = Product::where('ID', $meta['thumbnail_id'])->value('guid')??null;
            }
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'taxonomy' => $category->taxonomies,
                'meta' => $meta,
                'children' => $category->children,
            ];
        });

        $thumbnailUrl = $this->getThumbnailUrl($product->ID);
        $galleryImagesUrls = $this->getGalleryImagesUrls($product->ID);
        $price = $metaData['_price'] ?? '';
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                // geo resticted Product info mesage 
                $geoRresticted = [];
                $priceTier = $user->price_tier ?? '';
                $variations = $this->getVariations($product->ID, $priceTier);
                $checkout = Checkout::where('user_id', $user->ID)->first();
                $location = [];
                if($checkout){
                    $location = [
                        'state' => $checkout['shipping']['state'] ?? '',
                        'city' => $checkout['shipping']['city'] ?? '',
                        'zip' => $checkout['shipping']['postcode'] ?? ''
                    ];
                } else {
                    $location = [
                        'state' => $user->meta()->where('meta_key', 'shipping_state')->value('meta_value') ?? '',
                        'city' => $user->meta()->where('meta_key', 'shipping_city')->value('meta_value') ?? '',
                        'zip' => $user->meta()->where('meta_key', 'shipping_postcode')->value('meta_value') ?? ''
                    ];
                }
                $isRestricted = false;
                // $geoRestrictionService = app(GeoRestrictionService::class);
                // $isRestricted = $geoRestrictionService->isProductRestricted($product->ID, $location);

                if ($isRestricted) {
                    $geoRresticted[] = [
                        'location' => $location,
                        'restriction' => $isRestricted,
                        'reason' => 'This product is not available for shipping to your location. We\'re unable to deliver this item to your shipping address due to shipping restrictions.'
                    ];
                }
                $response = [
                    'id' => $product->ID,
                    'name' => $product->post_title,
                    'slug' => $product->post_name,
                    'permalink' => url('/product/' . $product->post_name),
                    'geo_restricted' => $geoRresticted??[],
                    'your_location' => $location,
                    'date_created' => $product->post_date,
                    'date_created_gmt' => $product->post_date_gmt,
                    'date_modified' => $product->post_modified,
                    'date_modified_gmt' => $product->post_modified_gmt,
                    'type' => $product->post_type,
                    'status' => $product->post_status,
                    'min_quantity' => $metaData['min_quantity'] ?? false,
                    'max_quantity' => $metaData['max_quantity'] ?? false,
                    'featured' => $metaData['_featured'] ?? false,
                    'catalog_visibility' => $metaData['_visibility'] ?? 'visible',
                    'description' => $product->post_content,
                    'short_description' => $product->post_excerpt,
                    'sku' => $metaData['_sku'] ?? '',
                    'price' => $price ?? $metaData['_regular_price'] ?? $metaData['_price'] ?? null,
                    'ad_price' => ProductMeta::where('post_id', $product->ID)->where('meta_key', $priceTier)->value('meta_value') ?? $this->getVariationsPrice($product->ID, $priceTier),
                    'regular_price' => $metaData['_regular_price'] ?? '',
                    'sale_price' => $metaData['_sale_price'] ?? '',
                    'date_on_sale_from' => $metaData['_sale_price_dates_from'] ?? null,
                    'date_on_sale_from_gmt' => $metaData['_sale_price_dates_from_gmt'] ?? null,
                    'date_on_sale_to' => $metaData['_sale_price_dates_to'] ?? null,
                    'date_on_sale_to_gmt' => $metaData['_sale_price_dates_to_gmt'] ?? null,
                    'purchasable' => $product->post_status === 'publish',
                    'total_sales' => $metaData['total_sales'] ?? 0,
                    'virtual' => $metaData['_virtual'] ?? false,
                    'downloadable' => $metaData['_downloadable'] ?? false,
                    'downloads' => [],  // Add logic for downloads if needed
                    'download_limit' => $metaData['_download_limit'] ?? -1,
                    'download_expiry' => $metaData['_download_expiry'] ?? -1,
                    'external_url' => $metaData['_product_url'] ?? '',
                    'button_text' => $metaData['_button_text'] ?? '',
                    'tax_status' => $metaData['_tax_status'] ?? 'taxable',
                    'tax_class' => $metaData['_tax_class'] ?? '',
                    'manage_stock' => $metaData['_manage_stock'] ?? false,
                    'stock_quantity' => $metaData['_stock'] ?? null,
                    'backorders' => $metaData['_backorders'] ?? 'no',
                    'backorders_allowed' => ($metaData['_backorders'] ?? '') === 'yes',
                    'backordered' => ($metaData['_backorders'] ?? '') === 'notify',
                    'low_stock_amount' => $metaData['_low_stock_amount'] ?? null,
                    'sold_individually' => $metaData['_sold_individually'] ?? false,
                    'weight' => $metaData['_weight'] ?? '',
                    'dimensions' => [
                        'length' => $metaData['_length'] ?? '',
                        'width' => $metaData['_width'] ?? '',
                        'height' => $metaData['_height'] ?? ''
                    ],
                    'shipping_required' => $metaData['_shipping'] ?? true,
                    'shipping_taxable' => $metaData['_shipping_taxable'] ?? true,
                    'shipping_class' => $metaData['_shipping_class'] ?? '',
                    'shipping_class_id' => $metaData['_shipping_class_id'] ?? 0,
                    'reviews_allowed' => $product->comment_status === 'open',
                    'average_rating' => $metaData['_wc_average_rating'] ?? '0.00',
                    'rating_count' => $metaData['_wc_rating_count'] ?? 0,
                    'parent_id' => $product->post_parent,
                    'purchase_note' => $metaData['_purchase_note'] ?? '',
                    'categories' => $categories,
                    'brands' => $brands,
                    'images' => $galleryImagesUrls,
                    'thumbnail_url' => $thumbnailUrl,
                    'variations' => $variations,
                    'menu_order' => $product->menu_order,
                    'stock_status' => $metaData['_stock_status'] ?? 'instock',
                    'has_options' => $metaData['_has_options'] ?? true,
                    'post_password' => $product->post_password,
                    '_links' => [
                        'self' => [
                            ['href' => url('/wp-json/wc/v3/products/' . $product->ID)]
                        ],
                        'collection' => [
                            ['href' => url('/wp-json/wc/v3/products')]
                        ]
                    ],
                ];
            }
        } catch (\Throwable $th) {
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
                'featured' => $metaData['_featured'] ?? false,
                'catalog_visibility' => $metaData['_visibility'] ?? 'visible',
                'description' => $product->post_content,
                'short_description' => $product->post_excerpt,
                'sku' => $metaData['_sku'] ?? '',
                'weight' => $metaData['_weight'] ?? '',
                'dimensions' => [
                    'length' => $metaData['_length'] ?? '',
                    'width' => $metaData['_width'] ?? '',
                    'height' => $metaData['_height'] ?? ''
                ],

                'parent_id' => $product->post_parent,
                'categories' => $categories,
                'brands' => $brands,
                'images' => $galleryImagesUrls,
                'thumbnail_url' => $thumbnailUrl,
                'variations' => [],
                'menu_order' => $product->menu_order,
                'meta_data' => $metaData,
                'stock_status' => $metaData['_stock_status'] ?? 'instock',
                'has_options' => $metaData['_has_options'] ?? true,
                '_links' => [
                    'self' => [
                        ['href' => url('/wp-json/wc/v3/products/' . $product->ID)]
                    ],
                    'collection' => [
                        ['href' => url('/wp-json/wc/v3/products')]
                    ]
                ],
            ];
        }
        return response()->json($response);
    }

    private function getVariations($productId, $priceTier = '')
    {
        $couponProductIds = (new CartController())->couponProductID(); 
        $variations = Product::where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereHas('meta', function ($query) {
                // Filter variations to include only those in stock
                $query->where('meta_key', '_stock_status')
                    ->where('meta_value', 'instock');
            })
            ->with('meta')
            ->get()
            ->map(function ($variation) use ($priceTier,$couponProductIds) {
                // Get meta data as an array
                $metaData = $variation->meta->pluck('meta_value', 'meta_key')->toArray();

                // Construct the regex pattern to include the price tier
                $pattern = '/^(_sku|attribute_.*|_stock|_regular_price|_price|_stock_status|max_quantity|min_quantity|max_quantity_var|min_quantity_var' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';

                // Filter meta data to include only the selected fields
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);

                // Determine the price to use based on price tier or fallback to regular price
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return [
                    'id' => $variation->ID,
                    'isCouponProduct'=>in_array($variation->ID, $couponProductIds) ? true : false,
                    'date' => $variation->post_modified_gmt,
                    'meta' => $filteredMetaData,
                    'ad_price' => $adPrice,  // Include ad_price here
                    'thumbnail_url' => $this->getThumbnailUrl($variation->ID),  // Add variation thumbnail URL here
                    'gallery_images_urls' => $this->getGalleryImagesUrls($variation->ID),  // Add variation gallery images URLs here
                ];
            });

        return $variations;
    }

    private function getVariationsPrice($productId, $priceTier = '')
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
                $metaData = $variation->meta->pluck('meta_value', 'meta_key')->toArray();
                $pattern = '/^(_regular_price|_price' . preg_quote($priceTier, '/') . '|_thumbnail_id)$/';
                $filteredMetaData = array_filter($metaData, function ($key) use ($pattern) {
                    return preg_match($pattern, $key);
                }, ARRAY_FILTER_USE_KEY);
                $adPrice = $metaData[$priceTier] ?? $metaData['_price'] ?? $metaData['_regular_price'] ?? null;

                return $adPrice;
            });

        return $variations[0];
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
    private function getGalleryImagesUrls($productId)
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


    private function woocommerce()
    {
        $woocommerce = new Client(
            config('services.woocommerce.url'),
            config('services.woocommerce.consumer_key'),
            config('services.woocommerce.consumer_secret'),
            [
                'version' => 'wc/v3',
            ]
        );
        return $woocommerce;
    }

    public function createOrder(Request $request)
    {
        $apiUrl = 'https://adfe.phantasm.solutions/wp-json/wc/v3/orders';
        $consumerKey = 'ck_c8dc03022f8f45e6f71552507ef3f36b9d21b272';
        $consumerSecret = 'cs_ff377d2ce01a253a56090984036b08c727d945b5';

        $data = [
            // "paymentType"=>"card",
            'payment_method' => 'bacs',
            'payment_method_title' => 'Direct Bank Transfer',
            'set_paid' => true,
            'billing' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address_1' => '969 Market',
                'address_2' => '',
                'city' => 'Chicago',
                'state' => 'IL',
                'postcode' => '60665',
                'country' => 'US',
                'email' => 'john.doe@example.com',
                'phone' => '5555555555'
            ],
            'shipping' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address_1' => '969 Market',
                'address_2' => '',
                'city' => 'Chicago',
                'state' => 'IL',
                'postcode' => '60665',
                'country' => 'US'
            ],
            'line_items' => [
                [
                    'product_id' => 12,
                    'variation_id' => 15,
                    'quantity' => 1,
                    "tax_class" => "vapes",
                    "subtotal" => "600.00",
                    "subtotal_tax" => "0.45",
                    "total" => "600.00",
                    "total_tax" => "0.45",
                    "taxes" => [
                        [
                            "id" => 75,
                            "total" => "0.45",
                            "subtotal" => "0.45"
                        ]
                    ],
                    'meta_data' => [
                        [
                            'key' => '_indirect_tax_amount',
                            'value' => '0.45'
                        ],
                        [
                            'key' => '_indirect_tax_basis',
                            'value' => '60'
                        ]
                        ],
                    "sku" => "",
                    "price" => 300
                ]
            ],
            'shipping_lines' => [
                [
                    'method_id' => 'flat_rate',
                    'method_title' => 'Flat Rate',
                    'total' => '15.00'
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_USERPWD, $consumerKey . ":" . $consumerSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo $response;
        }
        curl_close($ch);
    }
    public function getUAddresses(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->ID;

        $woocommerce = $this->woocommerce();
        $data = $woocommerce->get('customers/' . $userId);
        return response()->json($data);
    }
}
