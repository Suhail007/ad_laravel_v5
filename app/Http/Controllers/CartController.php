<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAddToCartRequest;
use App\Http\Requests\UpdateCartQuantityRequest;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\MMTax;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Product;
use App\Models\ProductMeta;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CartController extends Controller
{
    public function couponProductID()
    {
        //hardcoded coupon product's variation id's 
        return [206835, 206836];
    }
    private function cartTotal($cartItems, $priceTier)
    {
        $total = 0;
        $taxID = [];
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;

            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $taxID = ProductMeta::where('post_id', $variation->ID)->where('meta_key', 'mm_indirect_tax_type')->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $taxID = ProductMeta::where('post_id', $product->ID)->where('meta_key', 'mm_indirect_tax_type')->value('meta_value');
            }
            if ($wholesalePrice == "") {
                $wholesalePrice = 0;
            } else if ($wholesalePrice == " ") {
                $wholesalePrice = 0;
            }
            $total += round($wholesalePrice * $cartItem->quantity, 2);
        }

        return [round($total, 2), $taxID];
    }


    protected function reduceStock($cartItem)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $cartItem->quantity);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $cartItem->quantity);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    protected function adjustStock($cartItem, $oldQuantity, $newQuantity)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;
        $quantityChange = $newQuantity - $oldQuantity;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantityChange);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantityChange);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    protected function increaseStock($cartItem)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = $stockLevel + $cartItem->quantity;
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = $stockLevel + $cartItem->quantity;
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }
    protected function reduceStockByQuantity($cartItem, $quantity)
    {
        $product = $cartItem->product;
        $variation = $cartItem->variation;

        if ($variation) {
            $stockLevel = ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantity);
            ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        } else {
            $stockLevel = ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->value('meta_value');

            $newStockLevel = max(0, $stockLevel - $quantity);
            ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', '_stock')
                ->update(['meta_value' => $newStockLevel]);
        }
    }

    public function tax(Request $request)
    {
        $tax = MMTax::get();
        return response()->json($tax);
    }

    private function cartItemCount($cartItems)
    {
        $totalCount = 0;
        foreach ($cartItems as $cartItem) {
            $totalCount += $cartItem->quantity;
        }
        return $totalCount;
    }

    /**
     * Check if user has reached their order limit for a product
     *
     * @param int $productId
     * @param int|null $variationId
     * @param int $userId
     * @param Carbon $currentDateTime
     * @return array Returns true if user is allowed to order, false if limit reached
     */
    private function checkProductLimit($productId, $variationId, $userId, $currentDateTime, $maxQty = 0, $cartQty = 1){
        $postId = $variationId ?? $productId;

        $currentTime = $currentDateTime instanceof Carbon
            ? $currentDateTime
            : Carbon::parse($currentDateTime);

        $sessionMeta = ProductMeta::where('post_id', $postId)
            ->where('meta_key', 'sessions_limit_data')
            ->first();

        if (!$sessionMeta) {
            return ['status' => true, 'canAdd' => true, 'allowedQty' => $maxQty];
        }

        $sessions = json_decode($sessionMeta->meta_value, true) ?? [];
        if (empty($sessions)) {
            return ['status' => true, 'canAdd' => true, 'allowedQty' => $maxQty];
        }

        foreach ($sessions as $session) {
            if (empty($session['isActive'])) continue;

            $startTime = Carbon::parse($session['limit_session_start'] ?? '2000-01-01 00:00:00');
            $endTime = Carbon::parse($session['limit_session_end'] ?? '2099-12-31 23:59:59');

            if ($currentTime->between($startTime, $endTime)) {
                $sessionId = $session['session_limt_id'] ?? null;
                $maxLimit = (int) ($session['max_order_limit_per_user'] ?? 0);

                if ($maxLimit === 0 && $maxQty === 0) {
                    return ['status' => true, 'canAdd' => true, 'allowedQty' => $cartQty];
                }

                $limitRecord = DB::table('product_limit_session')
                    ->where('product_variation_id', $postId)
                    ->where('user_id', $userId)
                    ->where('session_id', $sessionId)
                    ->first();

                $orderCount = $limitRecord->order_count ?? 0;
                $limitCount = $limitRecord->limit_count ?? 0;

                $remainingQty = $maxQty > 0 ? max(0, $maxQty - $limitCount) : $cartQty;
                $remainingOrders = $maxLimit > 0 ? max(0, $maxLimit - $orderCount) : 1;

                // Determine if adding current cartQty would exceed allowed qty
                $incomingQty = $cartQty ?? 1;
                $willExceedQty = $maxQty > 0 && ($limitCount + $incomingQty) > $maxQty;
                $willExceedOrders = $maxLimit > 0 && $orderCount >= $maxLimit;

                if ($willExceedQty || $willExceedOrders) {
                    $blockTime = now();
                    $newAttempt = ($limitRecord->blocked_attemps ?? 0) + 1;
                    $existingLog = $limitRecord->log ?? '';
                    $logMessage = "Blocked at {$blockTime->format('Y-m-d H:i:s')} (limit: $maxLimit orders / $maxQty qty, used: $orderCount orders / $limitCount qty)\n";

                    DB::table('product_limit_session')
                        ->updateOrInsert(
                            [
                                'product_variation_id' => $postId,
                                'user_id' => $userId,
                                'session_id' => $sessionId,
                            ],
                            [
                                'blocked_attemps' => $newAttempt,
                                'blocked_attemp_time' => $blockTime,
                                'log' => $existingLog . $logMessage,
                                'updated_at' => $blockTime,
                            ]
                        );

                    return [
                        'status' => false,
                        'canAdd' => false,
                        'allowedQty' => $remainingQty,
                        'remainingOrders' => $remainingOrders,
                    ];
                }

                // Valid
                return [
                    'status' => true,
                    'canAdd' => true,
                    'allowedQty' => $remainingQty,
                    'remainingOrders' => $remainingOrders,
                ];
            }
        }

        return ['status' => true, 'canAdd' => true, 'allowedQty' => $maxQty];
    }




    public function bulkAddToCart(Request $request)
    {

        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;

        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $product_id = $request->product_id;
        $variations = $request->variations;

        $cartItems = [];
        $newMsgShow = false;
        $currentDateTime = $request->input('currentDateTime') ?? now(); // take from request eg. 2025-05-28 10:00:00
        $count = 0;
        foreach ($variations as $variation) {
            $cartItem = Cart::where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->where('variation_id', $variation['variation_id'])
                ->first();

            if ($cartItem) {
                $newQty = $variation['quantity'];
                $oldQty = $cartItem->quantity;// new 
                $stockLevel = ProductMeta::where('post_id', $variation['variation_id'])
                    ->where('meta_key', '_stock')
                    ->value('meta_value');
                $cartItem->quantity += $newQty;
                //check if stock is enough
                if($stockLevel < $newQty + $oldQty && !$isFreeze){
                    $cartItem->quantity = $stockLevel;
                }
                //cart limit
                if ($cartItem->isLimit) {

                    // check if customer have reached the limit
                    $limitCheck = $this->checkProductLimit($product_id, $variation['variation_id'], $user_id, $currentDateTime, $cartItem->max, $cartItem->quantity);
                    if ($limitCheck['status'] == false) {
                        if($limitCheck['allowedQty'] > 0){
                            $cartItem->quantity = $limitCheck['allowedQty'];
                            $cartItem->save();
                            $count++;
                        } else {
                            $count++;
                            continue;
                        }
                        // return response()->json([
                        //     'status' => false,
                        //     'username' => $user->user_login,
                        //     'message' =>"Customer quota full, you've reached the order limit for this product.",
                        //     'time' => now()->toDateTimeString(),
                        //     'cart_count' => 0,
                        //     'cart_items' => [],
                        // ], 200);
                    }

                    if ($cartItem->quantity < $cartItem->min) {
                        $count++;
                        $reduceQTY = abs($oldQty - $cartItem->min);
                        $cartItem->quantity = $cartItem->min;
                        $cartItem->save();
                        if ($isFreeze) {
                            $this->reduceStockByQuantity($cartItem, $reduceQTY);
                        }
                    }
                    if ($cartItem->quantity > $cartItem->max) {
                        $count++;
                        $reduceQTY = abs($oldQty - $cartItem->max);
                        $cartItem->quantity = $cartItem->max;
                        $cartItem->save();
                        if ($isFreeze) {
                            $this->reduceStockByQuantity($cartItem, $reduceQTY);
                        }
                    }
                } else {
                    $cartItem->save();
                    if ($isFreeze) {
                        $this->reduceStockByQuantity($cartItem, $newQty);
                    }
                }
            } else {
                $newQty = $variation['quantity'];
                if ($variation['isLimit']) {
                    $limitCheck = $this->checkProductLimit($product_id, $variation['variation_id'], $user_id, $currentDateTime, $variation['max'], $newQty);
                    if ($limitCheck['status'] == false) {
                        if($limitCheck['allowedQty'] > 0){
                            $newQty = $limitCheck['allowedQty'];
                            $count++;
                        } else {
                            $count++;
                            continue;
                        }

                        // return response()->json([
                        //     'status' => false,
                        //     'username' => $user->user_login,
                        //     'message' =>"Customer quota full, you've reached the order limit for this product.",
                        //     'time' => now()->toDateTimeString(),
                        //     'cart_count' => 0,
                        //     'cart_items' => [],
                        // ], 200);
                    }

                    if ($variation['quantity'] < $variation['min']) {
                        
                        $newQty = $variation['min'];
                        $count++;
                    }

                    if ($variation['quantity'] > $variation['max']) {
                        $newQty = $variation['max'];
                        $count++;
                    }
                    
                    
                }
                try {
                    if (in_array($variation['variation_id'], $this->couponProductID())) {
                        $limitCouponID = $product_id;
                        $limitUserEmail = $user->user_email;
                        $isApplicable = UserCoupon::where('qrDetail', 'GiftProduct')->where('email', $limitUserEmail)->first();
                        $limitCouponLable = 'GiftProduct' ?? 'NONAME';
                        $limitCouponRuleTitle = "Free AD Gift";
                        if ($isApplicable) {
                            $newMsg = "Opps! It seems like you already claimed Gift";
                            return response()->json([
                                'status' => false,
                                'username' => $user->user_login,
                                'message' => $newMsg,
                                // 'data' => $userIp,
                                'time' => now()->toDateTimeString(),
                                'cart_count' => 0,
                                'cart_items' => [],
                            ], 200);
                        } else {
                            UserCoupon::create([
                                'couponName' => $limitCouponRuleTitle,
                                'qrDetail' => $limitCouponLable,
                                'discountRuleId' => $limitCouponID,
                                'email' => $limitUserEmail,
                                'canUse' => false,
                                'meta' => null
                            ]);
                        }
                    }
                } catch (\Throwable $th) {
                }
                $cartItem = Cart::create([
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation['variation_id'],
                    'quantity' => $newQty,
                    'min' => $variation['min'] ?? null,
                    'max' => $variation['max'] ?? null,
                    'isLimit' => $variation['isLimit'] ?? 0,
                ]);

                if ($isFreeze) {
                    $this->reduceStock($cartItem);
                }
            }


            $cartItems[] = $cartItem;
        }

        $cartItems = Cart::where('user_id', $user_id)->get();

        $userIp = $request->ip();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $userIp,
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $priceTier = $user->price_tier;
        $cartData = [];
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }

        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount= 0;
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;
            $itemCount++;

            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            }

            $stockLevel = 0;
            $stockStatus = 'outofstock';
            if ($variation) {
                $stockLevel = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            } else {
                $stockLevel = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            }

            if ($stockStatus == 'instock') {
                $stockStatus = 'instock';
            } else {
                $stockStatus = 'outofstock';
            }

            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;
            $categoryIds = $product->categories->pluck('term_id')->toArray();

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $productSlug,
                'product_price' => $wholesalePrice,
                'product_image' => $product->thumbnail_url,
                'stock' => $stockLevel,
                'stock_status' => $stockStatus,
                'quantity' => $cartItem->quantity,
                'min' => $cartItem->min ?? null,
                'max' => $cartItem->max ?? null,
                'isLimit' => $cartItem->isLimit ?? 0,
                'variation_id' => $variation ? $variation->ID : null,
                'variation' => $variationAttributes,
                'taxonomies' => $categoryIds
            ];
        }
        $message = ($count > 0) ? $count . " items are not added due to purchase Limit" : 'Products added to cart';
        return response()->json([
            'status' => true,
            'success' => $message,
            'data' => $userIp,
            'cart' => $cartData,
            'cart_total' => $total,
            'itemCount' => $itemCount,
            // 'pagination' => [
            //     'total' => $cartItems->total(),
            //     'per_page' => $cartItems->perPage(),
            //     'current_page' => $cartItems->currentPage(),
            //     'last_page' => $cartItems->lastPage(),
            //     'next_page_url' => $cartItems->nextPageUrl(),
            //     'prev_page_url' => $cartItems->previousPageUrl(),
            // ]
        ], 200);
    }



    public function bulkUpdateCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;
        $items = $request->items;

        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $cartItems = [];
        $count = 0;
        $currentDateTime = $request->input('currentDateTime') ?? now(); // take from request eg. 2025-05-28 10:00:00
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $variation_id = $item['variation_id'];
            $quantity = $item['quantity'];
            $min = $item['min'];
            $max = $item['max'];
            $isLimit = $item['isLimit'];

            $cartItem = Cart::where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->where('variation_id', $variation_id)
                ->first();

            if ($cartItem) {
                $oldQuantity = $cartItem->quantity;

                $cartItem->quantity = $quantity;
                //cart limit
                if ($cartItem->isLimit) {

                    // check if customer have reached the limit
                    $limitCheck = $this->checkProductLimit($product_id, $variation_id, $user_id, $currentDateTime, $cartItem->max , $cartItem->quantity);
                    if ($limitCheck['status'] == false) {
                        if($limitCheck['allowedQty'] > 0){
                            $cartItem->quantity = $limitCheck['allowedQty'];
                            $count++;
                        } else {
                            $count++;
                            continue;
                        }
                        // return response()->json([
                        //     'status' => false,
                        //     'username' => $user->user_login,
                        //     'message' =>"Customer quota full, you've reached the order limit for this product.",
                        //     'time' => now()->toDateTimeString(),
                        //     'cart_count' => 0,
                        //     'cart_items' => [],
                        // ], 200);
                    }

                    if ($cartItem->quantity < $cartItem->min) {
                        $count++;
                        $reduceQTY = abs($oldQuantity - $cartItem->min);
                        $cartItem->quantity = $cartItem->min;
                        $cartItem->save();
                        if ($isFreeze) {
                            $this->adjustStock($cartItem, $oldQuantity, $reduceQTY);
                        }
                    }
                    if ($cartItem->quantity > $cartItem->max) {
                        $count++;
                        $reduceQTY = abs($oldQuantity - $cartItem->max);
                        $cartItem->quantity = $cartItem->max;
                        $cartItem->save();
                        if ($isFreeze) {
                            $this->adjustStock($cartItem, $oldQuantity, $reduceQTY);
                        }
                    }
                } else {
                    $cartItem->save();
                    if ($isFreeze) {
                        $this->adjustStock($cartItem, $oldQuantity, $quantity);
                    }
                }
            } else {
                if ($isLimit) {
                    // check if customer have reached the limit
                    $limitCheck = $this->checkProductLimit($product_id, $variation_id, $user_id, $currentDateTime, $max, $quantity);
                    if ($limitCheck['status'] == false) {
                        if($limitCheck['allowedQty'] > 0){
                            $quantity = $limitCheck['allowedQty'];
                            $count++;
                        } else {
                            $count++;
                            continue;
                        }
                        // return response()->json([
                        //     'status' => false,
                        //     'username' => $user->user_login,
                        //     'message' =>"Customer quota full, you've reached the order limit for this product.",
                        //     'time' => now()->toDateTimeString(),
                        //     'cart_count' => 0,
                        //     'cart_items' => [],
                        // ], 200);
                    }
                    if ($quantity < $min) {
                        return response()->json(['status' => false, 'message' => 'Quantity cannot be less than ' . $min]);
                    }

                    if ($quantity > $max) {
                        return response()->json(['status' => false, 'message' => 'Quantity cannot be more than ' . $max]);
                    }
                }
                try {
                    if (in_array($variation_id, $this->couponProductID())) {
                        $limitCouponID = $product_id;
                        $limitUserEmail = $user->user_email;
                        $isApplicable = UserCoupon::where('qrDetail', 'GiftProduct')->where('email', $limitUserEmail)->first();
                        $limitCouponLable = 'GiftProduct' ?? 'NONAME';
                        $limitCouponRuleTitle = "Free AD Gift";
                        if ($isApplicable) {
                            $newMsg = "Opps! It seems like you already claimed Gift";
                            return response()->json([
                                'status' => false,
                                'username' => $user->user_login,
                                'message' => $newMsg,
                                // 'data' => $userIp,
                                'time' => now()->toDateTimeString(),
                                'cart_count' => 0,
                                'cart_items' => [],
                            ], 200);
                        } else {
                            UserCoupon::create([
                                'couponName' => $limitCouponRuleTitle,
                                'qrDetail' => $limitCouponLable,
                                'discountRuleId' => $limitCouponID,
                                'email' => $limitUserEmail,
                                'canUse' => false,
                                'meta' => null
                            ]);
                        }
                    }
                } catch (\Throwable $th) {
                }
                $cartItem = Cart::create([
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'quantity' => $quantity,
                    'min' => $min ?? null,
                    'max' => $max ?? null,
                    'isLimit' => $isLimit ?? 0,
                ]);
                if ($isFreeze) {
                    $this->reduceStock($cartItem);
                }
            }

            $cartItems[] = $cartItem;
        }

        $perPage = $request->input('per_page', 15);
        $cartItems = Cart::where('user_id', $user_id)->get();

        $userIp = $request->ip();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $userIp,
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $priceTier = $user->price_tier;
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartData = [];
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = 0;//$this->cartItemCount($cartTotalItems);
    
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;
            $itemCount++;
            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
            }

            $stockLevel = 0;
            $stockStatus = 'outofstock';
            if ($variation) {
                $stockLevel = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            } else {
                $stockLevel = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            }

            if ($stockStatus === 'instock' && $stockLevel > 0) {
                $stockStatus = 'instock';
            } else {
                $stockStatus = 'outofstock';
            }

            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;
            $categoryIds = $product->categories->pluck('term_id')->toArray();

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $productSlug,
                'product_price' => $wholesalePrice,
                'product_image' => $product->thumbnail_url,
                'stock' => $stockLevel,
                'stock_status' => $stockStatus,
                'quantity' => $cartItem->quantity,
                // 'min' => $cartItem->min ?? null,
                // 'max' => $cartItem->max ?? null,
                // 'isLimit' => $cartItem->isLimit ?? 0,
                'variation_id' => $variation ? $variation->ID : null,
                'variation' => $variationAttributes,
                'taxonomies' => $categoryIds
            ];
        }

        return response()->json([
            'status' => true,
            'success' => ($count > 0) ? $count . " items have Limit purchase limit" : 'Cart updated successfully',
            'data' => $userIp,
            'cart' => $cartData,
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            'itemCount' => $itemCount,
            // 'pagination' => [
            //     'total' => $cartItems->total(),
            //     'per_page' => $cartItems->perPage(),
            //     'current_page' => $cartItems->currentPage(),
            //     'last_page' => $cartItems->lastPage(),
            //     'next_page_url' => $cartItems->nextPageUrl(),
            //     'prev_page_url' => $cartItems->previousPageUrl(),
            // ]
        ], 200);
    }


    public function getCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => 'error'], 401);
        }

        // $perPage = $request->input('per_page', 15);
        $cartItems = Cart::where('user_id', $user->ID)->get();


        $userIp = $request->ip();
        if ($cartItems->isEmpty()) {
            return response()->json([
                'status' => false,
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $userIp,
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $priceTier = $user->price_tier;

        $priceTier = $priceTier ?? '_regular_price';

        $cartData = [];
        $total = $this->cartTotal($cartItems, $priceTier);
        $itemCount =0;// $this->cartItemCount($cartItems);
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;
            $wholesalePrice = 0;
            $itemCount++;
            if ($variation) {
                $wholesalePrice = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $wholesalePrice = $wholesalePrice ?? ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_price')
                    ->value('meta_value');
                try {
                    //code...
                } catch (\Throwable $th) {
                    //throw $th;
                }
            } else {
                $wholesalePrice = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', $priceTier)
                    ->value('meta_value');
                $wholesalePrice = $wholesalePrice ?? ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_price')
                    ->value('meta_value');
            }

            $stockLevel = 0;
            $stockStatus = 'outofstock';
            $taxID = null;
            $postID = $variation ? $variation->ID : $product->ID;

            // Fetch all required meta values in a single query
            $productMeta = ProductMeta::where('post_id', $postID)
                ->whereIn('meta_key', [
                    '_stock',
                    '_stock_status',
                    'mm_indirect_tax_type',
                    '_tax_class',
                    '_sku',
                    'mm_product_basis_1',
                    'mm_product_basis_2',
                    'mm_product_basis_3',
                    'max_quantity_var',
                    'min_quantity_var',
                    'mm_product_cost',
                    'max_quantity',
                ])
                ->pluck('meta_value', 'meta_key');

            $stockLevel = $productMeta->get('_stock', null);
            $stockStatus = $productMeta->get('_stock_status', null);
            $taxID = $productMeta->get('mm_indirect_tax_type', null);
            $taxON = $productMeta->get('mm_product_cost', null);

            $taxClass = $productMeta->get('_tax_class', null);
            if ($taxClass == 'parent') {
                // echo 'tax class is '.$taxClass;
                $taxClass = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_tax_class')
                    ->value('meta_value');
                // echo 'tax class is '.$taxClass;
            }

            $sku = $productMeta->get('_sku', null);
            $ml1taxID = $productMeta->get('mm_product_basis_1', null);
            $ml2taxID = $productMeta->get('mm_product_basis_2', null);
            $ml3taxID = $productMeta->get('mm_product_basis_3', null);
            $max_quantity_var = $productMeta->get('max_quantity_var', null);
            $min_quantity_var = $productMeta->get('min_quantity_var', null);
            $max_quantity = $productMeta->get('max_quantity', null);
            if ($stockStatus === 'instock' && $stockLevel > 0) {
                $stockStatus = 'instock';
            } else {
                $stockStatus = 'outofstock';
            }
            if ($taxID) {
            }
            $variationAttributes = [];
            if ($variation) {
                $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                foreach ($attributes as $attribute) {
                    $variationAttributes[] = $attribute->meta_value;
                }
            }

            $productSlug = $product->post_name;

            $categoryIds = $product->categories->pluck('term_id')->toArray();

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $productSlug,
                'product_price' => $wholesalePrice,
                'product_image' => $product->thumbnail_url,
                'stock' => $stockLevel,
                'stock_status' => $stockStatus,
                'quantity' => $cartItem->quantity,
                'isCouponProduct' => $variation && in_array($variation->ID, $this->couponProductID()) ? true : false,
                'variation_id' => $variation ? $variation->ID : null,
                'variation' => $variationAttributes,
                'taxonomies' => $categoryIds,
                'location_tax' => $taxID,
                'tax_class' => $taxClass,
                'sku' => $sku,
                'taxON' =>$taxON,
                'ml1' => $ml1taxID,
                'ml2' => $ml2taxID,
                'ml3' => $ml3taxID,
                'max_quantity_var' => $max_quantity_var ?? $max_quantity ?? null,
                'min_quantity_var' => $min_quantity_var
            ];
        }
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;
        $freeze_time = $checkout ? $checkout->updated_at : false;
        try {
            $existingCoupon = UserCoupon::where('email', $user->user_email)->where('canUse', true)->get();
        } catch (\Throwable $th) {
            $existingCoupon = [];
        }

        return response()->json([
            'status' => true,
            'freeze' => $isFreeze,
            'username' => $user->user_login,
            'qrCoupon' => $existingCoupon ?? null,
            'user_mm_txc' => strtoupper($user->mmtax) == "EX" ? "EX" : null,
            'message' => 'Cart items',
            'data' => $userIp,
            'current_time' => now()->toDateTimeString(),
            'freeze_time' => $freeze_time,
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            'itemCount' => $itemCount,
            'cart_items' => $cartData,
        ], 200);
    }



    public function deleteFromCart($id)
    {
        $cart = Cart::findOrFail($id);
        $user = JWTAuth::parseToken()->authenticate();
        $user_id = $user->ID;

        // Check if the user has frozen their cart
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        if ($isFreeze) {
            $this->increaseStock($cart);
        }

        $cart->delete();
        $priceTier = $user->price_tier;
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = 0;//$this->cartItemCount($cartTotalItems);
        $itemCount = Cart::where('user_id', $user->ID)->count();
        return response()->json([
            'status' => true,
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            'itemCount' => $itemCount,
            'success' => 'Product removed from cart'
        ], 200);
    }


    public function updateCartQuantity(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $currentDateTime = $request->input('currentDateTime') ?? now(); // take from request eg. 2025-05-28 10:00:00
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not authenticated'], 200);
        }

        $cartItem = Cart::where('user_id', $user->ID)
            ->where('product_id', $request->product_id)
            ->where('variation_id', $request->variation_id)
            ->first();

        if (!$cartItem || $request->quantity <= 0) {
            return response()->json(['status' => false, 'message' => 'Item not found'], 200);
        }

        $oldQuantity = $cartItem->quantity;
        $cartItem->quantity = $request->quantity;
        if ($cartItem->isLimit) {
            // check if customer have reached the limit
            $limitCheck = $this->checkProductLimit($cartItem->product_id, $cartItem->variation_id, $user->ID, $currentDateTime, $cartItem->max, $cartItem->quantity);
            
            if ($cartItem->quantity > $cartItem->max) {

                $reduceQTY = abs($oldQuantity - $cartItem->max);
                $cartItem->quantity = $cartItem->max;
                $cartItem->save();

                $checkout = Checkout::where('user_id', $user->ID)->first();
                $isFreeze = $checkout ? $checkout->isFreeze : false;

                if ($isFreeze) {
                    $this->adjustStock($cartItem, $oldQuantity, $reduceQTY);
                }
            }

            if ($cartItem->quantity < $cartItem->min) {
                $reduceQTY = abs($oldQuantity - $cartItem->min);
                $cartItem->quantity = $cartItem->min;
                $cartItem->save();

                $checkout = Checkout::where('user_id', $user->ID)->first();
                $isFreeze = $checkout ? $checkout->isFreeze : false;

                if ($isFreeze) {
                    $this->adjustStock($cartItem, $oldQuantity, $reduceQTY);
                }
            }
            if ($limitCheck['status'] == false) {
                $quantity = $limitCheck['allowedQty'];
                if($quantity > 0){
                    $cartItem->quantity = $quantity;
                    $cartItem->save();
                } else {
                    return response()->json([
                        'status' => false,
                        'username' => $user->user_login,
                        'message' =>"Customer quota full, you've reached the order limit for this product.",
                        'time' => now()->toDateTimeString(),
                        'cart_count' => 0,
                        'cart_items' => [],
                    ], 200);
                }
                $checkout = Checkout::where('user_id', $user->ID)->first();
                $isFreeze = $checkout ? $checkout->isFreeze : false;
                if ($isFreeze) {
                    $this->adjustStock($cartItem, $oldQuantity, $cartItem->quantity);
                }
            } else if ($request->quantity <= $cartItem->max && $request->quantity >= $cartItem->min) {
                $cartItem->quantity = $request->quantity;
                $cartItem->save();
                $checkout = Checkout::where('user_id', $user->ID)->first();
                $isFreeze = $checkout ? $checkout->isFreeze : false;

                if ($isFreeze) {
                    $this->adjustStock($cartItem, $oldQuantity, $request->quantity);
                }
            }
        } else {
            $cartItem->save();

            $checkout = Checkout::where('user_id', $user->ID)->first();
            $isFreeze = $checkout ? $checkout->isFreeze : false;

            if ($isFreeze) {
                $this->adjustStock($cartItem, $oldQuantity, $request->quantity);
            }
        }

        $priceTier = $user->price_tier;
        if (!$priceTier) {
            $priceTier = '_regular_price';
        }
        $cartTotalItems = Cart::where('user_id', $user->ID)->get();
        $total = $this->cartTotal($cartTotalItems, $priceTier);
        $itemCount = $this->cartItemCount($cartTotalItems);
        return response()->json([
            'message' => 'Item quantity updated',
            'cart_total' => $total[0],
            'location_tax' => $total[1],
            'cart_count' => $itemCount,
            'status' => true
        ], 200);
    }

    public function empty(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $user_id = $user->ID;

        // Check if the user has frozen their cart
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $cartItems = Cart::where('user_id', $user_id)->get();

        foreach ($cartItems as $cartItem) {
            if ($isFreeze) {
                $this->increaseStock($cartItem);
            }
            try {//code...
                if($cartItem->variation_id && in_array($cartItem->variation_id,$this->couponProductID())){
                    UserCoupon::where('qrDetail', 'GiftProduct')->where('email',$user->user_email)->delete();
                } 
            } catch (\Throwable $th) {
                
            }
            
        }

        Cart::where('user_id', $user_id)->delete();
        if ($checkout) {
            $checkout->delete();
        }

        return response()->json(['message' => 'All items removed', 'cart_count' => 0, 'status' => true], 200);
    }

    public function bulkDeleteCart(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $user_id = $user->ID;
        $checkout = Checkout::where('user_id', $user_id)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        if ($isFreeze) {
            return response()->json(['message' => 'Stock already reserved for 5 minutes, please order quickly', 'removed_items' => 0, 'status' => true], 200);
        }

        $cartItems = Cart::where('user_id', $user_id)->get();
        $removedItems = [];

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;

            $stockLevel = 0;
            $stockStatus = 'outofstock';

            if ($variation) {
                $stockLevel = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $variation->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            } else {
                $stockLevel = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock')
                    ->value('meta_value');

                $stockStatus = ProductMeta::where('post_id', $product->ID)
                    ->where('meta_key', '_stock_status')
                    ->value('meta_value');
            }

            // Check if the stock level is available
            if ($stockStatus != 'instock' || $stockLevel == 0) {
                // Remove out-of-stock item from the cart
                $cartItem->delete();
                $removedItems[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'message' => 'Item removed from cart due to being out of stock',
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Out-of-stock items removed from the cart',
            'removed_items' => $removedItems,
        ]);
    }
    public function removeById(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }
        // Check if the user has frozen their cart
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $isFreeze = $checkout ? $checkout->isFreeze : false;

        $cartItems = Cart::where('user_id', $user->ID)->whereIn('product_id', $request->input('productIds'))->get();
        foreach ($cartItems as $cartItem) {
            if ($isFreeze) {
                $this->increaseStock($cartItem);
            }
        }
        Cart::where('user_id', $user->ID)->whereIn('product_id', $request->input('productIds'))->delete();
        if ($checkout) {
            $checkout->delete();
        }
        return response()->json(['message' => 'Item removed from cart', 'status' => true], 200);
    }
}
