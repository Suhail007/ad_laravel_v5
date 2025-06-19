<?php

namespace App\Http\Controllers\Multichannel;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

use function PHPUnit\Framework\isEmpty;

class ProductVariationSessionLock extends Controller
{
    public function index(Request $request)
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
                        'sessions_limit_data',
                        'sessions_limit_data_created_at',
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
                                    'sessions_limit_data',
                                    'sessions_limit_data_created_at',
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
            $query->where(function ($q) use ($searchTerm) {
                $q->where('post_title', 'LIKE', "%{$searchTerm}%")
                    ->orWhereHas('meta', function ($q) use ($searchTerm) {
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

    public function updateOrCreate(Request $request){
        $isAdmin = false;
        $adminId = null;
        $adminName = 'Admin';

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $adminId = $user->id;
            $adminName = $user->name ?? 'Admin';
            foreach ($user->capabilities as $key => $value) {
                if ($key == 'administrator') {
                    $isAdmin = true;
                }
            }
        } catch (\Throwable $th) {
        }

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Hey you are not Allowed']);
        }

        $validate = Validator::make($request->all(), [
            'quantities' => 'required|array',
            'quantities.*.value' => 'required|numeric',
            'quantities.*.post_id' => 'required|integer',
            'quantities.*.session_limit' => 'nullable|array',
            'quantities.*.session_limit.*.session_limt_id' => 'nullable|integer',
            'quantities.*.session_limit.*.limit_session_start' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.limit_session_end' => 'nullable|date_format:Y-m-d H:i:s',
            'quantities.*.session_limit.*.min_order_limit_per_user' => 'nullable|integer',
            'quantities.*.session_limit.*.max_order_limit_per_user' => 'nullable|integer',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validate->errors()->toArray()
            ]);
        }

        DB::beginTransaction();

        try {
            foreach ($request->quantities as $quantity) {
                $postId = $quantity['post_id'];
                $metaKey = 'sessions_limit_data';

                $meta = ProductMeta::where('post_id', $postId)->where('meta_key', $metaKey)->first();
                $existingSessions = $meta ? json_decode($meta->meta_value, true) ?? [] : [];
                $existingIds = array_column($existingSessions, 'session_limt_id');
                $maxId = $existingIds ? max($existingIds) : 0;

                $newSessions = $quantity['session_limit'] ?? [];

                foreach ($newSessions as $newSession) {

                    // $newStart = strtotime($newSession['limit_session_start'] ?? '');
                    // $newEnd = strtotime($newSession['limit_session_end'] ?? '');

                    $newStart = !empty($newSession['limit_session_start']) ? strtotime($newSession['limit_session_start']) : null;
                    $newEnd = !empty($newSession['limit_session_end']) ? strtotime($newSession['limit_session_end']) : null;


                    if ($newStart && $newEnd && $newStart > $newEnd) {
                        return response()->json(['status' => false, 'message' => 'Start date cannot be after end date']);
                    }

                    $currentTime = now()->format('Y-m-d H:i:s');
                    $logEntry = [
                        'userID' => $adminId,
                        'message' => "This rule " . (!empty($newSession['session_limt_id']) ? 'updated' : 'created') . " by {$adminName} at {$currentTime}",
                        'date' => $currentTime
                    ];

                    $matched = false;

                    // If ID provided, try to update
                    if (!empty($newSession['session_limt_id'])) {
                        foreach ($existingSessions as &$existingSession) {
                            if ($existingSession['session_limt_id'] == $newSession['session_limt_id']) {
                                $existingSession = array_merge($existingSession, $newSession);
                                $existingSession['log_list'][] = $logEntry;
                                $matched = true;
                                break;
                            }
                        }
                    } else {
                        // If ID not provided, match by exact session values
                        foreach ($existingSessions as &$existingSession) {
                            if (
                                $existingSession['limit_session_start'] === $newSession['limit_session_start'] &&
                                $existingSession['limit_session_end'] === $newSession['limit_session_end'] &&
                                $existingSession['max_order_limit_per_user'] === $newSession['max_order_limit_per_user'] &&
                                $existingSession['min_order_limit_per_user'] === ($newSession['min_order_limit_per_user'] ?? null) &&
                                $existingSession['isActive'] == $newSession['isActive'] &&
                                trim($existingSession['session_note']) === trim($newSession['session_note'])
                            ) {
                                $existingSession['log_list'][] = $logEntry;
                                $matched = true;
                                break;
                            }
                        }

                        // If not matched and does not overlap, assign new ID
                        if (!$matched) {
                            foreach ($existingSessions as $existingSession) {
                                // $existingStart = strtotime($existingSession['limit_session_start'] ?? '');
                                // $existingEnd = strtotime($existingSession['limit_session_end'] ?? '');
                                // if (
                                //     $newStart && $newEnd &&
                                //     $existingStart && $existingEnd &&
                                //     $newStart <= $existingEnd && $newEnd >= $existingStart
                                // ) {
                                //     $post = DB::table('wp_posts')->find($postId);
                                //     return response()->json([
                                //         'status' => false,
                                //         'message' => "Session for {$post->post_title} overlaps with existing session between {$existingSession['limit_session_start']} and {$existingSession['limit_session_end']}."
                                //     ]);
                                // }
                                $conflictsWithActive = $existingSession['isActive'] ?? false;
                                $existingStart = !empty($existingSession['limit_session_start']) ? strtotime($existingSession['limit_session_start']) : null;
                                $existingEnd = !empty($existingSession['limit_session_end']) ? strtotime($existingSession['limit_session_end']) : null;

                                // Check for overlap if newStart or newEnd is not given, but existing session is active
                                if (
                                    $conflictsWithActive && (
                                        ($newStart && $newEnd && $existingStart && $existingEnd &&
                                            $newStart <= $existingEnd && $newEnd >= $existingStart) ||
                                        (!$newStart && !$newEnd && $existingStart && $existingEnd && now()->between($existingStart, $existingEnd))
                                    )
                                ) {
                                    $post = DB::table('wp_posts')->find($postId);
                                    return response()->json([
                                        'status' => false,
                                        'message' => "Session for {$post->post_title} overlaps with active session between {$existingSession['limit_session_start']} and {$existingSession['limit_session_end']}."
                                    ]);
                                }

                            }

                            $maxId++;
                            $newSession['session_limt_id'] = $maxId;
                            $newSession['log_list'] = [$logEntry];
                            $existingSessions[] = $newSession;
                            $matched = true;
                        }
                    }
                }

                // Save updated max_quantity if provided
                if (!empty($quantity['type']) && isset($quantity['value'])) {
                    ProductMeta::updateOrCreate(
                        ['post_id' => $postId, 'meta_key' => $quantity['type']],
                        ['meta_value' => $quantity['value']]
                    );

                    ProductMeta::firstOrCreate(
                        ['post_id' => $postId, 'meta_key' => 'sessions_limit_data_created_at'],
                        ['meta_value' => now()->format('Y-m-d H:i:s')]
                    );

                    // update cart also
                    $limitValue = $quantity['value'] ?? 0;
                    $isLimit = $limitValue > 0;
                    $updateData = [
                        'isLimit' => $isLimit,
                        'max' => $isLimit ? $limitValue : null,
                    ];
                    $updatedRows = Cart::where('variation_id', $postId)->get();

                    if ($updatedRows->isEmpty()) {
                        $updatedRows = Cart::where('product_id', $postId)->get();
                    }
                    foreach ($updatedRows as $cartItem) {
                        $cartItem->isLimit = $updateData['isLimit'];
                        $cartItem->max = $updateData['max'];
                        if ($isLimit && $cartItem->qty > $limitValue) {
                            $cartItem->qty = $limitValue;
                        }
                        $cartItem->save();
                    }
                }

                // Save session_limit_data
                ProductMeta::updateOrCreate(
                    ['post_id' => $postId, 'meta_key' => $metaKey],
                    ['meta_value' => json_encode($existingSessions)]
                );
            }

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Quantities updated successfully.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }
    public function create(Request $request) {}
    public function show(Request $request) {}
    public function edit(Request $request) {}
    public function destroy(Request $request) {}
    public function getPurchaseLimitProductById(Request $request, $id)
    {
        $productId = $id;
        $logData = [];
        $sessionID = $request->query('sessionID', null);
        // Step 1: Get product or variation with meta + variations
        $product = Product::with(['meta', 'variations.varients'])->where('ID', $productId)
            ->orWhereHas('variations', function ($q) use ($productId) {
                $q->where('ID', $productId);
            })->first();

        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found']);
        }

        $ids = [];

        // Step 2: Check for sessions_limit_data in product meta
        $productMeta = collect($product->meta ?? []);
        $hasSessionLimit = $productMeta->where('meta_key', 'sessions_limit_data')->isNotEmpty();
        if ($hasSessionLimit) {
            $ids[] = $product->ID;
        }

        // Step 3: Check each variation for session limits
        foreach ($product->variations ?? [] as $variation) {
            $variationMeta = collect($variation->varients ?? []);
            if ($variationMeta->where('meta_key', 'sessions_limit_data')->isNotEmpty()) {
                $ids[] = $variation->ID;
            }
        }

        // Step 4: Fetch all product_limit_session records for matched IDs
        $records = DB::table('product_limit_session')
            ->whereIn('product_variation_id', $ids)
            ->when($sessionID, function ($query) use ($sessionID) {
                $query->where('session_id', $sessionID);
            })
            ->get();

        foreach ($records as $record) {
            $user = User::where('ID', $record->user_id)->first();

            if ($user) {
                $logData[] = [
                    'product_variation_id' => $record->product_variation_id,
                    'user_id' => $user->ID,
                    'name' => $user->user_login,
                    'email' => $user->user_email,
                    'capabilities' => $user->capabilities,
                    'account_no' => $user->account,
                    'order_count' => $record->order_count,
                    'session_id' => $record->session_id,
                    'blocked_attempts' => $record->blocked_attemps,
                    'blocked_time' => $record->blocked_attemp_time,
                    'log' => $record->log,
                    'last_updated' => $record->updated_at,
                ];
            }
        }

        return response()->json(['status' => true, 'logData' => $logData]);
    }
    public function getProductsWithActiveSession(Request $request)
    {
        $perPage = $request->query('perPage', 15);
        $sortBy = $request->query('sortBy', 'post_modified');
        $sortOrder = $request->query('sortOrder', 'desc');
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

        $now = now();
        $productIdsWithActiveSession = [];

        // Step 1: Fetch all sessions_limit_data entries
        $metaRecords = ProductMeta::where('meta_key', 'sessions_limit_data')->get();

        foreach ($metaRecords as $meta) {
            $sessions = json_decode($meta->meta_value, true);
            if (is_array($sessions)) {
                foreach ($sessions as $session) {
                    if (
                        isset($session['isActive']) && $session['isActive'] &&
                        isset($session['limit_session_start']) &&
                        isset($session['limit_session_end']) &&
                        $now->between(Carbon::parse($session['limit_session_start']), Carbon::parse($session['limit_session_end']))
                    ) {
                        $productIdsWithActiveSession[] = $meta->post_id;
                        break; // one active session is enough
                    }
                }
            }
        }

        if (empty($productIdsWithActiveSession)) {
            return response()->json(['status' => true, 'products' => []]);
        }

        // Step 2: Load matching products
        $products = Product::with([
            'meta' => function ($query) {
                $query->select('post_id', 'meta_key', 'meta_value')
                    ->whereIn('meta_key', [
                        '_price',
                        '_stock_status',
                        '_stock',
                        '_sku',
                        '_thumbnail_id',
                        '_product_image_gallery',
                        'max_quantity',
                        'min_quantity',
                        'sessions_limit_data',
                        'sessions_limit_data_created_at'
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
                                    '_sku',
                                    'max_quantity_var',
                                    'min_quantity_var',
                                    '_thumbnail_id',
                                    'sessions_limit_data',
                                    'sessions_limit_data_created_at'
                                ]);
                        }
                    ]);
            },
            'thumbnail'
        ])
            ->whereIn('ID', $productIdsWithActiveSession)
            ->where('post_type', 'product')
            ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status' => true,
            'products' => $products
        ]);
    }
    // public function getProductsWithInactiveSession(Request $request){
    //     $perPage = $request->query('perPage', 15);
    //     $sortBy = $request->query('sortBy', 'post_modified');
    //     $sortOrder = $request->query('sortOrder', 'desc');
    //     $page = $request->query('page', 1);

    //     $isAdmin = false;
    //     try {
    //         $user = JWTAuth::parseToken()->authenticate();
    //         $capabilities = $user->capabilities ?? [];
    //         $isAdmin = isset($capabilities['administrator']);
    //     } catch (\Throwable $th) {
    //     }

    //     if (!$isAdmin) {
    //         return response()->json(['status' => false, 'message' => 'Hey, you are not allowed']);
    //     }

    //     $now = now();
    //     $productIdsWithInactiveSession = [];

    //     $metaRecords = ProductMeta::where('meta_key', 'sessions_limit_data')->get();

    //     foreach ($metaRecords as $meta) {
    //         $sessions = json_decode($meta->meta_value, true);
    //         $hasActiveSession = false;

    //         if (is_array($sessions)) {
    //             foreach ($sessions as $session) {
    //                 if (
    //                     isset($session['isActive']) && $session['isActive']
    //                     // && isset($session['limit_session_start']) && isset($session['limit_session_end'])
    //                 ) {
    //                     // $start = Carbon::parse($session['limit_session_start']);
    //                     // $end = Carbon::parse($session['limit_session_end']);

    //                     // if ($now->between($start, $end)) {
    //                         $hasActiveSession = true;
    //                         break;
    //                     // }
    //                 }
    //             }

    //             if (!$hasActiveSession) {
    //                 $hasDeactivatedMax = ProductMeta::where('post_id', $meta->post_id)
    //                 ->where(function($query){
    //                     $query->where('meta_key', 'deactivated_max_quantity')
    //                     ->orWhere('meta_key', 'deactivated_max_quantity_var');
    //                 })
    //                 ->where('meta_value', '>', 0)
    //                 ->exists();

    //                 if($hasDeactivatedMax){
    //                     $productIdsWithInactiveSession[] = $meta->post_id;
    //                 } else {
    //                     $productIdsWithInactiveSession[] = $meta->post_id;
    //                 }
    //             }
    //         }
    //     }

    //     if (empty($productIdsWithInactiveSession)) {
    //         return response()->json(['status' => true, 'products' => []]);
    //     }

    //     $products = Product::with([
    //         'meta' => function ($query) {
    //             $query->select('post_id', 'meta_key', 'meta_value')
    //                 ->whereIn('meta_key', [
    //                     '_price',
    //                     '_stock_status',
    //                     '_stock',
    //                     '_sku',
    //                     '_thumbnail_id',
    //                     '_product_image_gallery',
    //                     'max_quantity',
    //                     'min_quantity',
    //                     'sessions_limit_data',
    //                     'sessions_limit_data_created_at',
    //                     'deactivated_max_quantity',
    //                 ]);
    //         },
    //         'variations' => function ($query) {
    //             $query->select('ID', 'post_parent', 'post_title', 'post_name')
    //                 ->with([
    //                     'varients' => function ($query) {
    //                         $query->select('post_id', 'meta_key', 'meta_value')
    //                             ->whereIn('meta_key', [
    //                                 '_price',
    //                                 '_stock_status',
    //                                 '_stock',
    //                                 '_sku',
    //                                 'max_quantity_var',
    //                                 'min_quantity_var',
    //                                 '_thumbnail_id',
    //                                 'sessions_limit_data',
    //                                 'sessions_limit_data_created_at',
    //                                 'deactivated_max_quantity_var'
    //                             ]);
    //                     }
    //                 ]);
    //         },
    //         'thumbnail'
    //     ])
    //         ->whereIn('ID', $productIdsWithInactiveSession)
    //         ->where('post_type', 'product')
    //         ->select('ID', 'post_title', 'post_modified', 'post_name', 'post_date')
    //         ->orderBy($sortBy, $sortOrder)
    //         ->paginate($perPage, ['*'], 'page', $page);

    //     return response()->json([
    //         'status' => true,
    //         'products' => $products
    //     ]);
    // }

    public function getProductsWithInactiveSession(Request $request)
{
    $perPage = $request->query('perPage', 15);
    $sortBy = $request->query('sortBy', 'post_modified');
    $sortOrder = $request->query('sortOrder', 'desc');
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

    $productIds = [];

    $metaRecords = ProductMeta::where('meta_key', 'sessions_limit_data')->get();
    $now = now();

    foreach ($metaRecords as $meta) {
        $postId = $meta->post_id;
        $hasActiveSession = false;

        $sessions = json_decode($meta->meta_value, true);
        if (is_array($sessions)) {
            foreach ($sessions as $session) {
                if (isset($session['isActive']) && $session['isActive']) {
                    $hasActiveSession = true;
                    break;
                }
            }
        }

        // Check if product has deactivated max qty at product or variation level
        $hasDeactivatedQty = ProductMeta::where('post_id', $postId)
            ->where(function ($query) {
                $query->where('meta_key', 'deactivated_max_quantity')
                    ->orWhere('meta_key', 'deactivated_max_quantity_var');
            })
            ->where('meta_value', '>', 0)
            ->exists();

        if (!$hasActiveSession || $hasDeactivatedQty) {
            $productIds[] = $postId;
        }
    }

    // Include any products with deactivated max qty but NO session meta at all
    $productsWithDeactivatedOnly = ProductMeta::whereIn('meta_key', ['deactivated_max_quantity', 'deactivated_max_quantity_var'])
        // ->where('meta_value', '>', 0)
        ->pluck('post_id')
        ->toArray();

    // Merge both arrays and ensure uniqueness
    $productIds = array_unique(array_merge($productIds, $productsWithDeactivatedOnly));

    if (empty($productIds)) {
        return response()->json(['status' => true, 'products' => []]);
    }

    $products = Product::with([
        'meta' => function ($query) {
            $query->select('post_id', 'meta_key', 'meta_value')
                ->whereIn('meta_key', [
                    '_price',
                    '_stock_status',
                    '_stock',
                    '_sku',
                    '_thumbnail_id',
                    '_product_image_gallery',
                    'max_quantity',
                    'min_quantity',
                    'sessions_limit_data',
                    'sessions_limit_data_created_at',
                    'deactivated_max_quantity',
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
                                '_sku',
                                'max_quantity_var',
                                'min_quantity_var',
                                '_thumbnail_id',
                                'sessions_limit_data',
                                'sessions_limit_data_created_at',
                                'deactivated_max_quantity_var'
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

    
    public function deactivateAllSessionsForProduct(Request $request, $id){
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

        $product = Product::with(['meta', 'variations.varients'])->where('ID', $id)->first();
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found']);
        }

        $productMeta = $product->meta->keyBy('meta_key');

        if ($productMeta->has('sessions_limit_data')) {
            $sessions = json_decode($productMeta['sessions_limit_data']->meta_value, true);
            if (is_array($sessions)) {
                // foreach ($sessions as &$session) {
                //     $session['isActive'] = false;
                // }

                $productMeta['sessions_limit_data']->meta_value = json_encode($sessions);
                $productMeta['sessions_limit_data']->save();
            }
        }

        if ($productMeta->has('max_quantity')) {
            $oldMax = $productMeta['max_quantity']->meta_value;
            ProductMeta::updateOrCreate(
                ['post_id' => $product->ID, 'meta_key' => 'deactivated_max_quantity'],
                ['meta_value' => $oldMax]
            );
            $productMeta['max_quantity']->meta_value = null;
            $productMeta['max_quantity']->save();
        }

        foreach ($product->variations as $variation) {
            $varMeta = $variation->varients->keyBy('meta_key');

            // Handle sessions_limit_data
            if ($varMeta->has('sessions_limit_data')) {
                $sessions = json_decode($varMeta['sessions_limit_data']->meta_value, true);
                if (is_array($sessions)) {
                    // foreach ($sessions as &$session) {
                    //     $session['isActive'] = false;
                    // }

                    $varMeta['sessions_limit_data']->meta_value = json_encode($sessions);
                    $varMeta['sessions_limit_data']->save();

                }
            }

            if ($varMeta->has('max_quantity_var')) {
                $oldMax = $varMeta['max_quantity_var']->meta_value;
                ProductMeta::updateOrCreate(
                    ['post_id' => $variation->ID, 'meta_key' => 'deactivated_max_quantity_var'],
                    ['meta_value' => $oldMax]
                );
                $varMeta['max_quantity_var']->meta_value = null;
                $varMeta['max_quantity_var']->save();
            }
        }

        return response()->json(['status' => true, 'message' => 'Sessions deactivated and quantity limits archived successfully']);
    }
    public function activateAllSessionsForProduct(Request $request, $id){
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
        $product = Product::with(['meta', 'variations.varients'])->where('ID', $id)->first();
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found']);
        }

        $productMeta = $product->meta->keyBy('meta_key');
        if ($productMeta->has('sessions_limit_data')) {
            $sessions = json_decode($productMeta['sessions_limit_data']->meta_value, true);
            if (is_array($sessions)) {
                // foreach ($sessions as &$session) {
                //     $session['isActive'] = true;
                // }

                $productMeta['sessions_limit_data']->meta_value = json_encode($sessions);
                $productMeta['sessions_limit_data']->save();

                // foreach ($sessions as $s) {
                //     DB::table('product_limit_session')->updateOrInsert(
                //         ['session_id' => $s['session_id'] ?? uniqid(), 'product_variation_id' => $product->ID],
                //         [
                //             'is_active' => true,
                //             'activated_at' => now(),
                //             'source' => 'product'
                //         ]
                //     );
                // }
            }
        }
        if ($productMeta->has('deactivated_max_quantity')) {
            $oldValue = $productMeta['deactivated_max_quantity']->meta_value;
            ProductMeta::updateOrCreate(
                ['post_id' => $product->ID, 'meta_key' => 'max_quantity'],
                ['meta_value' => $oldValue]
            );
            $productMeta['deactivated_max_quantity']->delete();
        }
        foreach ($product->variations as $variation) {
            $varMeta = $variation->varients->keyBy('meta_key');
            if ($varMeta->has('sessions_limit_data')) {
                $sessions = json_decode($varMeta['sessions_limit_data']->meta_value, true);
                if (is_array($sessions)) {
                    // foreach ($sessions as &$session) {
                    //     $session['isActive'] = true;
                    // }

                    $varMeta['sessions_limit_data']->meta_value = json_encode($sessions);
                    $varMeta['sessions_limit_data']->save();

                    // foreach ($sessions as $s) {
                    //     DB::table('product_limit_session')->updateOrInsert(
                    //         ['session_id' => $s['session_id'] ?? uniqid(), 'product_variation_id' => $variation->ID],
                    //         [
                    //             'is_active' => true,
                    //             'activated_at' => now(),
                    //             'source' => 'variation'
                    //         ]
                    //     );
                    // }
                }
            }
            if ($varMeta->has('deactivated_max_quantity_var')) {
                $oldValue = $varMeta['deactivated_max_quantity_var']->meta_value;
                ProductMeta::updateOrCreate(
                    ['post_id' => $variation->ID, 'meta_key' => 'max_quantity_var'],
                    ['meta_value' => $oldValue]
                );
                $varMeta['deactivated_max_quantity_var']->delete();
            }
        }
        return response()->json(['status' => true, 'message' => 'Sessions activated and quantity limits restored successfully']);
    }
}
