<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CleanupController extends Controller
{
    public function category(string $value = null)
    {
        if ($value == null) {
            $data = Category::with([
                'categorymeta' => function ($query) {
                    $query->where('meta_key', 'visibility');
                },
                'taxonomy' => function ($query) {
                    $query->select('term_id', 'taxonomy');
                }
            ])
                ->whereHas('taxonomy', function ($query) {
                    $query->where('taxonomy', 'product_cat');
                })
                ->take(10)
                ->get();
            return response()->json($data);
        }
        $data = Category::with([
            'categorymeta' => function ($query) {
                $query->where('meta_key', 'visibility');
            },
            'taxonomy' => function ($query) {
                $query->select('term_id', 'taxonomy');
            }
        ])
            ->whereNested(function ($query) use ($value) {
                $query->where('name', 'LIKE', '%' . $value . '%')
                    ->orWhere('slug', 'LIKE', '%' . $value . '%');
            })
            ->whereHas('taxonomy', function ($query) {
                $query->where('taxonomy', 'product_cat');
            })
            ->get();
        return response()->json($data);
    }
    public function brand(string $value = null)
    {
        if ($value == null) {
            $data = Category::with([
                'categorymeta' => function ($query) {
                    $query->where('meta_key', 'visibility');
                },
                'taxonomy' => function ($query) {
                    $query->select('term_id', 'taxonomy');
                }
            ])
                ->whereHas('taxonomy', function ($query) {
                    $query->where('taxonomy', 'product_brand');
                })
                ->take(10)
                ->get();

            return response()->json($data);
        }

        $data = Category::with([
            'categorymeta' => function ($query) {
                $query->where('meta_key', 'visibility');
            },
            'taxonomy' => function ($query) {
                $query->select('term_id', 'taxonomy');
            }
        ])
            ->whereNested(function ($query) use ($value) {
                $query->where('name', 'LIKE', '%' . $value . '%')
                    ->orWhere('slug', 'LIKE', '%' . $value . '%');
            })
            ->whereHas('taxonomy', function ($query) {
                $query->where('taxonomy', 'product_brand');
            })
            ->get();
        return response()->json($data);
    }
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
    public function brandProducts(Request $request, string $slugs)
    {
        $perPage = $request->query('perPage', 500);
        $sortBy = $request->query('sort', 'default');
        $page = $request->query('page', 1);

        $slugArray = explode(',', $slugs);
        $auth = false;
        $priceTier = '';
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->ID) {
                $auth = true;
                $priceTier = $user->price_tier ?? '';
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
                    ->whereHas('meta', function ($query) {
                        $query->where('meta_key', '_stock_status')
                            ->where('meta_value', 'instock');
                    })
                    ->whereHas('categories.taxonomies', function ($query) use ($slugArray) {
                        $query->whereIn('slug', $slugArray)
                            ->where('taxonomy', 'product_brand');
                    })
                    ->orderBy('post_date', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
            }
        } catch (\Throwable $th) {
            $auth = false;
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
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slugArray) {
                    $query->whereIn('slug', $slugArray)
                        ->where('taxonomy', 'product_brand');
                })
                ->orderBy('post_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }

        $products->getCollection()->transform(function ($product) use ($priceTier, $auth) {
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
            if (!$auth) {
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

        return response()->json($products);
    }

    public function menuCleanUp()
    {

        $brandUrls = DB::table('wp_custom_value_save')->pluck('brand_url');

        // Step 2: Process each brand URL
        foreach ($brandUrls as $brandUrl) {

            $slug = trim(parse_url($brandUrl, PHP_URL_PATH), 'brand/');

            // Check if there are products associated with this slug
            $hasProducts = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', ['_price', '_stock_status', '_sku', '_thumbnail_id']);
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
                ->select('ID', 'post_title', 'post_modified', 'post_name')
                ->where('post_type', 'product')
                ->whereHas('meta', function ($query) {
                    $query->where('meta_key', '_stock_status')
                        ->where('meta_value', 'instock');
                })
                ->whereHas('categories.taxonomies', function ($query) use ($slug) {
                    $query->where('slug', $slug)
                        ->where('taxonomy', 'product_brand');
                })
                ->exists(); // Check if any products exist

            if (!$hasProducts) {
                DB::table('wp_custom_value_save')
                    ->where('brand_url', $brandUrl)
                    ->delete();
            }
        }
        return true;
    }


    public function cartSync(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 200);
        }

        if ($user->ID != 1) {
            return response()->json(['message' => 'User are not allowed', 'status' => false], 200);
        }


        $chunkSize = 100;

        DB::table('wp_users')->orderBy('ID')->chunk($chunkSize, function ($users) {
            foreach ($users as $user) {
                $carts = DB::table('wp_usermeta')
                    ->where('user_id', $user->ID)
                    ->where('meta_key', 'LIKE', '%_woocommerce_persistent_cart_%')
                    ->get();

                foreach ($carts as $cart) {
                    $cart_data = unserialize($cart->meta_value);

                    if ($cart_data && isset($cart_data['cart'])) {
                        foreach ($cart_data['cart'] as $cart_item_key => $item) {
                            $product_id = $item['product_id'] ?? null;
                            $variation_id = $item['variation_id'] ?? null;
                            $quantity = $item['quantity'] ?? 0;

                            if ($product_id && !DB::table('wp_posts')->where('ID', $product_id)->exists()) {
                                echo "Product ID $product_id does not exist. Skipping...<br>";
                                continue;
                            }

                            if ($variation_id !== null && $variation_id !== '' && !DB::table('wp_posts')->where('ID', $variation_id)->exists()) {
                                echo "Variation ID $variation_id does not exist. Skipping...<br>";
                                continue;
                            }


                            if ($variation_id === 0) {
                                echo "Variation ID is 0. Skipping...<br>";
                                continue;
                            }


                            $cartItem = Cart::where('user_id', $user->ID)
                                ->where('product_id', $product_id)
                                ->where('variation_id', $variation_id)
                                ->first();

                            if ($cartItem) {

                                $cartItem->quantity += $quantity;
                                $cartItem->save();
                            } else {

                                Cart::create([
                                    'user_id' => $user->ID,
                                    'product_id' => $product_id,
                                    'variation_id' => $variation_id,
                                    'quantity' => $quantity,
                                ]);
                            }
                        }
                    }
                }

                echo $user->ID . ' user cart synced <br>';
            }
        });
    }
}
