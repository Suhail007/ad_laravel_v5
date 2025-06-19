<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
{
    public function getProductVariation(Request $request, $id = null)
    {
        $isAdmin = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $data = $user->capabilities;
            foreach ($data as $key => $value) {
                if ($key == 'administrator') {
                    $isAdmin = true;
                }
            }
        } catch (\Throwable $th) {
        }

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey you are not Allowed']);
        }
        $priceTier = '_price';
        $product = Product::with([
            'meta' => function ($query) use ($priceTier) {
                $query->select('meta_id', 'post_id', 'meta_key', 'meta_value')
                    ->whereIn('meta_key', ['_price', '_stock', '_stock_status', '_sku', '_thumbnail_id', '_product_image_gallery', 'min_quantity', 'max_quantity', $priceTier, 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user']);
            },
            'variations' => function ($query) use ($priceTier) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) use ($priceTier) {
                            $query->select('meta_id', 'post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', ['_price', '_stock_status', '_stock', '_sku', '_thumbnail_id', $priceTier, 'max_quantity_var', 'min_quantity_var', 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user'])
                                // ->orWhere(function ($query) {
                                //     $query->where('meta_key', 'like', 'attribute_%'); // slow down 
                                // })
                            ;
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->where('post_type', 'product')
            ->where('ID', $id)
            ->first();
        return response()->json(['status' => true, 'data' => $product]);
    }

    public function updateQuantity(Request $request)
    {
        // $isAdmin = false;
        // try {
        //     $user = JWTAuth::parseToken()->authenticate();
        //     $data = $user->capabilities;
        //     foreach ($data as $key => $value) {
        //         if ($key == 'administrator') {
        //             $isAdmin = true;
        //         }
        //     }
        // } catch (\Throwable $th) {
        // }

        // if (!$isAdmin) {
        //     return response()->json(['status' => false, 'message' => 'Hey you are not Allowed']);
        // }
        $validate = Validator::make($request->all(), [
            'quantities' => 'required|array',
            'quantities.*.value' => 'required|numeric',
            'quantities.*.post_id' => 'required|integer',
            'quantities.*.session_limit' => 'required|array',
            'quantities.*.session_limit.*.session_limt_id' => 'nullable|integer',
            'quantities.*.session_limit.*.limit_session_start' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.limit_session_end' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.min_order_limit_per_user' => 'nullable|integer',
            'quantities.*.session_limit.*.max_order_limit_per_user' => 'nullable|integer',
            

        ]);
        if ($validate->fails()) {
            $errors = $validate->errors()->toArray();
            $formattedErrors = [];
            foreach ($errors as $key => $errorMessages) {
                $formattedErrors[] = [
                    'field' => $key,
                    'messages' => $errorMessages
                ];
            }
            return response()->json([
                'status' => false,
                'message' => $formattedErrors
            ]);
        }
        $data = $request->all();

        foreach ($data['quantities'] as $quantity) {
            $postId = $quantity['post_id'];
            // $metaKey = $quantity['type'] . '_sessions_limit_data';
            $metaKey = 'sessions_limit_data';
            $existingMeta = ProductMeta::where('post_id', $postId)->where('meta_key', $metaKey)->first();
            $existingSessions = [];
            if ($existingMeta) {
                $existingSessions = json_decode($existingMeta->meta_value, true) ?? [];
            }
            $existingIds = array_column($existingSessions, 'session_limt_id');
            $maxId = $existingIds ? max(array_filter($existingIds)) : 0;
            if (!empty($quantity['session_limit']) && is_array($quantity['session_limit'])) {
                foreach ($quantity['session_limit'] as $newSession) {
                    $matched = false;
                    if (empty($newSession['session_limt_id'])) {
                        $maxId += 1;
                        $newSession['session_limt_id'] = $maxId;
                    }
                    foreach ($existingSessions as &$existingSession) {
                        if (
                            isset($existingSession['session_limt_id']) &&
                            $existingSession['session_limt_id'] == $newSession['session_limt_id']
                        ) {
                            $existingSession = array_merge($existingSession, $newSession);
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $existingSessions[] = $newSession;
                    }
                }
            }
            ProductMeta::updateOrCreate(
                [
                    'post_id' => $postId,
                    'meta_key' => $metaKey,
                ],
                [
                    'meta_value' => json_encode($existingSessions),
                ]
            );
        }

        return response()->json(['status' => true, 'message' => 'Quantities updated successfully.']);
    }
    
    public function getPurchaseLimitProduct(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sortBy', 'post_modified'); // default sort field
        $sortOrder = $request->query('sortOrder', 'desc');    // default sort order
        $page = $request->query('page', 1);

        $isAdmin = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];

            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {
        }

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey, you are not allowed']);
        }
        $directLimitProductIds = ProductMeta::whereIn('meta_key', ['max_quantity', 'min_quantity'])
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('post_id')
            ->toArray();

        $variationIdsWithLimits = ProductMeta::whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('post_id')
            ->toArray();

        $parentProductIdsFromVariations = Product::whereIn('ID', $variationIdsWithLimits)
            ->pluck('post_parent')
            ->toArray();
        $allRelevantProductIds = array_unique(array_merge($directLimitProductIds, $parentProductIdsFromVariations));

        $query = Product::with([
            'meta' => function ($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                    ->whereIn('meta_key', [
                        '_price',
                        '_stock_status',
                        '_stock',
                        'max_quantity',
                        'min_quantity',
                        '_sku',
                        '_thumbnail_id',
                        '_product_image_gallery',
                        'limit_session_start',
                        'limit_session_end',
                        'min_order_limit_per_user',
                        'max_order_limit_per_user',
                    ]);
            },
            'variations' => function ($query) {
                $query->select('ID', 'post_parent', 'post_title', 'post_name')
                    ->with([
                        'varients' => function ($query) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', [
                                    '_price',
                                    '_stock_status',
                                    '_stock',
                                    'max_quantity_var',
                                    'min_quantity_var',
                                    '_sku',
                                    '_thumbnail_id',
                                    'limit_session_start',
                                    'limit_session_end',
                                    'min_order_limit_per_user',
                                    'max_order_limit_per_user',
                                ]);
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->whereIn('ID', $allRelevantProductIds)
            ->where('post_type', 'product')
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date');

        // Add search functionality
        if (!empty($searchTerm)) {
            $query->where(function($q) use ($searchTerm) {
                $q->where('post_title', 'LIKE', "%{$searchTerm}%")
                  ->orWhereHas('meta', function($q) use ($searchTerm) {
                      $q->where('meta_key', '_sku')
                        ->where('meta_value', 'LIKE', "%{$searchTerm}%");
                  });
            });
        }

        $products = $query->orderBy($sortBy, $sortOrder)
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status' => true,
            'products' => $products
        ]);
    }

    public function removePurchaseLimit($id)
    {
        $isAdmin = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {
        }

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'You are not allowed']);
        }
        $productKeys = ['max_quantity', 'min_quantity', 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user'];
        $variantKeys = ['max_quantity_var', 'min_quantity_var', 'limit_session_start', 'limit_session_end', 'min_order_limit_per_user', 'max_order_limit_per_user'];
        $productDeleted = ProductMeta::where('post_id', $id)
            ->whereIn('meta_key', $productKeys)
            ->delete();

        $variantIds = Product::where('post_parent', $id)
            ->where('post_type', 'product_variation')
            ->pluck('ID')
            ->toArray();
        if ($variantIds) {
            $variantDeleted = ProductMeta::whereIn('post_id', $variantIds)
                ->whereIn('meta_key', $variantKeys)
                ->delete();
            return response()->json(['status' => true, 'message' => 'Purchase limits removed of variations']);
        } else {
            return response()->json(['status' => true, 'message' => 'Purchase limits removed ']);
        }
    }

    public function searchPurchaseLimitProduct(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');
        $perPage = $request->query('perPage', 15);
        $page = $request->query('page', 1);
        $sortBy = $request->query('sortBy', 'post_modified');
        $sortOrder = $request->query('sortOrder', 'desc');
        $isAdmin = false;
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $capabilities = $user->capabilities ?? [];
            $isAdmin = isset($capabilities['administrator']);
        } catch (\Throwable $th) {
        }
        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'You are not allowed']);
        }
        $regexPattern = '';
        if (!empty($searchTerm)) {
            $searchWords = preg_split('/\s+/', $searchTerm);
            $regexPattern = implode('.*', array_map(function ($word) {
                return "(?=.*" . preg_quote($word) . ")";
            }, $searchWords));
        }
        if (!empty($searchTerm)) {
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', [
                            '_price',
                            '_stock_status',
                            '_stock',
                            'max_quantity',
                            'min_quantity',
                            '_sku',
                            '_thumbnail_id',
                            '_product_image_gallery',
                        ]);
                },
                'variations' => function ($query) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with(['varients' => function ($query) {
                            $query->select('post_id', 'meta_key', 'meta_value')
                                ->whereIn('meta_key', [
                                    '_price',
                                    '_stock_status',
                                    '_stock',
                                    'max_quantity_var',
                                    'min_quantity_var',
                                    '_sku',
                                    '_thumbnail_id'
                                ]);
                        }]);
                },
                'thumbnail'
            ])
                ->where('post_type', 'product')
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->where(function ($query) {
                    $query->whereHas('meta', function ($q) {
                        $q->whereIn('meta_key', ['max_quantity', 'min_quantity'])
                            ->where('meta_value', '!=', '');
                    })
                        ->orWhereHas('variations.varients', function ($q) {
                            $q->whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
                                ->where('meta_value', '!=', '');
                        });
                })
                ->when($searchTerm, function ($query) use ($regexPattern) {
                    $query->where(function ($q) use ($regexPattern) {
                        $q->where('post_title', 'REGEXP', $regexPattern)
                            ->orWhereHas('meta', function ($metaQuery) use ($regexPattern) {
                                $metaQuery->where('meta_key', '_sku')
                                    ->where('meta_value', 'REGEXP', $regexPattern);
                            });
                    });
                })
                ->orderBy($sortBy, $sortOrder)
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'products' => $products
            ]);
        } else {
            $priceTier = '_price';
            $productIds = ProductMeta::whereIn('meta_key', ['max_quantity', 'min_quantity'])
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '')
                ->pluck('post_id')
                ->merge(
                    ProductMeta::whereIn('meta_key', ['max_quantity_var', 'min_quantity_var'])
                        ->whereNotNull('meta_value')
                        ->where('meta_value', '!=', '')
                        ->pluck('post_id')
                )
                ->unique()
                ->toArray();
            $products = Product::with([
                'meta' => function ($query) {
                    $query->select('post_id', 'meta_key', 'meta_value')
                        ->whereIn('meta_key', [
                            '_price',
                            '_stock_status',
                            '_stock',
                            'max_quantity',
                            'min_quantity',
                            '_sku',
                            '_thumbnail_id',
                            '_product_image_gallery',
                            'limit_session_start',
                            'limit_session_end',
                            'min_order_limit_per_user',
                            'max_order_limit_per_user',
                        ]);
                },
                'variations' => function ($query) {
                    $query->select('ID', 'post_parent', 'post_title', 'post_name')
                        ->with([
                            'varients' => function ($query) {
                                $query->select('post_id', 'meta_key', 'meta_value')
                                    ->whereIn('meta_key', [
                                        '_price',
                                        '_stock_status',
                                        '_stock',
                                        'max_quantity_var',
                                        'min_quantity_var',
                                        '_sku',
                                        '_thumbnail_id',
                                        'limit_session_start',
                                        'limit_session_end',
                                        'min_order_limit_per_user',
                                        'max_order_limit_per_user',
                                    ]);
                            }
                        ]);
                },
                'thumbnail'
            ])
                ->whereIn('ID', $productIds)
                ->where('post_type', 'product')
                ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
                ->orderBy($sortBy, $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);
            return response()->json([
                'status' => true,
                'products' => $products
            ]);
        }
    }
}
