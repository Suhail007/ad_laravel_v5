<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Checkout;
use App\Models\ProductMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Jobs\UnfreezeCart;
use App\Models\UserMeta;
use App\Services\GeoRestrictionService;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;

class CheckoutController extends Controller
{
    private $security_key;
    public function __construct()
    {
        $this->security_key = config('services.nmi.security');
    }

    private function validateBilling($billingInformation)
    {
        $validBillingKeys = [
            "first_name",
            "last_name",
            "company",
            "address1",
            "address2",
            "city",
            "state",
            "zip",
            "country",
            "phone",
            "fax",
            "email"
        ];

        foreach ($billingInformation as $key => $value) {
            if (!in_array($key, $validBillingKeys)) {
                throw new Exception("Invalid key provided in billingInformation. '{$key}' is not a valid billing parameter.");
            }
        }
    }


    private function validateShipping($shippingInformation)
    {
        $validShippingKeys = [
            "shipping_first_name",
            "shipping_last_name",
            "shipping_company",
            "shipping_address1",
            "address2",
            "shipping_city",
            "shipping_state",
            "shipping_zip",
            "shipping_country",
            "shipping_email"
        ];

        foreach ($shippingInformation as $key => $value) {
            if (!in_array($key, $validShippingKeys)) {
                throw new Exception("Invalid key provided in shippingInformation. '{$key}' is not a valid shipping parameter.");
            }
        }
    }

    private function doSale($amount, $payment_token, $billing, $shipping)
    {
        $requestOptions = [
            'type' => 'sale',
            'amount' => $amount,
            'payment_token' => $payment_token
        ];
        $requestOptions = array_merge($requestOptions, $billing, $shipping);

        return $requestOptions;
    }

    private function _doRequest($postData)
    {
        $hostName = "secure.nmi.com";
        $path = "/api/transact.php";
        $client = new Client();

        $postData['security_key'] = config('services.nmi.security');
        $postUrl = "https://{$hostName}{$path}";

        try {
            $response = $client->post($postUrl, [
                'form_params' => $postData,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);

            parse_str($response->getBody(), $responseArray);

            $parsedResponseCode = (int)$responseArray['response_code'];
            $status = in_array($parsedResponseCode, [100, 200]);

            $paydata = [
                'status' => $status,
                'date' => $response->getHeaderLine('Date'),
                'responsetext' => $responseArray['responsetext'],
                'authcode' => $responseArray['authcode'] ?? '',
                'transactionid' => $responseArray['transactionid'] ?? 'failed',
                'avsresponse' => $responseArray['avsresponse'] ?? 'N',
                'cvvresponse' => $responseArray['cvvresponse'] ?? 'N',
                'description' => $response->getBody()->getContents(),
                'response_code' => $parsedResponseCode,
                'type' => $responseArray['type'] ?? ''
            ];

            return $paydata;
        } catch (Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    public function processPayment(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }
        $billingInfo = $request->input('billing');
        $shippingInfo = $request->input('shipping');
        $payment_token = $request->input('payment_token');
        $total = $request->input('total');
        $saleData = $this->doSale($total, $payment_token, $billingInfo, $shippingInfo);
        $paymentResult = $this->_doRequest($saleData);

        if (!$paymentResult['status']) {
            return response()->json([
                'status' => false,
                'message' => $paymentResult,
            ], 200);
        }
        return response()->json([
            'status' => true,
            'message' => $paymentResult,
        ], 200);
    }
    public function checkGeoRestrictions($shippingInfo)
    {
        $location = [
            'state' => $shippingInfo['state'] ?? null,
            'city' => $shippingInfo['city'] ?? null,
            'zip' => $shippingInfo['zip']??$shippingInfo['postcode'] ?? null
        ];

        // Get cart items
        $cartItems = Cart::where('user_id', JWTAuth::parseToken()->authenticate()->ID)->get();
        $restrictedProducts = [];

        foreach ($cartItems as $cartItem) {
            $productId = $cartItem->product_id;
            
            // Check if product is restricted
            $isRestricted = app(GeoRestrictionService::class)->isProductRestricted($productId, $location);
            $variationAttributes = [];
            if ($isRestricted) {
                $product = $cartItem->product;
                $variation = $cartItem->variation;
                if ($variation) {
                    $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                    foreach ($attributes as $attribute) {
                        $variationAttributes[] = $attribute->meta_value;
                    }
                }
                $restrictedProducts[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'product_name' => $product->post_title,
                    'product_image' => $product->thumbnail_url??null,
                    'product_slug' => $product->post_name,
                    'variation' => $variationAttributes??[],
                    'requested_quantity' => $cartItem->quantity,
                    'available_quantity'=>$this->getStockInfo($variation, $product)[0]??0,
                    'stock_status'=>$this->getStockInfo($variation, $product)[1]??'',
                    'wholesale_price' => $this->getWholesalePrice($variation, $product, JWTAuth::parseToken()->authenticate()->price_tier),
                    'reason' => 'This product is unavailable for shipping to your location'
                ];
            }
        }

        return $restrictedProducts;
    }

    public function checkoutAddress(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Unknown User'], 401);
        }

        $validate = Validator::make($request->all(), [
            'billing' => 'required|array',
            'shipping' => 'required|array'
        ]);

        if ($validate->fails()) {
            return response()->json(['status' => false, 'message' => $validate->errors()], 401);
        }

        $data = $request->all();

        $data['billing']['email']=$data['billing']['email']??$this->getUserMeta($user->ID, 'billing_email');
        // $isRestrictedState=in_array($data['shipping']['state'],['CA','UT','MN','PA'])?true:false;
        // if($isRestrictedState){
        //     return response()->json(['status' => false, 'message' => 'We are not accepting orders to the selected shipping state']);
        // }
        // Check for geo-restrictions
        // $restrictedProducts = $this->checkGeoRestrictions($data['shipping']);
        // if (!empty($restrictedProducts)) {
        //     return response()->json([
        //         'status' => true,
        //         'message' => 'Some products in your cart cannot be shipped to your location',
        //         'restricted_products' => $restrictedProducts,
        //         'reason'=>'geo_restriction',
        //         'location'=>$data['shipping'],
        //     ]);
        // }
        $checkout = Checkout::updateOrCreate(
            ['user_id' => $user->ID],
            [
                'billing' => $data['billing'],
                'shipping' => $data['shipping']
            ]
        );

        $check = Checkout::where('user_id', $user->ID)->firstOrFail();
        if (!$check->isFreeze) {
            $response = $this->freezeCart($request, $check);
            // $check->update([
            //     'isFreeze' => true,
            // ]);
            // UnfreezeCart::dispatch($user->ID)->delay(now()->addMinutes(5));
            return response()->json(['status' => true, 'message' => 'Address Selected Successfully', 'data' => $response], 201);
        }


        return response()->json(['status' => true, 'message' => 'Address Selected Successfully', 'data' => 'cart already Freezed'], 201);
    }
    private function getUserMeta($userId, $key)
    {
        return UserMeta::where('user_id', $userId)
            ->where('meta_key', $key)
            ->value('meta_value');
    }
    public function freezeCart(Request $request, $check)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable $th) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $cartItems = Cart::where('user_id', $user->ID)->get();
        if ($cartItems->isEmpty()) {
            return response()->json([
                'status' => false,
                'username' => $user->user_login,
                'message' => 'Cart is empty',
                'data' => $request->ip(),
                'time' => now()->toDateTimeString(),
                'cart_count' => 0,
                'cart_items' => [],
            ], 200);
        }

        $cartData = [];
        $adjustedItems = [];

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $variation = $cartItem->variation;

            if ($product->post_status !== 'publish') {
                $cartItem->delete();
                $variationAttributes = [];
                if ($variation) {
                    $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                    foreach ($attributes as $attribute) {
                        $variationAttributes[] = $attribute->meta_value;
                    }
                }
                $adjustedItems[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'product_name' => $product->post_title,
                    'product_image' => $product->thumbnail_url,
                    'variation' => $variationAttributes,
                    'requested_quantity'=>$cartItem->quantity,
                    'available_quantity'=>0,
                    'message' => 'Product is not published on web',
                ];
                continue;
            }
            $tier = $user->price_tier ?? '_price' ?? '_regular_price';
            $wholesalePrice = $this->getWholesalePrice($variation, $product, $tier);
            list($stockLevel, $stockStatus) = $this->getStockInfo($variation, $product);

            $adjusted = false;
            $originalQuantity = $cartItem->quantity;
            if ($stockLevel == "0") {
                $cartItem->delete();
                $variationAttributes = [];
                if ($variation) {
                    $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                    foreach ($attributes as $attribute) {
                        $variationAttributes[] = $attribute->meta_value;
                    }
                }
                $adjustedItems[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'product_name' => $product->post_title,
                    'product_image' => $product->thumbnail_url,
                    'requested_quantity' => $originalQuantity,
                    'available_quantity'=>0,
                    'variation' => $variationAttributes,
                    'message' => 'Product is out of stock',
                ];
                continue;
            }
            if ($cartItem->quantity > $stockLevel) {
                $cartItem->quantity = $stockLevel;
                $cartItem->save();
                $adjusted = true;
            }
            $variationAttributes = $this->getVariationAttributes($variation);

            $cartData[] = [
                'key' => $cartItem->id,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'product_slug' => $product->post_name,
                'product_price' => $wholesalePrice,
                'product_image' => $product->thumbnail_url,
                'stock' => $stockLevel,
                'stock_status' => $stockStatus,
                'quantity' => $cartItem->quantity,
                'variation_id' => $variation ? $variation->ID : null,
                'variation' => $variationAttributes,
            ];

            if ($adjusted) {
                $variationAttributes = [];
                if ($variation) {
                    $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
                    foreach ($attributes as $attribute) {
                        $variationAttributes[] = $attribute->meta_value;
                    }
                }
                $adjustedItems[] = [
                    'product_id' => $product->ID,
                    'variation_id' => $variation ? $variation->ID : null,
                    'product_name' => $product->post_title,
                    'product_image' => $product->thumbnail_url,
                    'requested_quantity' => $originalQuantity,
                    'available_quantity' => $stockLevel,
                    'variation'=>$variationAttributes,
                    'message' => 'Qty adjusted due to low stock',
                ];
            }
            if (empty($adjustedItems)) {
                // $check = Checkout::where('user_id', $user->ID)->firstOrFail();
                // if (!$check->isFreeze) {
                // if(!$check->isFreeze){
                $check->update([
                    'isFreeze' => true,
                ]);
                // }
                $this->reduceStock($cartItem);
                UnfreezeCart::dispatch($user->ID)->delay(now()->addMinutes(5));
                // }

            }
        }

        $response = [
            'status' => true,
            'username' => $user->user_login,
            'message' => 'Cart items frozen',
            'data' => $request->ip(),
            'time' => now()->toDateTimeString(),
            'cart_count' => count($cartData),
            'cart_items' => $cartData,
        ];

        if (!empty($adjustedItems)) {
            $response['adjusted_items'] = $adjustedItems;
            $response['message'] = 'Some items were adjusted due to low stock';
        }

        return response()->json($response);
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
    private function getWholesalePrice($variation, $product, $priceTier)
    {
        if ($variation) {
            return ProductMeta::where('post_id', $variation->ID)
                ->where('meta_key', $priceTier)
                ->value('meta_value');
        } else {
            return ProductMeta::where('post_id', $product->ID)
                ->where('meta_key', $priceTier)
                ->value('meta_value');
        }
    }

    private function getStockInfo($variation, $product)
    {
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

        $stockStatus = ($stockStatus === 'instock' && $stockLevel > 0) ? 'instock' : 'outofstock';

        return [$stockLevel, $stockStatus];
    }

    private function getVariationAttributes($variation)
    {
        $variationAttributes = [];
        if ($variation) {
            $attributes = DB::select("SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'attribute_%'", [$variation->ID]);
            foreach ($attributes as $attribute) {
                $variationAttributes[] = $attribute->meta_value;
            }
        }
        return $variationAttributes;
    }
}
