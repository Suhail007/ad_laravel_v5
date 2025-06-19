<?php

namespace App\Http\Controllers;

use App\Jobs\BufferJob;
use App\Jobs\SendOrderConfirmationEmail;
use App\Models\Buffer;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\DiscountRule;
use App\Models\Order;
use App\Models\OrderItemMeta;
use App\Models\OrderMeta;
use App\Models\ProductMeta;
use App\Models\User;
use App\Models\UserCoupon;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use PhpParser\Node\Stmt\TryCatch;
use Tymon\JWTAuth\Facades\JWTAuth;

class PayPalController extends Controller
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
    private function webhookIsVerified($webhookBody, $signingKey, $nonce, $sig)
    {
        return $sig === hash_hmac("sha256", $nonce . "." . $webhookBody, $signingKey);
    }
    public function handleWebhook(Request $request)
    {
        try {
            $signingKey = config('services.nmi.security');
            $webhookBody = $request->getContent();
            $headers = $request->headers->all();
            $sigHeader = $headers['webhook-signature'][0] ?? null;
            if (is_null($sigHeader) || strlen($sigHeader) < 1) {
                throw new Exception("Invalid webhook - signature header missing");
            }
            if (preg_match('/t=(.*),s=(.*)/', $sigHeader, $matches)) {
                $nonce = $matches[1];
                $signature = $matches[2];
            } else {
                throw new Exception("Unrecognized webhook signature format");
            }
            if (!$this->webhookIsVerified($webhookBody, $signingKey, $nonce, $signature)) {
                throw new Exception("Invalid webhook - signature verification failed");
            }
            $webhookData = json_decode($webhookBody, true);

            return response()->json(['message' => 'Webhook processed successfully', 'usefulldata' => $webhookData], 200);
        } catch (Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            return response()->json(['message' => 'Webhook verification failed: ' . $e->getMessage()]);
        }
    }
    /**
     * Summary of isShipViaWanhub
     * @description Both Woocommerce and ManageMore linked with same name at shipping method.
     * @param mixed $zipcode
     * @param mixed $city
     * @param mixed $state
     * @return bool
     */
    private function isShipViaWanhub($zipcode, $city, $state){
        $shipVia = DB::table('wanhub_list')->where('zip', $zipcode)->where('primary_city', $city)->where('state', $state)->first();
        return $shipVia ? true : false;
    }
    public function processPayment(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $agent = $request->userAgent() ?? "Unknown Device";
        $ip = $request->ip() ?? "0.0.0.0";
        $checkout = Checkout::where('user_id', $user->ID)->first();
        $billingInfo = $checkout->billing;
        $shippingInfo = $checkout->shipping;
        $amount = $request->input('amount');
        $lineItems = $request->input('line_items');
        $paytype = $request->input('paymentType');
        $amount = $request->input('amount');
        $order_type = $request->input('order_type');
        $stateType = $user->mmtax ?? $request->input('stateType') ?? 'OS';
        $order_role = $request->input('order_role');
        $order_wholesale_role = $request->input('order_role'); // $request->input('order_wholesale_role');
        $shippingLines = $request->input('shipping_lines');

        $orderDate = $request->input('orderDate')??now()->format('Y-m-d H:i:s');

        $paytype = $request->input('paymentType');
        if ($paytype == 'card') {
            // $restrictedProducts = (new CheckoutController())->checkGeoRestrictions($shippingInfo);
            // if (!empty($restrictedProducts)) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Some products in your cart cannot be shipped to your location',
            //         'restricted_products' => $restrictedProducts,
            //         'reason'=>'geo_restriction',
            //         'location'=>$shippingInfo,
            //     ]);
            // }
            $payment_token = $request->input('payment_token');
            try {
                $total = 0;
                $checkout->update(
                    [
                        // 'total' => $shippingLines[0]['total'] + $amount,
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );
                $orderData = Checkout::where('user_id', $user->ID)->first();
                //total item with unit tax with per unit discount
                $isVape = false;
                $order_tax = 0;
                $ordertotalQTY = 0;
                $productnames = [];
                $is_free = false;
                $percentageDiscount = 0;
                $cartDiscount = 0;
                $couponIDs = [];
                $isPercentageCoupone = false;


                // cart validation matching from frontend and backend
                $backendCart = Cart::where('user_id', $user->ID)->get();
                if($backendCart->count() == 0){
                    return response()->json([
                        'status' => false,
                        'message' => 'Your cart is empty. Please add items to your cart and try again.',
                        'reason' => 'cart_empty',
                    ]);
                }
                $frontendCart = $orderData['extra'];
                $indexedBackendCart = [];
                foreach ($backendCart as $item) {
                    $key = $item->product_id . '-' . ($item->variation_id ?? 'null');
                    $indexedBackendCart[$key] = $item;
                }
                $indexedFrontendCart = [];
                foreach ($frontendCart as $item) {
                    $key = $item['product_id'] . '-' . ($item['variation_id'] ?? 'null');
                    $indexedFrontendCart[$key] = $item;
                }
                foreach ($indexedFrontendCart as $key => $frontendItem) {
                    $backendItem = $indexedBackendCart[$key] ?? null;
                    if (!$backendItem) {
                        if (!($frontendItem['is_free_product'] ?? false)) {
                            return response()->json([
                                'status' => false,
                                'message' => 'Some items in your cart are no longer available. Please refresh your cart and try again.',
                                'reason' => 'frontend_item_not_in_backend',
                                'product_id' => $frontendItem['product_id']
                            ]);
                        }
                        continue;
                    }
                    if ($frontendItem['quantity'] != $backendItem->quantity) {
                        if($frontendItem['is_free_product'] == true){
                            continue;
                        }
                        return response()->json([
                            'status' => false,
                            'message' => 'One or more items in your cart have changed in quantity. Please review and update your cart before placing the order.',
                            'reason' => 'frontend_quantity_exceeds_backend',
                            'product_id' => $frontendItem['product_id']
                        ]);
                    }
                }
                foreach ($indexedBackendCart as $key => $backendItem) {
                    if (!isset($indexedFrontendCart[$key])) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Your cart has been updated on another device. Please refresh your cart to continue.',
                            'reason' => 'backend_item_not_in_frontend',
                            'product_id' => $backendItem->product_id
                        ]);
                    }
                }
                // cart validation matching from frontend and backend end


                foreach ($orderData['extra'] as $item) {
                    if ($item['quantity'] < 0) {
                        $item['quantity'] = 1;
                    }
                    $ordertotalQTY += $item['quantity'];
                    $subtotal = $item['product_price'];

                    $productnames[] = $item['product_name'];

                    if ($item['isVape'] == true) {
                        $order_tax += $item['quantity'] * $item['taxPerUnit'];
                        $order_tax = round($order_tax, 1);
                        $isVape = true;
                    } else {
                        $subtotal = $subtotal + ($item['taxPerUnit'] ?? 0);
                    }
                    try {
                        if (isset($item['discount_amt']) && $item['discount_amt']) {
                            if($item['type'] == 'fixed_price'){

                            } else {
                                $cartDiscount += $item['discount_amt'];
                            }

                            $couponIDs[] = $item['applicable_rules'][0]['rule_id'];

                            //coupon user limit validation and functionality
                            try {
                                if ($item['applicable_rules'][0]['userUseLimit'] == 1 && $item['applicable_rules'][0]['label'] == "Spaceman10") {
                                    $limitCouponID = $item['applicable_rules'][0]['rule_id'];
                                    $limitUserEmail = $user->user_email;
                                    $isApplicable = UserCoupon::where('discountRuleId', $limitCouponID)->where('email', $limitUserEmail)->first();
                                    $limitCouponLable = $item['applicable_rules'][0]['label'] ?? 'NONAME';
                                    $limitCouponRuleTitle = $item['applicable_rules'][0]['rule_title'];
                                    if ($isApplicable && $isApplicable->canUse == false) {
                                        return response()->json(['status' => false, 'message' => "Opps! You are not allowed to use $limitCouponLable again", 'reload' => true]);
                                    } else if ($isApplicable) {
                                        $isApplicable->update([
                                            'couponName' => $limitCouponRuleTitle,
                                            'qrDetail' => $limitCouponLable,
                                            'discountRuleId' => $limitCouponID,
                                            'email' => $limitUserEmail,
                                            'canUse' => false,
                                            'meta' => null
                                        ]);
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
                                //throw $th;
                            }

                            $isPercentageCoupone = true;
                        }
                    } catch (\Throwable $th) {
                        Log::info($th->getMessage());
                    }

                    $float1 = $item['quantity'] * $subtotal;
                    $total += round($float1, 2);
                }

                //cart discount
                $cartDiscountTax = 0;
                if ($cartDiscount > 0 && $isVape) {
                    $cartDiscountTax = $cartDiscount * 0.15;
                    $cartDiscountTax = round($cartDiscountTax, 2);
                }
                $cartDiscount = round($cartDiscount, 2);
                $total = $total - $cartDiscount + $order_tax;

                $subtotal = $total;
                $shppingtotal = $shippingLines[0]['total'];
                $total += $shppingtotal;

                $checkout->update(
                    [
                        'total' => $total,
                    ]
                );

                if (isset($billingInfo['postcode'])) {
                    $billingInfo['zipcode'] = $billingInfo['postcode'];
                    unset($billingInfo['postcode']);
                }
                if (isset($shippingInfo['postcode'])) {
                    $shippingInfo['zipcode'] = $shippingInfo['postcode'];
                    unset($shippingInfo['postcode']);
                }
                // $isRestrictedState=in_array($shippingInfo['state'],['CA','UT','MN','PA'])?true:false;
                // if($isRestrictedState){
                //     return response()->json(['status' => false, 'message' => 'We are not accepting orders to the selected shipping state']);
                // }
                $validShippingKeys = [
                    "shipping_first_name" => "first_name",
                    "shipping_last_name" => "last_name",
                    "shipping_company" => "company",
                    "shipping_address1" => "address_1",
                    "address2" => "address_2",
                    "shipping_city" => "city",
                    "shipping_state" => "state",
                    "shipping_zip" => "zipcode",
                    "shipping_country" => "country",
                    "shipping_email" => null
                ];

                $validBillingKeys = [
                    "first_name" => "first_name",
                    "last_name" => "last_name",
                    "company" => "company",
                    "address1" => "address_1",
                    "address2" => "address_2",
                    "city" => "city",
                    "state" => "state",
                    "zip" => "zipcode",
                    "country" => "country",
                    "phone" => "phone",
                    "fax" => null,
                    "email" => "email"
                ];

                // Restructure shipping info
                $restructuredShipping = [];
                foreach ($validShippingKeys as $newKey => $oldKey) {
                    $restructuredShipping[$newKey] = $oldKey !== null && isset($shippingInfo[$oldKey]) ? $shippingInfo[$oldKey] : null;
                }

                // Restructure billing info
                $restructuredBilling = [];
                foreach ($validBillingKeys as $newKey => $oldKey) {
                    $restructuredBilling[$newKey] = $oldKey !== null && isset($billingInfo[$oldKey]) ? $billingInfo[$oldKey] : null;
                }

                // Output the restructured arrays
                $shippingInfo = $restructuredShipping;
                $billingInfo = $restructuredBilling;
                $saleData = $this->doSale($total, $payment_token, $billingInfo, $shippingInfo);
                $paymentResult = $this->_doRequest($saleData);
                try {
                    Log::info('Payment Result: ' . json_encode($paymentResult, JSON_PRETTY_PRINT));
                } catch (\Throwable $th) {
                }
                if ($paymentResult['status'] === false || $paymentResult['responsetext'] !== 'Approved') {
                    return response()->json([
                        'status' => false,
                        'message' => $paymentResult['responsetext'], // Send the response text as the message
                        'uniqueId' => null
                    ], 200);
                }
                $orderData = Checkout::where('user_id', $user->ID)->first();
                try {
                    DB::beginTransaction();

                    // $newValue = DB::transaction(function () {
                    //     // Increment the value directly in the database
                    //     DB::table('wp_options')
                    //         ->where('option_name', 'wt_last_order_number')
                    //         ->increment('option_value', 1);

                    //     // Retrieve the new updated value
                    //     return DB::table('wp_options')
                    //         ->where('option_name', 'wt_last_order_number')
                    //         ->value('option_value');
                    // });



                    $orderId = DB::table('wp_posts')->insertGetId([
                        'post_author' => $user->ID,
                        'post_date' => now(),
                        'post_date_gmt' => now(),
                        'post_content' => '',
                        'post_title' => 'Order',
                        'to_ping' => '',
                        'pinged' => '',
                        'post_content_filtered' => '',
                        'post_excerpt' => '',
                        'post_status' => 'wc-pre-processing',
                        'comment_status' => 'open',
                        'ping_status' => 'closed',
                        'post_name' => 'order-' . uniqid(),
                        'post_modified' => now(),
                        'post_modified_gmt' => now(),
                        'post_type' => 'shop_order',
                        'guid' => 'https://ad.phantasm.solutions/?post_type=shop_order&p=' . uniqid(),
                    ]);

                    $newValue = $orderId;
                    $state = $orderData['shipping']['state'];
                    if ($shippingLines[0]['total']) {
                        $floattotal = 15.00;
                    } else {
                        $floattotal = 0.00;
                    }
                    $metaData = [
                        ['post_id' => $orderId, 'meta_key' => '_billing_first_name', 'meta_value' => $orderData['billing']['first_name']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_last_name', 'meta_value' => $orderData['billing']['last_name']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_1', 'meta_value' => $orderData['billing']['address_1']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_2', 'meta_value' => $orderData['billing']['address_2']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_city', 'meta_value' => $orderData['billing']['city']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_state', 'meta_value' => $orderData['billing']['state']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_postcode', 'meta_value' => $orderData['billing']['postcode']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_country', 'meta_value' => $orderData['billing']['country']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_email', 'meta_value' => $orderData['billing']['email']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_phone', 'meta_value' => $orderData['billing']['phone']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_first_name', 'meta_value' => $orderData['shipping']['first_name']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_last_name', 'meta_value' => $orderData['shipping']['last_name']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_1', 'meta_value' => $orderData['shipping']['address_1']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_2', 'meta_value' => $orderData['shipping']['address_2']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_city', 'meta_value' => $orderData['shipping']['city']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_state', 'meta_value' => $orderData['shipping']['state']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_postcode', 'meta_value' => $orderData['shipping']['postcode']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_country', 'meta_value' => $orderData['shipping']['country']],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => $orderData['paymentType']],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => 'Credit-Debit Card'],
                        ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => $orderId], //$paymentResult['data']['transactionid']],
                        ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $total],
                        ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                        // ['post_id' => $orderId, 'meta_key' => 'mm_field_ITX', 'meta_value' => $isVape ? 0 : null],
                        ['post_id' => $orderId, 'meta_key' => 'mm_field_CID', 'meta_value' => $user->account ?? null],
                        ['post_id' => $orderId, 'meta_key' => 'mm_login_id', 'meta_value' => $user->user_email ?? null],
                        ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                        ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],
                        ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                        ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                        ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ['post_id' => $orderId, 'meta_key' => '_cart_discount', 'meta_value' => $cartDiscount ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_cart_discount_tax', 'meta_value' => $cartDiscountTax ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_order_tax', 'meta_value' => $order_tax ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_order_shipping', 'meta_value' => $floattotal],
                        ['post_id' => $orderId, 'meta_key' => '_order_shipping_tax', 'meta_value' => 0],
                        ['post_id' => $orderId, 'meta_key' => '_order_date', 'meta_value' => $orderDate??null],
                        // Add custom meta to prevent WooCommerce from recalculating shipping
                        ['post_id' => $orderId, 'meta_key' => '_custom_shipping_locked', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_original_shipping_amount', 'meta_value' => $floattotal],
                    ];
                    try {
                        if ($isPercentageCoupone) {
                            $discountRule = DB::table('wp_wdr_rules')->whereIn('id', $couponIDs)->get();

                            $data = [
                                'free_shipping' => false,
                                'cart_discounts' => [
                                    'applied_as' => 'coupon',
                                    'combine_all_discounts' => false,
                                    'applied_coupons' => [],
                                ],
                                'saved_amount' => [
                                    'product_level' => 0,
                                    'product_level_based_on_tax_settings' => 0,
                                    'cart_level' => 0,
                                    'total' => 0,
                                    'total_based_on_tax_settings' => 0,
                                ]
                            ];
                            // print_r($discountRule);

                            foreach ($discountRule as $coupon) {
                                $productAdjustments = json_decode($coupon->product_adjustments, true);
                                if (json_last_error() === JSON_ERROR_NONE && isset($productAdjustments['cart_label'])) {
                                    $cartLabel = $productAdjustments['cart_label'];
                                    $cartValue = $productAdjustments['value'];
                                } else {
                                    $cartLabel = ' ';
                                }

                                $data['cart_discounts']['applied_coupons'][] = [
                                    'name' => $coupon->title,
                                    'value' => $cartDiscount ?? 0,
                                    'rules' => [
                                        [
                                            'id' => $coupon->id,
                                            'title' => $coupon->title,
                                            'discount' => [
                                                'discount_label' => $cartLabel ?? ' ',
                                                'discount_value' => $cartDiscount ?? 0,
                                            ]
                                        ]
                                    ]
                                ];
                                $data['saved_amount']['cart_level'] += $cartDiscount;
                                $data['saved_amount']['total'] += $cartDiscount;
                                $data['saved_amount']['total_based_on_tax_settings'] += $cartDiscount;
                            }
                            $serializedData = serialize($data);
                            $metaData[] = ['post_id' => $orderId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedData ?? ' '];
                            $_wdr_discounts = $serializedData;
                            $serializedData = '';
                        }
                    } catch (\Throwable $th) {
                    }

                    foreach ($metaData as $meta) {
                        OrderMeta::insert($meta);
                    }
                    if ($stateType == 'EX') {
                        $metaValueST = 'EX';
                        $stateEx = true;
                    } elseif ($orderData['shipping']['state'] == 'IL') {
                        $metaValueST =  'IL';
                    } else {
                        $metaValueST =  'OS';
                    }

                    OrderMeta::insert([
                        'post_id' => $orderId,
                        'meta_key' => 'mm_field_TXC',
                        'meta_value' => $metaValueST,
                    ]);


                    if ($stateType == 'EX' && $orderData['shipping']['state'] != 'IL') {
                        OrderMeta::insert([
                            'post_id' => $orderId,
                            'meta_key' => 'mm_field_ITX',
                            'meta_value' => 1,
                        ]);
                    } else {
                        OrderMeta::insert([
                            'post_id' => $orderId,
                            'meta_key' => 'mm_field_ITX',
                            'meta_value' => $isVape ? 0 : null,
                        ]);
                    }


                    $totalAmount = $total;
                    $productCount = count($orderData['extra']);
                    if($shippingLines[0]['method_title'] == 'Flat rate'){
                        $isShipViaWanhub = $this->isShipViaWanhub($orderData['shipping']['postcode'], $orderData['shipping']['city'], $orderData['shipping']['state']);
                        if($isShipViaWanhub){
                            $shippingLines[0]['method_title'] = 'WANHUB';
                        } else {
                            $shippingLines[0]['method_title'] = 'WANHUB-NA';
                        }
                    }
                    $id1 = DB::table('wp_woocommerce_order_items')->insertGetId([
                        'order_id' => $orderId,
                        'order_item_name' => $shippingLines[0]['method_title'],
                        'order_item_type' => 'shipping'
                    ]);

                    $productnamesString = implode(',', $productnames);
                    $shippingtaxmeta = [
                        ['order_item_id' => $id1, 'meta_key' => 'taxes', 'meta_value' =>  serialize(['total' => [0]])],
                        ['order_item_id' => $id1, 'meta_key' => 'total_tax', 'meta_value' => 0],
                        ['order_item_id' => $id1, 'meta_key' => 'Items', 'meta_value' => $productnamesString ?? ' '],
                        ['order_item_id' => $id1, 'meta_key' => 'cost', 'meta_value' => $floattotal],
                         ['order_item_id' => $id1, 'meta_key' => 'instance_id', 'meta_value' => ($shippingLines[0]['method_id'] == 'flat_rate') ? ($shippingLines[0]['method_title'] == 'WANHUB' ? 3 : 4) : 2],
                        // ['order_item_id' => $id1, 'meta_key' => 'instance_id', 'meta_value' => ($shippingLines[0]['method_id'] == 'flat_rate') ? 1 : 2],
                        ['order_item_id' => $id1, 'meta_key' => 'method_id', 'meta_value' => $shippingLines[0]['method_id']],
                    ];
                    if ($floattotal > 0) {
                        Buffer::create([
                            'order_id' => $orderId,
                            'shipping' => $shippingLines[0]['method_title'],
                        ]);
                        // BufferJob::dispatch();
                    }
                    if ($cartDiscount > 0) {
                        Buffer::create([
                            'order_id' => $orderId,
                            'shipping' => $cartDiscount,
                            'extra' => $cartDiscountTax
                        ]);
                        // BufferJob::dispatch();
                    }

                    foreach ($shippingtaxmeta as $meta) {
                        OrderItemMeta::insert($meta);
                    }

                    if ($isVape) {
                        $id2 = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => 'IL-STATE TAX-1',
                            'order_item_type' => 'tax'
                        ]);
                        $metaILTax = [
                            ['order_item_id' => $id2, 'meta_key' => 'rate_percent', 'meta_value' => $shppingtotal],
                            ['order_item_id' => $id2, 'meta_key' => 'shipping_tax_amount', 'meta_value' => 0],
                            ['order_item_id' => $id2, 'meta_key' => 'tax_amount', 'meta_value' => $order_tax ?? 0], //$amount * 0.15],
                            ['order_item_id' => $id2, 'meta_key' => 'label', 'meta_value' => 'State Tax'],
                            ['order_item_id' => $id2, 'meta_key' => 'compound', 'meta_value' => ''],
                            ['order_item_id' => $id2, 'meta_key' => 'rate_id', 'meta_value' => 1],
                        ];
                        foreach ($metaILTax as $meta) {
                            OrderItemMeta::insert($meta);
                        }
                    }
                    try {
                        if ($request->input('cartAdjustment')) {
                            $cartAdjustment = $request->input('cartAdjustment');
                            if ($cartAdjustment[0]['couponName']) {
                                $id4 = DB::table('wp_woocommerce_order_items')->insertGetId([
                                    'order_id' => $orderId,
                                    'order_item_name' => $cartAdjustment[0]['couponName'],
                                    'order_item_type' => 'coupon'
                                ]);
                                if ($cartAdjustment[0]['type'] == 'percentage') {
                                    $discountRateTypec = 'percent';
                                }

                                $coupon_info = [0, $cartAdjustment[0]['couponName'], $discountRateTypec, 0];
                                $jsonCouponInfo = json_encode($coupon_info);
                                $metaILTax = [
                                    ['order_item_id' => $id4, 'meta_key' => 'coupon_info', 'meta_value' => $jsonCouponInfo],
                                    ['order_item_id' => $id4, 'meta_key' => 'discount_amount_tax', 'meta_value' => 0],
                                    ['order_item_id' => $id4, 'meta_key' => 'discount_amount', 'meta_value' => 0],
                                ];
                                foreach ($metaILTax as $meta) {
                                    OrderItemMeta::insert($meta);
                                }
                            }
                        }
                    } catch (\Throwable $th) {
                    }

                    $taxAmmountWC = 0;
                    $temp = false;
                    $giveawaySaleCasper = 0;
                    $giveawaySaleKrature=0;
                    foreach ($orderData['extra'] as $item) {
                        if ($item['quantity'] < 0) {
                            $item['quantity'] = 1;
                        }
                        $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => $item['product_name'],
                            'order_item_type' => 'line_item'
                        ]);

                        $cartItem = Cart::where('user_id', $user->ID)
                            ->where('product_id', $item['product_id'])
                            ->where('variation_id', $item['variation_id'] ?? null)
                            ->first();

                        if (isset($cartItem->isLimit) && $cartItem->isLimit && isset($cartItem->max) && $cartItem->max > 0) {
                            $productVariationId = $item['variation_id'] ?? $item['product_id'];

                            // Step 1: Get active session from ProductMeta
                            $sessionMeta = ProductMeta::where('post_id', $productVariationId)
                                ->where('meta_key', 'sessions_limit_data')
                                ->first();

                            $activeSessionId = null;

                            if ($sessionMeta) {
                                $sessions = json_decode($sessionMeta->meta_value, true) ?? [];
                                $now = Carbon::parse($orderDate);

                                foreach ($sessions as $session) {
                                    if (
                                        isset($session['isActive']) && $session['isActive'] &&
                                        $now->between(Carbon::parse($session['limit_session_start'] ?? '2000-01-01 00:00:00'), Carbon::parse($session['limit_session_end'] ?? '2099-01-01 00:00:00'))
                                        ) {
                                        $activeSessionId = $session['session_limt_id'] ?? null;
                                        break;
                                    }
                                }
                            }


                            // Step 2: If an active session exists, update or insert order count
                            if ($activeSessionId) {
                                $productLimitSession = DB::table('product_limit_session')
                                    ->where('product_variation_id', $productVariationId)
                                    ->where('user_id', $user->ID)
                                    ->where('session_id', $activeSessionId)
                                    ->first();

                                if ($productLimitSession) {
                                    $limitCount = $productLimitSession->limit_count + $cartItem->quantity;
                                    if($limitCount >= $cartItem->max){
                                        // 4+1 = 5 increase the order count by 1
                                        DB::table('product_limit_session')
                                        ->where('id', $productLimitSession->id)
                                        ->update([
                                            'order_count' => $productLimitSession->order_count + 1,
                                            'limit_count' =>$productLimitSession->limit_count + $cartItem->quantity,
                                            'updated_at' => now(),
                                        ]);
                                    } else {
                                        // 2+1 = 3 increase the limit count by $cartItem->quantity
                                        DB::table('product_limit_session')
                                        ->where('id', $productLimitSession->id)
                                        ->update([
                                            'limit_count' =>$productLimitSession->limit_count + $cartItem->quantity,
                                            'updated_at' => now(),
                                        ]);
                                    }
                                } else {
                                    if($cartItem->quantity < $cartItem->max){
                                        DB::table('product_limit_session')->insert([
                                            'product_variation_id' => $productVariationId,
                                            'user_id' => $user->ID,
                                            'session_id' => $activeSessionId,
                                            'order_count' => 0,
                                            'limit_count' => $cartItem->quantity, //1
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                    } else {
                                        DB::table('product_limit_session')->insert([
                                            'product_variation_id' => $productVariationId,
                                            'user_id' => $user->ID,
                                            'session_id' => $activeSessionId,
                                            'order_count' => 1,
                                            'limit_count' =>$cartItem->quantity, //1
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                    }
                                }
                            }
                        }

                        // Step 3: Remove item from cart
                        if ($cartItem) {
                            $cartItem->delete();
                        }


                        $productPrice = $item['product_price'];
                        $linetotal = 0;
                        $iLTax = 0;
                        $initialPrice = 0;

                        if ($item['isVape'] == true) {

                            $iLTax = $item['quantity'] * $item['taxPerUnit'];
                            $iLTax = round($iLTax, 2);
                        } else {
                            $productPrice = $productPrice + ($item['taxPerUnit'] ?? 0);
                        }
                        $float2 = 0.00;
                        $float2 = $item['quantity'] * $productPrice;
                        $float2 = round($float2, 2);

                        // 16/04/2025
                        if (!empty($item['taxonomies']) && in_array(1835, $item['taxonomies'])) { // 1835-> casper-blend
                            $giveawaySaleCasper += $float2;
                        }
                        if (!empty($item['taxonomies']) && in_array(2687, $item['taxonomies'])) { // 2687->krature-hydroxy
                            $giveawaySaleKrature += $float2;

                        }

                        $linetotal += $float2;

                        $taxAmount = (float) ($iLTax ?? 0);

                        $serializedData = sprintf(
                            'a:2:{s:5:"total";a:1:{i:1;s:6:"%.2f";}s:8:"subtotal";a:1:{i:1;s:6:"%.2f";}}',
                            $taxAmount,
                            $taxAmount
                        );
                        $float3 = 0.00;
                        $float3 = $item['quantity'] * $item['taxPerUnit'];
                        $indirect_tax_amount = round($float3, 2);

                        if ($orderData['shipping']['state'] == 'IL' && $item['isVape'] == true) {
                            $indirect_tax_amount = 0.00;
                        }


                        $itemMeta = [
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_sku', 'meta_value' => $item['sku'] ?? 'AD'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_reduced_stock', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => 'flavor', 'meta_value' => implode(',', $item['variation']) ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis', 'meta_value' => $item['ml1'] * $item['quantity'] ?? $item['ml2'] * $item['quantity'] ?? 0], //
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $order_role],

                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => $indirect_tax_amount ?? 0],

                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => isset($item['type']) && $item['type'] == 'fixed_price' ? (isset($item['subTotal']) ? $item['subTotal'] : 0) : ($linetotal ?? 0)],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => isset($item['type']) && $item['type'] == 'fixed_price' ? ((isset($item['subTotal']) ? $item['subTotal'] : 0) + (isset($item['discount_amt']) ? $item['discount_amt'] : 0)) : ($linetotal ?? 0)],

                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => $iLTax ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => $iLTax ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' =>  $serializedData],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j2', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j2', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j1', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j1', 'meta_value' => 0],

                        ];
                        if (isset($item['is_free_product']) && $item['is_free_product']) {
                            $discountId = $item['discount_id'];

                            $initialPrice = $item['initial_price'];
                            $discounted_price = $productPrice;
                            $initial_price_based_on_tax_settings = $initialPrice;
                            $discounted_price_based_on_tax_settings = $productPrice;
                            $saved_amount = $initialPrice - $discounted_price;
                            $saved_amount_based_on_tax_settings = $saved_amount;


                            $metaValue = [
                                'initial_price' => $initialPrice,
                                'discounted_price' => $discounted_price,
                                'initial_price_based_on_tax_settings' => $initial_price_based_on_tax_settings,
                                'discounted_price_based_on_tax_settings' => $discounted_price_based_on_tax_settings,
                                'applied_rules' => [],
                                'saved_amount' => $saved_amount,
                                'saved_amount_based_on_tax_settings' => $saved_amount_based_on_tax_settings,
                                'is_free_product' => $item['is_free_product']
                            ];

                            $serializedMetaValue = serialize($metaValue);

                            $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedMetaValue];
                            $variation_id = $item['variation_id'] ?? 0;
                            $product_id = $item['product_id'];
                            if ($variation_id) {
                                $stockLevel = ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->value('meta_value');
                                $newStockLevel = max(0, $stockLevel - $item['quantity']);
                                ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                            } else {
                                $stockLevel = ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->value('meta_value');
                                $newStockLevel = max(0, $stockLevel - $item['quantity']);
                                ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                            }
                        }

                        try {


                            $ischangeproducttocart = false;
                            if (isset($item['discount_amt']) && $item['discount_amt']) {
                                $discountAmount = $item['discount_amt'];
                                $coupon = DB::table('wp_wdr_rules')->where('id', $item['applicable_rules'][0]['rule_id'])->first();

                                $productAdjustments = json_decode($coupon->product_adjustments, true);
                                try {
                                    if ($request->input('cartAdjustment')) {
                                        $productAdjustments = json_decode($coupon->cart_adjustments, true);
                                        $ischangeproducttocart = true;
                                    }
                                } catch (\Throwable $th) {
                                }

                                if (json_last_error() === JSON_ERROR_NONE && isset($productAdjustments['cart_label'])) {
                                    $cartLabel = $productAdjustments['cart_label'];
                                    $cartValue = $productAdjustments['value'];
                                    $cartType = $productAdjustments['type'];
                                } else if ($ischangeproducttocart) {
                                    $cartLabel = $productAdjustments['label'];
                                    $cartValue = $productAdjustments['value'];
                                    $cartType = $productAdjustments['type'];
                                } else {
                                    $cartLabel = ' ';
                                    $cartValue = 0;
                                    $cartType = ' ';
                                }
                                if ($cartType == 'percentage' || $cartType == 'fixed_price') {
                                    $cartTypeN = 'percent';
                                }
                                $couponTitle = $cartLabel; //20% off  //<-lable
                                $discountRateType = $cartTypeN; // 'percent'
                                $discountRateValue = $cartValue; //20
                                if ($cartType == 'fixed_price') {
                                    $discountRateValue = ($item['subTotal'] / ($item['subTotal'] + $item['discount_amt'])) * 100;
                                }

                                if ($temp == false) {
                                    $id3 = DB::table('wp_woocommerce_order_items')->insertGetId([
                                        'order_id' => $orderId,
                                        'order_item_name' => $couponTitle,
                                        'order_item_type' => 'coupon'
                                    ]);
                                    $temp = true;
                                }


                                $coupon_info = [0, $couponTitle, $discountRateType, $discountRateValue];
                                $jsonCouponInfo = json_encode($coupon_info);
                                $metaILTax = [
                                    ['order_item_id' => $id3, 'meta_key' => 'coupon_info', 'meta_value' => $jsonCouponInfo],
                                    ['order_item_id' => $id3, 'meta_key' => 'discount_amount_tax', 'meta_value' => round($cartDiscountTax, 2) ?? 0],
                                    ['order_item_id' => $id3, 'meta_key' => 'discount_amount', 'meta_value' => $cartDiscount ?? 0],
                                ];

                                foreach ($metaILTax as $meta) {
                                    OrderItemMeta::insert($meta);
                                }

                                $lineTotalValue = $float2 - $discountAmount; //product price with tax
                                // dd($lineTotalValue);
                                foreach ($itemMeta as &$meta) {
                                    if ($meta['meta_key'] == '_line_total') {
                                        $meta['meta_value'] = $lineTotalValue;
                                    }
                                    // elseif ($meta['meta_key'] === '_line_subtotal') {
                                    //     $meta['meta_value'] = $lineSubtotalValue;
                                    // } $value = 9.998;   $roundedValue = round($value); //10

                                }
                                $initialPriced = $productPrice;
                                $discounted_priced = $productPrice;
                                $initial_price_based_on_tax_settingsd = $productPrice;
                                $discounted_price_based_on_tax_settingsd = $productPrice;
                                $idd = $coupon->id;
                                $titled = $coupon->title;
                                $appliedInd = 'cart_level';
                                $discount_typed = $cartType;
                                $discount_valued = $discountRateValue;
                                $discount_labeld = $couponTitle;
                                $discount_priced = $discountAmount;
                                $qtyd = $item['quantity'];
                                $data2 = [
                                    'initial_price' => $initialPriced,
                                    'discounted_price' => $discounted_priced,
                                    'initial_price_based_on_tax_settings' => $initial_price_based_on_tax_settingsd,
                                    'discounted_price_based_on_tax_settings' => $discounted_price_based_on_tax_settingsd,
                                    'applied_rules' => [
                                        [
                                            'id' => $idd,
                                            'title' => $titled,
                                            'type' => 'simple_discount',
                                            'discount' => [
                                                'applied_in' => $appliedInd,
                                                'discount_type' => $discount_typed,
                                                'discount_value' => $discount_valued,
                                                'discount_label' => $discount_labeld,
                                                'discount_price' => $discount_priced
                                            ]
                                        ]
                                    ],
                                    'saved_amount' => 0,
                                    'saved_amount_based_on_tax_settings' => 0,
                                    'is_free_product' => false
                                ];
                                $serializedData2 = serialize($data2);

                                $data3 = [
                                    'initial_price' => $initialPriced,
                                    'discounted_price' => $discounted_priced,
                                    'total_discount_details' => [],
                                    'cart_discount_details' => [
                                        $idd => [ // Use coupon ID or another unique identifier here
                                            'cart_discount' => $discount_valued,
                                            'cart_shipping' => 'no',
                                            'cart_discount_type' => $discount_typed,
                                            'cart_discount_label' => $discount_labeld,
                                            'cart_discount_price' => $discount_priced,
                                            'cart_discount_product_price' => $discount_priced
                                        ]
                                    ],
                                    'apply_as_cart_rule' => ['yes'],
                                    'discount_lines' => [
                                        'non_applied' => [
                                            'quantity' => $qtyd,
                                            'discount' => 0,
                                            'price' => $initialPriced,
                                            'calculate_discount_from' => $initialPriced
                                        ]
                                    ],
                                    'cart_quantity' => $qtyd,
                                    'product_id' => $item['variation_id'] ?? $item['product_id'],
                                    'initial_price_based_on_tax_settings' => $initialPrice,
                                    'discounted_price_based_on_tax_settings' => $initialPrice
                                ];

                                $serializedData3 = serialize($data3);
                                $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedData2];
                                $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_advanced_woo_discount_item_total_discount', 'meta_value' => $serializedData3];
                            }
                        } catch (\Throwable $th) {
                            //throw $th;
                        }

                        foreach ($itemMeta as $meta) {
                            OrderItemMeta::insert($meta);
                        }

                        $unitshippingCharge = (float) ($shppingtotal / max($ordertotalQTY, 1)) * $item['quantity'];
                        $done = DB::table('wp_wc_order_product_lookup')->insert([
                            'order_item_id' => $orderItemId,
                            'order_id' => $orderId,
                            'product_id' => $item['product_id'],
                            'variation_id' => $item['variation_id'] ?? 0,
                            'customer_id' => $user->ID,
                            'date_created' => now(),
                            'product_qty' => $item['quantity'],
                            'product_net_revenue' => $linetotal,
                            'product_gross_revenue' => $isVape ? $totalAmount : 0,
                            'tax_amount' => $iLTax ?? 0,
                            'coupon_amount' => 0,
                            'shipping_amount' => $unitshippingCharge ?? 0,
                            'shipping_tax_amount' => 0,
                        ]);
                    }

                    DB::table('wp_wc_orders')->insert([
                        'id' => $orderId,
                        'status' => 'wc-pre-processing',
                        'currency' => 'USD',
                        'type' => 'shop_order',
                        'tax_amount' => $order_tax ?? 0,
                        'total_amount' => $totalAmount,
                        'customer_id' => $user->ID,
                        'billing_email' => $orderData['billing']['email'],
                        'date_created_gmt' => now(),
                        'date_updated_gmt' => now(),
                        'parent_order_id' => 0,
                        'payment_method' => $orderData['paymentType'],
                        'payment_method_title' => 'Credit-Debit Card', //. $paymentResult['data']['transactionid'],
                        'transaction_id' => uniqid(),
                        'ip_address' => $ip,
                        'user_agent' => $agent,
                        'customer_note' => ''
                    ]);


                    //pending
                    // $wp_wc_order_meta = [
                    //     ['order_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                    //     ['order_id' => $orderId, 'meta_key' => '_order_tax', 'meta_value' => $order_tax ?? 0],
                    //     ['order_id' => $orderId, 'meta_key' => '_wwpp_order_type', 'meta_value' => $order_type],
                    //     ['order_id' => $orderId, 'meta_key' => '_wwpp_wholesale_order_type', 'meta_value' => $order_wholesale_role],
                    //     ['order_id' => $orderId, 'meta_key' => 'wwp_wholesale_role', 'meta_value' => $order_wholesale_role],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_CID', 'meta_value' => $user->account ?? null],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_TXC', 'meta_value' => $metaValue ?? 'OS'],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_ITX', 'meta_value' => 0],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_login_id', 'meta_value' => $user->user_email ?? null],
                    //     [
                    //         'order_id' => $orderId,
                    //         'meta_key' => '_shipping_address_index',
                    //         'meta_value' => trim(
                    //             (isset($orderData['shipping']['first_name']) ? $orderData['shipping']['first_name'] . ' ' : '') .
                    //                 (isset($orderData['shipping']['address_1']) ? $orderData['shipping']['address_1'] . ' ' : '') .
                    //                 (isset($orderData['shipping']['city']) ? $orderData['shipping']['city'] . ' ' : '') .
                    //                 (isset($orderData['shipping']['state']) ? $orderData['shipping']['state'] . ' ' : '') .
                    //                 (isset($orderData['shipping']['postcode']) ? $orderData['shipping']['postcode'] : '')
                    //         )

                    //     ],
                    // ];


                    // DB::table('wp_wc_orders_meta')->insert($wp_wc_order_meta);

                    try {
                        $billingCompany = $orderData['billing']['company'];
                        $shippingCompany = $orderData['shipping']['company'];
                    } catch (\Throwable $th) {
                        $billingCompany = '';
                        $shippingCompany = '';
                    }
                    DB::table('wp_wc_order_addresses')->insert([
                        [
                            'order_id' => $orderId,
                            'address_type' => 'billing',
                            'first_name' => $orderData['billing']['first_name'],
                            'last_name' => $orderData['billing']['last_name'],
                            'company' => $billingCompany ?? '',
                            'address_1' => $orderData['billing']['address_1'],
                            'address_2' => $orderData['billing']['address_2'],
                            'city' => $orderData['billing']['city'],
                            'state' => $orderData['billing']['state'],
                            'postcode' => $orderData['billing']['postcode'],
                            'country' => $orderData['billing']['country'],
                            'email' => $orderData['billing']['email'],
                            'phone' => $orderData['billing']['phone']
                        ],
                        [
                            'order_id' => $orderId,
                            'address_type' => 'shipping',
                            'first_name' => $orderData['shipping']['first_name'],
                            'last_name' => $orderData['shipping']['last_name'],
                            'company' => $shippingCompany ?? '',
                            'address_1' => $orderData['shipping']['address_1'],
                            'address_2' => $orderData['shipping']['address_2'],
                            'city' => $orderData['shipping']['city'],
                            'state' => $orderData['shipping']['state'],
                            'postcode' => $orderData['shipping']['postcode'],
                            'country' => $orderData['shipping']['country'],
                            'email' => $orderData['billing']['email'],
                            'phone' => $orderData['billing']['phone']
                        ]
                    ]);

                    DB::table('wp_wc_order_stats')->insert([
                        'order_id' => $orderId,
                        'parent_id' => 0,
                        'status' => 'wc-pre-processing',
                        'date_created' => now(),
                        'date_created_gmt' => now(),
                        'num_items_sold' => $productCount,
                        'total_sales' => $totalAmount,
                        'tax_total' => 0,
                        'shipping_total' => $shippingLines[0]['total'],
                        'net_total' => $totalAmount,
                        'returning_customer' => 0,
                        'customer_id' => $user->ID,
                        'date_paid' => null,
                        'date_completed' => null,
                    ]);

                    $orderNotes = [
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Order status changed from Pending payment to Processing (express).',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Credit-Debit Card complete', // (Charge ID: ' . $paymentResult['data']['transactionid'],
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                    ];
                    foreach ($orderNotes as $note) {
                        DB::table('wp_comments')->insert($note);
                    }
                    $mail = $user->user_email;
                    try {
                        $coupon = UserCoupon::where('email', $mail)->first();
                        if (!$coupon) {
                        }
                        if ($coupon->canUse === false) {
                        }
                        $coupon->canUse = false;
                        $coupon->save();
                    } catch (\Throwable $th) {
                        Log::info($th->getMessage());
                    }
                    $checkout->delete();
                    DB::commit();
                    try {
                        $email = $orderData['billing']['email'];
                        $username = $orderData['billing']['first_name'] . ' ' . $orderData['billing']['last_name'];
                        $deliveryDate = '3 working Days';
                        $businessAddress = implode(' ', $orderData['shipping']);
                        $order = Order::with(['meta', 'items.meta'])->find($newValue);
                        $shippingAddress = $businessAddress ?? 'N/A';
                        $orderDate = $order->post_date;
                        $paymentMethod = $order->meta->where('meta_key', '_payment_method_title')->first()->meta_value ?? 'N/A';
                        $items = $order->items->where('order_item_type', 'line_item')->map(function ($item) {
                            $sku = $item->meta->where('meta_key', '_sku')->first()->meta_value ?? 'N/A';
                            $quantity = $item->meta->where('meta_key', '_qty')->first()->meta_value ?? 0;
                            $subtotal = $item->meta->where('meta_key', '_line_subtotal')->first()->meta_value ?? 0;
                            $total = $item->meta->where('meta_key', '_line_total')->first()->meta_value ?? 0;

                            return [
                                'name' => $item->order_item_name,
                                'sku' => $sku,
                                'quantity' => $quantity,
                                'subtotal' => $subtotal,
                                'total' => $total,
                            ];
                        });
                        $subtotal = $order->meta->where('meta_key', '_order_subtotal')->first()->meta_value ?? 0;
                        $shipping = $order->meta->where('meta_key', '_order_shipping')->first()->meta_value ?? 0;
                        $tax = $order->meta->where('meta_key', '_order_tax')->first()->meta_value ?? 0;
                        $discount = $order->meta->where('meta_key', '_cart_discount')->first()->meta_value ?? 0;
                        $total = $order->meta->where('meta_key', '_order_total')->first()->meta_value ?? 0;
                        $watermarkNumber = $user->account ?? '  ';
                        $html = View::make('pdf.order_invoice', compact(
                            'order',
                            'shippingAddress',
                            'orderDate',
                            'paymentMethod',
                            'items',
                            // 'subtotal',
                            'shipping',
                            'tax',
                            'discount',
                            'total',
                            'watermarkNumber'
                        ))->render();
                        $dompdf = new Dompdf();
                        $dompdf->loadHtml($html);
                        $dompdf->setPaper('A4', 'portrait');
                        $dompdf->render();
                        $pdfOutput = $dompdf->output();
                        $tempFilePath = "temp/order_invoice_{$orderId}.pdf";
                        Storage::put($tempFilePath, $pdfOutput);

                        SendOrderConfirmationEmail::dispatch(
                            $email,
                            $newValue,
                            $username,
                            $deliveryDate,
                            $businessAddress,
                            $tempFilePath
                        );
                    } catch (\Throwable $th) {
                        Log::info("Failed to send mail for $orderId because:");
                        Log::info($th->getMessage());
                    }
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 'Order creation failed: ' . $e->getMessage()], 500);
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Payment successful',
                    'data' => $paymentResult,
                    'order' => $orderId,
                    'orderNo' => $newValue
                ], 200);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        } else if ($paytype == 'onaccount') {
            // $restrictedProducts = (new CheckoutController())->checkGeoRestrictions($shippingInfo);
            // if (!empty($restrictedProducts)) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Some products in your cart cannot be shipped to your location',
            //         'restricted_products' => $restrictedProducts,
            //         'reason'=>'geo_restriction',
            //         'location'=>$shippingInfo,
            //     ]);
            // }
            try {
                $total = 0;
                $checkout->update(
                    [
                        'extra' => $lineItems,
                        'paymentType' => $paytype,
                    ]
                );
                $orderData = Checkout::where('user_id', $user->ID)->first();
                // $shippingInfo=$orderData['shipping'];
                // $isRestrictedState=in_array($shippingInfo['state'],['CA','UT','MN','PA'])?true:false;
                // if($isRestrictedState){
                //     return response()->json(['status' => false, 'message' => 'We are not accepting orders to the selected shipping state']);
                // }

                $isVape = false;
                $order_tax = 0;
                $ordertotalQTY = 0;
                $productnames = [];
                $is_free = false;
                $percentageDiscount = 0;
                $cartDiscount = 0;
                $couponIDs = [];
                $isPercentageCoupone = false;

                // cart validation matching from frontend and backend
                $backendCart = Cart::where('user_id', $user->ID)->get();
                if($backendCart->count() == 0){
                    return response()->json([
                        'status' => false,
                        'message' => 'Your cart is empty. Please add items to your cart and try again.',
                        'reason' => 'cart_empty',
                    ]);
                }
                $frontendCart = $orderData['extra'];
                $indexedBackendCart = [];
                foreach ($backendCart as $item) {
                    $key = $item->product_id . '-' . ($item->variation_id ?? 'null');
                    $indexedBackendCart[$key] = $item;
                }
                $indexedFrontendCart = [];
                foreach ($frontendCart as $item) {
                    $key = $item['product_id'] . '-' . ($item['variation_id'] ?? 'null');
                    $indexedFrontendCart[$key] = $item;
                }
                foreach ($indexedFrontendCart as $key => $frontendItem) {
                    $backendItem = $indexedBackendCart[$key] ?? null;
                    if (!$backendItem) {
                        if (!($frontendItem['is_free_product'] ?? false)) {
                            return response()->json([
                                'status' => false,
                                'message' => 'Some items in your cart are no longer available. Please refresh your cart and try again.',
                                'reason' => 'frontend_item_not_in_backend',
                                'product_id' => $frontendItem['product_id']
                            ]);
                        }
                        continue;
                    }
                    if ($frontendItem['quantity'] != $backendItem->quantity) {
                        if($frontendItem['is_free_product'] == true){
                            continue;
                        }
                        return response()->json([
                            'status' => false,
                            'message' => 'One or more items in your cart have changed in quantity. Please review and update your cart before placing the order.',
                            'reason' => 'frontend_quantity_exceeds_backend',
                            'product_id' => $frontendItem['product_id']
                        ]);
                    }
                }
                foreach ($indexedBackendCart as $key => $backendItem) {
                    if (!isset($indexedFrontendCart[$key])) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Your cart has been updated on another device. Please refresh your cart to continue.',
                            'reason' => 'backend_item_not_in_frontend',
                            'product_id' => $backendItem->product_id
                        ]);
                    }
                }
                // cart validation matching from frontend and backend end



                foreach ($orderData['extra'] as $item) {
                    if ($item['quantity'] < 0) {
                        $item['quantity'] = 1;
                    }
                    $ordertotalQTY += $item['quantity'];
                    $subtotal = $item['product_price'];
                    $productnames[] = $item['product_name'];

                    if ($item['isVape'] == true) {
                        $order_tax += $item['quantity'] * $item['taxPerUnit'];
                        $order_tax = round($order_tax, 1);
                        $isVape = true;
                    } else {
                        $subtotal = $subtotal + ($item['taxPerUnit'] ?? 0);
                    }
                    //percentage discount only
                    try {
                        if (isset($item['discount_amt']) && $item['discount_amt']) {
                            $cartDiscount += $item['discount_amt'];

                            $couponIDs[] = $item['applicable_rules'][0]['rule_id'];

                            //coupon user limit validation and functionality
                            try {
                                if ($item['applicable_rules'][0]['userUseLimit'] == 1 && $item['applicable_rules'][0]['label'] == "Spaceman10") {
                                    $limitCouponID = $item['applicable_rules'][0]['rule_id'];
                                    $limitUserEmail = $user->user_email;
                                    $isApplicable = UserCoupon::where('discountRuleId', $limitCouponID)->where('email', $limitUserEmail)->first();
                                    $limitCouponLable = $item['applicable_rules'][0]['label'] ?? 'NONAME';
                                    $limitCouponRuleTitle = $item['applicable_rules'][0]['rule_title'];
                                    if ($isApplicable && $isApplicable->canUse == false) {
                                        return response()->json(['status' => false, 'message' => "Opps! You are not allowed to use $limitCouponLable again", 'reload' => true]);
                                    } else if ($isApplicable) {
                                        $isApplicable->update([
                                            'couponName' => $limitCouponRuleTitle,
                                            'qrDetail' => $limitCouponLable,
                                            'discountRuleId' => $limitCouponID,
                                            'email' => $limitUserEmail,
                                            'canUse' => false,
                                            'meta' => null
                                        ]);
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
                                //throw $th;
                            }


                            $isPercentageCoupone = true;
                        }
                    } catch (\Throwable $th) {
                    }

                    $float1 = $item['quantity'] * $subtotal;
                    $total += round($float1, 2);
                }

                //cart discount
                $cartDiscountTax = 0;
                if ($cartDiscount > 0 && $isVape) {
                    $cartDiscountTax = $cartDiscount * 0.15;
                    $cartDiscountTax = round($cartDiscountTax, 2);
                }
                $cartDiscount = round($cartDiscount, 2);
                $total = $total - $cartDiscount + $order_tax;

                $subtotal = $total;
                $shppingtotal = $shippingLines[0]['total'];
                $total += $shppingtotal;

                $checkout->update(
                    [
                        'total' => $total,
                    ]
                );

                $orderData = Checkout::where('user_id', $user->ID)->first();
                try {
                    DB::beginTransaction();

                    // $newValue = DB::transaction(function () {
                    //     // Increment the value directly in the database
                    //     DB::table('wp_options')
                    //         ->where('option_name', 'wt_last_order_number')
                    //         ->increment('option_value', 1);

                    //     // Retrieve the new updated value
                    //     return DB::table('wp_options')
                    //         ->where('option_name', 'wt_last_order_number')
                    //         ->value('option_value');
                    // });

                    $orderId = DB::table('wp_posts')->insertGetId([
                        'post_author' => $user->ID,
                        'post_date' => now(),
                        'post_date_gmt' => now(),
                        'post_content' => '',
                        'post_title' => 'Order',
                        'to_ping' => '',
                        'pinged' => '',
                        'post_content_filtered' => '',
                        'post_excerpt' => '',
                        'post_status' => 'wc-pre-processing',
                        'comment_status' => 'open',
                        'ping_status' => 'closed',
                        'post_name' => 'order-' . uniqid(),
                        'post_modified' => now(),
                        'post_modified_gmt' => now(),
                        'post_type' => 'shop_order',
                        'guid' => 'https://ad.phantasm.solutions/?post_type=shop_order&p=' . uniqid(),
                    ]);
                    $newValue = $orderId;
                    $state = $orderData['shipping']['state'];
                    if ($shippingLines[0]['total']) {
                        $floattotal = 15.00;
                    } else {
                        $floattotal = 0.00;
                    }
                    $metaData = [
                        ['post_id' => $orderId, 'meta_key' => '_billing_first_name', 'meta_value' => $orderData['billing']['first_name']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_last_name', 'meta_value' => $orderData['billing']['last_name']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_1', 'meta_value' => $orderData['billing']['address_1']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_2', 'meta_value' => $orderData['billing']['address_2']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_city', 'meta_value' => $orderData['billing']['city']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_state', 'meta_value' => $orderData['billing']['state']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_postcode', 'meta_value' => $orderData['billing']['postcode']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_country', 'meta_value' => $orderData['billing']['country']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_email', 'meta_value' => $orderData['billing']['email']],
                        ['post_id' => $orderId, 'meta_key' => '_billing_phone', 'meta_value' => $orderData['billing']['phone']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_first_name', 'meta_value' => $orderData['shipping']['first_name']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_last_name', 'meta_value' => $orderData['shipping']['last_name']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_1', 'meta_value' => $orderData['shipping']['address_1']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_2', 'meta_value' => $orderData['shipping']['address_2']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_city', 'meta_value' => $orderData['shipping']['city']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_state', 'meta_value' => $orderData['shipping']['state']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_postcode', 'meta_value' => $orderData['shipping']['postcode']],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_country', 'meta_value' => $orderData['shipping']['country']],
                        ['post_id' => $orderId, 'meta_key' => '_order_shipping', 'meta_value' => $floattotal],
                        ['post_id' => $orderId, 'meta_key' => '_order_shipping_tax', 'meta_value' => 0],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method', 'meta_value' => 'managemore_onaccount'],
                        ['post_id' => $orderId, 'meta_key' => '_payment_method_title', 'meta_value' => '(*** PLEASE DONT USE THIS PAYMENT METHOD UNTIL WE ASK YOU TO DO IT. YOUR ORDER WILL AUTOMATICALLY GET CANCELLED.)'], //$orderData['payment_method_title']],
                        ['post_id' => $orderId, 'meta_key' => '_transaction_id', 'meta_value' => uniqid()],
                        ['post_id' => $orderId, 'meta_key' => 'mm_field_CID', 'meta_value' => $user->account ?? null],
                        // ['post_id' => $orderId, 'meta_key' => 'mm_field_TXC', 'meta_value' => $state == 'IL' ? 'IL' : 'OS'],
                        // ['post_id' => $orderId, 'meta_key' => 'mm_field_ITX', 'meta_value' => $isVape ? 0 : null],
                        ['post_id' => $orderId, 'meta_key' => 'mm_login_id', 'meta_value' => $user->user_email ?? null],
                        ['post_id' => $orderId, 'meta_key' => '_order_total', 'meta_value' => $total], //$orderData['shipping_lines'][0]['total'] + array_reduce($orderData['line_items'], function ($carry, $item) {return $carry + $item['quantity'] * $item['product_price'];}, 0)],
                        ['post_id' => $orderId, 'meta_key' => '_order_currency', 'meta_value' => 'USD'],
                        ['post_id' => $orderId, 'meta_key' => '_order_key', 'meta_value' => 'wc_order_' . uniqid()],
                        ['post_id' => $orderId, 'meta_key' => '_customer_user', 'meta_value' => $user->ID],
                        ['post_id' => $orderId, 'meta_key' => '_created_via', 'meta_value' => 'checkout'],
                        ['post_id' => $orderId, 'meta_key' => '_order_stock_reduced', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_billing_address_index', 'meta_value' => implode(' ', $orderData['billing'])],
                        ['post_id' => $orderId, 'meta_key' => '_shipping_address_index', 'meta_value' => implode(' ', $orderData['shipping'])],
                        ['post_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                        ['post_id' => $orderId, 'meta_key' => '_cart_discount', 'meta_value' => $cartDiscount ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_cart_discount_tax', 'meta_value' => $cartDiscountTax ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_order_tax', 'meta_value' => $order_tax ?? 0],
                        ['post_id' => $orderId, 'meta_key' => '_order_date', 'meta_value' => $orderDate??null],
                        // Add custom meta to prevent WooCommerce from recalculating shipping
                        ['post_id' => $orderId, 'meta_key' => '_custom_shipping_locked', 'meta_value' => 'yes'],
                        ['post_id' => $orderId, 'meta_key' => '_original_shipping_amount', 'meta_value' => $floattotal],
                    ];

                    try {
                        if ($isPercentageCoupone) {
                            $discountRule = DB::table('wp_wdr_rules')->whereIn('id', $couponIDs)->get();

                            $data = [
                                'free_shipping' => false,
                                'cart_discounts' => [
                                    'applied_as' => 'coupon',
                                    'combine_all_discounts' => false,
                                    'applied_coupons' => [],
                                ],
                                'saved_amount' => [
                                    'product_level' => 0,
                                    'product_level_based_on_tax_settings' => 0,
                                    'cart_level' => 0,
                                    'total' => 0,
                                    'total_based_on_tax_settings' => 0,
                                ]
                            ];
                            // print_r($discountRule);

                            foreach ($discountRule as $coupon) {
                                $productAdjustments = json_decode($coupon->product_adjustments, true);
                                if (json_last_error() === JSON_ERROR_NONE && isset($productAdjustments['cart_label'])) {
                                    $cartLabel = $productAdjustments['cart_label'];
                                    $cartValue = $productAdjustments['value'];
                                } else {
                                    $cartLabel = ' ';
                                }

                                $data['cart_discounts']['applied_coupons'][] = [
                                    'name' => $coupon->title,
                                    'value' => $cartDiscount ?? 0,
                                    'rules' => [
                                        [
                                            'id' => $coupon->id,
                                            'title' => $coupon->title,
                                            'discount' => [
                                                'discount_label' => $cartLabel ?? ' ',
                                                'discount_value' => $cartDiscount ?? 0,
                                            ]
                                        ]
                                    ]
                                ];
                                $data['saved_amount']['cart_level'] += $cartDiscount;
                                $data['saved_amount']['total'] += $cartDiscount;
                                $data['saved_amount']['total_based_on_tax_settings'] += $cartDiscount;
                            }
                            $serializedData = serialize($data);
                            $metaData[] = ['post_id' => $orderId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedData ?? ' '];
                            $_wdr_discounts = $serializedData;
                            $serializedData = '';
                        }
                    } catch (\Throwable $th) {
                    }

                    foreach ($metaData as $meta) {
                        OrderMeta::insert($meta);
                    }
                    if ($stateType == 'EX') {
                        $metaValueST = 'EX';
                    } elseif ($orderData['shipping']['state'] == 'IL') {
                        $metaValueST =  'IL';
                    } else {
                        $metaValueST =  'OS';
                    }

                    OrderMeta::insert([
                        'post_id' => $orderId,
                        'meta_key' => 'mm_field_TXC',
                        'meta_value' => $metaValueST,
                    ]);

                    if ($stateType == 'EX' && $orderData['shipping']['state'] != 'IL') {
                        OrderMeta::insert([
                            'post_id' => $orderId,
                            'meta_key' => 'mm_field_ITX',
                            'meta_value' => 1,
                        ]);
                    } else {
                        OrderMeta::insert([
                            'post_id' => $orderId,
                            'meta_key' => 'mm_field_ITX',
                            'meta_value' => $isVape ? 0 : null,
                        ]);
                    }


                    $totalAmount = $total;
                    $productCount = count($orderData['extra']);
                    if($shippingLines[0]['method_title'] == 'Flat rate'){
                        $isShipViaWanhub = $this->isShipViaWanhub($orderData['shipping']['postcode'], $orderData['shipping']['city'], $orderData['shipping']['state']);
                        if($isShipViaWanhub){
                            $shippingLines[0]['method_title'] = 'WANHUB';
                        } else {
                            $shippingLines[0]['method_title'] = 'WANHUB-NA';
                        }
                    }
                    $id1 = DB::table('wp_woocommerce_order_items')->insertGetId([
                        'order_id' => $orderId,
                        'order_item_name' => $shippingLines[0]['method_title'],
                        'order_item_type' => 'shipping'
                    ]);

                    $productnamesString = implode(',', $productnames);
                    $shippingtaxmeta = [
                        ['order_item_id' => $id1, 'meta_key' => 'taxes', 'meta_value' =>  serialize(['total' => [0]])],
                        ['order_item_id' => $id1, 'meta_key' => 'total_tax', 'meta_value' => 0],
                        ['order_item_id' => $id1, 'meta_key' => 'Items', 'meta_value' => $productnamesString ?? ' '],
                        ['order_item_id' => $id1, 'meta_key' => 'cost', 'meta_value' => $floattotal],
                        ['order_item_id' => $id1, 'meta_key' => 'instance_id', 'meta_value' => ($shippingLines[0]['method_id'] == 'flat_rate') ? ($shippingLines[0]['method_title'] == 'WANHUB' ? 3 : 4) : 2],
                        // ['order_item_id' => $id1, 'meta_key' => 'instance_id', 'meta_value' => ($shippingLines[0]['method_id'] == 'flat_rate') ? 1 : 2],
                        ['order_item_id' => $id1, 'meta_key' => 'method_id', 'meta_value' => $shippingLines[0]['method_id']],
                    ];
                    if ($floattotal > 0) {
                        Buffer::create([
                            'order_id' => $orderId,
                            'shipping' => $shippingLines[0]['method_title'],
                        ]);
                        // BufferJob::dispatch();
                    }
                    if ($cartDiscount > 0) {
                        Buffer::create([
                            'order_id' => $orderId,
                            'shipping' => $cartDiscount,
                            'extra' => $cartDiscountTax
                        ]);
                        // BufferJob::dispatch();
                    }

                    foreach ($shippingtaxmeta as $meta) {
                        OrderItemMeta::insert($meta);
                    }

                    if ($isVape) {
                        $id2 = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => 'IL-STATE TAX-1',
                            'order_item_type' => 'tax'
                        ]);
                        $metaILTax = [
                            ['order_item_id' => $id2, 'meta_key' => 'rate_percent', 'meta_value' => $shppingtotal],
                            ['order_item_id' => $id2, 'meta_key' => 'shipping_tax_amount', 'meta_value' => 0],
                            ['order_item_id' => $id2, 'meta_key' => 'tax_amount', 'meta_value' => $order_tax ?? 0], //$amount * 0.15],
                            ['order_item_id' => $id2, 'meta_key' => 'label', 'meta_value' => 'State Tax'],
                            ['order_item_id' => $id2, 'meta_key' => 'compound', 'meta_value' => ''],
                            ['order_item_id' => $id2, 'meta_key' => 'rate_id', 'meta_value' => 1],
                        ];

                        foreach ($metaILTax as $meta) {
                            OrderItemMeta::insert($meta);
                        }
                    }

                    try {
                        if ($request->input('cartAdjustment')) {
                            $cartAdjustment = $request->input('cartAdjustment');
                            if ($cartAdjustment[0]['couponName']) {
                                $id4 = DB::table('wp_woocommerce_order_items')->insertGetId([
                                    'order_id' => $orderId,
                                    'order_item_name' => $cartAdjustment[0]['couponName'],
                                    'order_item_type' => 'coupon'
                                ]);
                                if ($cartAdjustment[0]['type'] == 'percentage') {
                                    $discountRateTypec = 'percent';
                                }

                                $coupon_info = [0, $cartAdjustment[0]['couponName'], $discountRateTypec, 0];
                                $jsonCouponInfo = json_encode($coupon_info);
                                $metaILTax = [
                                    ['order_item_id' => $id4, 'meta_key' => 'coupon_info', 'meta_value' => $jsonCouponInfo],
                                    ['order_item_id' => $id4, 'meta_key' => 'discount_amount_tax', 'meta_value' => 0],
                                    ['order_item_id' => $id4, 'meta_key' => 'discount_amount', 'meta_value' => 0],
                                ];
                                foreach ($metaILTax as $meta) {
                                    OrderItemMeta::insert($meta);
                                }
                            }
                        }
                    } catch (\Throwable $th) {
                    }


                    $dd = [];
                    $temp = false;
                    foreach ($orderData['extra'] as $item) {
                        if ($item['quantity'] < 0) {
                            $item['quantity'] = 1;
                        }
                        $orderItemId = DB::table('wp_woocommerce_order_items')->insertGetId([
                            'order_id' => $orderId,
                            'order_item_name' => $item['product_name'],
                            'order_item_type' => 'line_item'
                        ]);
                        $cartItem = Cart::where('user_id', $user->ID)
                        ->where('product_id', $item['product_id'])
                        ->where('variation_id', $item['variation_id'] ?? null)
                        ->first();

                        if (isset($cartItem->isLimit) && $cartItem->isLimit && isset($cartItem->max) && $cartItem->max > 0) {
                            $productVariationId = $item['variation_id'] ?? $item['product_id'];

                            // Step 1: Get active session from ProductMeta
                            $sessionMeta = ProductMeta::where('post_id', $productVariationId)
                                ->where('meta_key', 'sessions_limit_data')
                                ->first();

                            $activeSessionId = null;

                            if ($sessionMeta) {
                                $sessions = json_decode($sessionMeta->meta_value, true) ?? [];
                                $now = Carbon::parse($orderDate);

                                foreach ($sessions as $session) {
                                    if (
                                        isset($session['isActive']) && $session['isActive'] &&
                                        $now->between(Carbon::parse($session['limit_session_start'] ?? '2000-01-01 00:00:00'), Carbon::parse($session['limit_session_end'] ?? '2099-01-01 00:00:00'))
                                        ) {
                                        $activeSessionId = $session['session_limt_id'] ?? null;
                                        break;
                                    }
                                }
                            }

                            // Step 2: If an active session exists, update or insert order count
                            if ($activeSessionId) {
                                $productLimitSession = DB::table('product_limit_session')
                                    ->where('product_variation_id', $productVariationId)
                                    ->where('user_id', $user->ID)
                                    ->where('session_id', $activeSessionId)
                                    ->first();

                                if ($productLimitSession) {
                                    $limitCount = $productLimitSession->limit_count + $cartItem->quantity;
                                    if($limitCount >= $cartItem->max){
                                        // 4+1 = 5 increase the order count by 1
                                        DB::table('product_limit_session')
                                        ->where('id', $productLimitSession->id)
                                        ->update([
                                            'order_count' => $productLimitSession->order_count + 1,
                                            'limit_count' =>$productLimitSession->limit_count + $cartItem->quantity,
                                            'updated_at' => now(),
                                        ]);
                                    } else {
                                        // 2+1 = 3 increase the limit count by $cartItem->quantity
                                        DB::table('product_limit_session')
                                        ->where('id', $productLimitSession->id)
                                        ->update([
                                            'limit_count' =>$productLimitSession->limit_count + $cartItem->quantity,
                                            'updated_at' => now(),
                                        ]);
                                    }
                                } else {
                                    if($cartItem->quantity < $cartItem->max){
                                        DB::table('product_limit_session')->insert([
                                            'product_variation_id' => $productVariationId,
                                            'user_id' => $user->ID,
                                            'session_id' => $activeSessionId,
                                            'order_count' => 0,
                                            'limit_count' => $cartItem->quantity, //1
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                    } else {
                                        DB::table('product_limit_session')->insert([
                                            'product_variation_id' => $productVariationId,
                                            'user_id' => $user->ID,
                                            'session_id' => $activeSessionId,
                                            'order_count' => 1,
                                            'limit_count' =>$cartItem->quantity, //1
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                    }
                                }
                            }
                        }

                        // Step 3: Remove item from cart
                        if ($cartItem) {
                            $cartItem->delete();
                        }


                        $productPrice = $item['product_price'];
                        $linetotal = 0;
                        $iLTax = 0;
                        $initialPrice = 0;

                        if ($item['isVape'] == true) {

                            $iLTax = $item['quantity'] * $item['taxPerUnit'];
                            $iLTax = round($iLTax, 2);
                        } else {
                            $productPrice = $productPrice + ($item['taxPerUnit'] ?? 0);
                        }
                        $float2 = 0.00;
                        $float2 = $item['quantity'] * $productPrice;
                        $float2 = round($float2, 2);
                        $linetotal += $float2;

                        $taxAmount = (float) ($iLTax ?? 0);
                        $serializedData = sprintf(
                            'a:2:{s:5:"total";a:1:{i:1;s:6:"%.2f";}s:8:"subtotal";a:1:{i:1;s:6:"%.2f";}}',
                            $taxAmount,
                            $taxAmount
                        );
                        $float3 = 0.00;
                        $float3 = $item['quantity'] * $item['taxPerUnit'];
                        $indirect_tax_amount = round($float3, 2);

                        if ($orderData['shipping']['state'] == 'IL' && $item['isVape'] == true) {
                            $indirect_tax_amount = 0.00;
                        }

                        $itemMeta = [
                            ['order_item_id' => $orderItemId, 'meta_key' => '_product_id', 'meta_value' => $item['product_id']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_variation_id', 'meta_value' => $item['variation_id'] ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_qty', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_sku', 'meta_value' => $item['sku'] ?? 'AD'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_reduced_stock', 'meta_value' => $item['quantity']],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_tax_class', 'meta_value' => $item['tax_class'] ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => 'flavor', 'meta_value' => implode(',', $item['variation']) ?? ''],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis', 'meta_value' => $item['ml1'] * $item['quantity'] ?? $item['ml2'] * $item['quantity'] ?? 0], //
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount', 'meta_value' => $indirect_tax_amount ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_priced', 'meta_value' => 'yes'],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_wwp_wholesale_role', 'meta_value' => $order_role],

                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_total', 'meta_value' => $linetotal ?? 0], //
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal', 'meta_value' => $linetotal ?? 0], //
                            //
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_subtotal_tax', 'meta_value' => $iLTax ?? 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax', 'meta_value' => $iLTax ?? 0],


                            ['order_item_id' => $orderItemId, 'meta_key' => '_line_tax_data', 'meta_value' =>  $serializedData],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j2', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j2', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_basis_j1', 'meta_value' => 0],
                            ['order_item_id' => $orderItemId, 'meta_key' => '_indirect_tax_amount_j1', 'meta_value' => 0],

                        ];
                        if (isset($item['is_free_product']) && $item['is_free_product']) {
                            $discountId = $item['discount_id'];

                            $initialPrice = $item['initial_price'];
                            $discounted_price = $productPrice;
                            $initial_price_based_on_tax_settings = $initialPrice;
                            $discounted_price_based_on_tax_settings = $productPrice;
                            $saved_amount = $initialPrice - $discounted_price;
                            $saved_amount_based_on_tax_settings = $saved_amount;


                            $metaValue = [
                                'initial_price' => $initialPrice,
                                'discounted_price' => $discounted_price,
                                'initial_price_based_on_tax_settings' => $initial_price_based_on_tax_settings,
                                'discounted_price_based_on_tax_settings' => $discounted_price_based_on_tax_settings,
                                'applied_rules' => [],
                                'saved_amount' => $saved_amount,
                                'saved_amount_based_on_tax_settings' => $saved_amount_based_on_tax_settings,
                                'is_free_product' => $item['is_free_product']
                            ];

                            $serializedMetaValue = serialize($metaValue);

                            $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedMetaValue];
                            $variation_id = $item['variation_id'] ?? 0;
                            $product_id = $item['product_id'];
                            if ($variation_id) {
                                $stockLevel = ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->value('meta_value');
                                $newStockLevel = max(0, $stockLevel - $item['quantity']);
                                ProductMeta::where('post_id', $variation_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                            } else {
                                $stockLevel = ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->value('meta_value');
                                $newStockLevel = max(0, $stockLevel - $item['quantity']);
                                ProductMeta::where('post_id', $product_id)->where('meta_key', '_stock')->update(['meta_value' => $newStockLevel]);
                            }
                        }

                        try {


                            $ischangeproducttocart = false;
                            if (isset($item['discount_amt']) && $item['discount_amt']) {

                                $discountAmount = $item['discount_amt'];
                                $coupon = DB::table('wp_wdr_rules')->where('id', $item['applicable_rules'][0]['rule_id'])->first();

                                $productAdjustments = json_decode($coupon->product_adjustments, true);
                                try {
                                    if ($request->input('cartAdjustment')) {
                                        $productAdjustments = json_decode($coupon->cart_adjustments, true);
                                        $ischangeproducttocart = true;
                                    }
                                } catch (\Throwable $th) {
                                }
                                // dd($productAdjustments);
                                if (json_last_error() === JSON_ERROR_NONE && isset($productAdjustments['cart_label'])) {
                                    $cartLabel = $productAdjustments['cart_label'];
                                    $cartValue = $productAdjustments['value'];
                                    $cartType = $productAdjustments['type'];
                                } else if ($ischangeproducttocart) {
                                    $cartLabel = $productAdjustments['label'];
                                    $cartValue = $productAdjustments['value'];
                                    $cartType = $productAdjustments['type'];
                                } else {
                                    $cartLabel = ' ';
                                    $cartValue = 0;
                                    $cartType = ' ';
                                }
                                if ($cartType == 'percentage') {
                                    $cartTypeN = 'percent';
                                } else {
                                    $cartTypeN = $cartType;
                                }
                                $couponTitle = $cartLabel; //20% off  //<-lable
                                $discountRateType = $cartTypeN; // 'percent'
                                $discountRateValue = $cartValue; //20

                                if ($temp == false) {
                                    $id3 = DB::table('wp_woocommerce_order_items')->insertGetId([
                                        'order_id' => $orderId,
                                        'order_item_name' => $couponTitle,
                                        'order_item_type' => 'coupon'
                                    ]);
                                    $temp = true;
                                }



                                $coupon_info = [0, $couponTitle, $discountRateType, $discountRateValue];
                                $jsonCouponInfo = json_encode($coupon_info);
                                $metaILTax = [
                                    ['order_item_id' => $id3, 'meta_key' => 'coupon_info', 'meta_value' => $jsonCouponInfo],
                                    ['order_item_id' => $id3, 'meta_key' => 'discount_amount_tax', 'meta_value' => round($cartDiscountTax, 2) ?? 0],
                                    ['order_item_id' => $id3, 'meta_key' => 'discount_amount', 'meta_value' => $cartDiscount ?? 0],
                                ];
                                // dd($metaILTax);
                                foreach ($metaILTax as $meta) {
                                    OrderItemMeta::insert($meta);
                                }

                                $lineTotalValue = $float2 - $discountAmount; //product price with tax
                                // dd($lineTotalValue);
                                foreach ($itemMeta as &$meta) {
                                    if ($meta['meta_key'] == '_line_total') {
                                        $meta['meta_value'] = $lineTotalValue;
                                    }
                                    // elseif ($meta['meta_key'] === '_line_subtotal') {
                                    //     $meta['meta_value'] = $lineSubtotalValue;
                                    // } $value = 9.998;   $roundedValue = round($value); //10

                                }
                                $initialPriced = $productPrice;
                                $discounted_priced = $productPrice;
                                $initial_price_based_on_tax_settingsd = $productPrice;
                                $discounted_price_based_on_tax_settingsd = $productPrice;
                                $idd = $coupon->id;
                                $titled = $coupon->title;
                                $appliedInd = 'cart_level';
                                $discount_typed = $cartType;
                                $discount_valued = $discountRateValue;
                                $discount_labeld = $couponTitle;
                                $discount_priced = $discountAmount;
                                $qtyd = $item['quantity'];
                                $data2 = [
                                    'initial_price' => $initialPriced,
                                    'discounted_price' => $discounted_priced,
                                    'initial_price_based_on_tax_settings' => $initial_price_based_on_tax_settingsd,
                                    'discounted_price_based_on_tax_settings' => $discounted_price_based_on_tax_settingsd,
                                    'applied_rules' => [
                                        [
                                            'id' => $idd,
                                            'title' => $titled,
                                            'type' => 'simple_discount',
                                            'discount' => [
                                                'applied_in' => $appliedInd,
                                                'discount_type' => $discount_typed,
                                                'discount_value' => $discount_valued,
                                                'discount_label' => $discount_labeld,
                                                'discount_price' => $discount_priced
                                            ]
                                        ]
                                    ],
                                    'saved_amount' => 0,
                                    'saved_amount_based_on_tax_settings' => 0,
                                    'is_free_product' => false
                                ];
                                $serializedData2 = serialize($data2);

                                $data3 = [
                                    'initial_price' => $initialPriced,
                                    'discounted_price' => $discounted_priced,
                                    'total_discount_details' => [],
                                    'cart_discount_details' => [
                                        $idd => [ // Use coupon ID or another unique identifier here
                                            'cart_discount' => $discount_valued,
                                            'cart_shipping' => 'no',
                                            'cart_discount_type' => $discount_typed,
                                            'cart_discount_label' => $discount_labeld,
                                            'cart_discount_price' => $discount_priced,
                                            'cart_discount_product_price' => $discount_priced
                                        ]
                                    ],
                                    'apply_as_cart_rule' => ['yes'],
                                    'discount_lines' => [
                                        'non_applied' => [
                                            'quantity' => $qtyd,
                                            'discount' => 0,
                                            'price' => $initialPriced,
                                            'calculate_discount_from' => $initialPriced
                                        ]
                                    ],
                                    'cart_quantity' => $qtyd,
                                    'product_id' => $item['variation_id'] ?? $item['product_id'],
                                    'initial_price_based_on_tax_settings' => $initialPrice,
                                    'discounted_price_based_on_tax_settings' => $initialPrice
                                ];

                                $serializedData3 = serialize($data3);
                                $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_wdr_discounts', 'meta_value' => $serializedData2];
                                $itemMeta[] = ['order_item_id' => $orderItemId, 'meta_key' => '_advanced_woo_discount_item_total_discount', 'meta_value' => $serializedData3];
                            }
                        } catch (\Throwable $th) {
                            //throw $th;
                        }

                        foreach ($itemMeta as $meta) {
                            OrderItemMeta::insert($meta);
                        }

                        // if($iLTax){
                        //     $tax= $iLTax;
                        //      DB::table('wp_wc_order_tax_lookup')->insert([
                        //         'order_id' => $orderId,
                        //         'tax_rate_id' => 1,
                        //         'date_created' => now(),
                        //         'shipping_tax' =>0,
                        //         'order_tax' => $item['variation_id'] ?? 0,
                        //         'total_tax' => $tax,
                        //     ]);
                        // }




                        $unitshippingCharge = (float) ($shppingtotal / max($ordertotalQTY, 1)) * $item['quantity'];
                        $done = DB::table('wp_wc_order_product_lookup')->insert([
                            'order_item_id' => $orderItemId,
                            'order_id' => $orderId,
                            'product_id' => $item['product_id'],
                            'variation_id' => $item['variation_id'] ?? 0,
                            'customer_id' => $user->ID,
                            'date_created' => now(),
                            'product_qty' => $item['quantity'],
                            'product_net_revenue' => $linetotal,
                            'product_gross_revenue' => $isVape ? $totalAmount : 0,
                            'tax_amount' => $iLTax ?? 0,
                            'coupon_amount' => 0,
                            'shipping_amount' => $unitshippingCharge ?? 0,
                            'shipping_tax_amount' => 0,
                        ]);
                    }

                    // dd($dd);
                    DB::table('wp_wc_orders')->insert([
                        'id' => $orderId,
                        'status' => 'wc-pre-processing',
                        'currency' => 'USD',
                        'type' => 'shop_order',
                        'tax_amount' => $order_tax ?? 0,
                        'total_amount' => $totalAmount,
                        'customer_id' => $user->ID,
                        'billing_email' => $orderData['billing']['email'],
                        'date_created_gmt' => now(),
                        'date_updated_gmt' => now(),
                        'parent_order_id' => 0,
                        'payment_method' => 'managemore_onaccount',
                        'payment_method_title' => '(*** PLEASE DONT USE THIS PAYMENT METHOD UNTIL WE ASK YOU TO DO IT. YOUR ORDER WILL AUTOMATICALLY GET CANCELLED.)',
                        'transaction_id' => uniqid(),
                        'ip_address' => $ip,
                        'user_agent' => $agent,
                        'customer_note' => ''
                    ]);


                    // $shippingFields = [
                    //     isset($orderData['shipping']['first_name']) ? $orderData['shipping']['first_name'] : '',
                    //     isset($orderData['shipping']['address_1']) ? $orderData['shipping']['address_1'] : '',
                    //     isset($orderData['shipping']['city']) ? $orderData['shipping']['city'] : '',
                    //     isset($orderData['shipping']['state']) ? $orderData['shipping']['state'] : '',
                    //     isset($orderData['shipping']['postcode']) ? $orderData['shipping']['postcode'] : ''
                    // ];

                    // $meta_value1 = trim(implode(' ', $shippingFields));

                    // $wp_wc_order_meta = [
                    //     ['order_id' => $orderId, 'meta_key' => '_order_number', 'meta_value' => $newValue],
                    //     ['order_id' => $orderId, 'meta_key' => '_order_tax', 'meta_value' => $order_tax ?? 0],
                    //     ['order_id' => $orderId, 'meta_key' => '_wwpp_order_type', 'meta_value' => $order_type],
                    //     ['order_id' => $orderId, 'meta_key' => '_wwpp_wholesale_order_type', 'meta_value' => $order_wholesale_role],
                    //     ['order_id' => $orderId, 'meta_key' => 'wwp_wholesale_role', 'meta_value' => $order_wholesale_role],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_CID', 'meta_value' => $user->account ?? ''],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_TXC', 'meta_value' => $metaValue ?? 'OS'],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_field_ITX', 'meta_value' => 0],
                    //     ['order_id' => $orderId, 'meta_key' => 'mm_login_id', 'meta_value' => $user->user_email ?? ''],
                    //     [
                    //         'order_id' => $orderId,
                    //         'meta_key' => '_shipping_address_index',
                    //         'meta_value' => $meta_value1
                    //     ],
                    // ];

                    // DB::table('wp_wc_orders_meta')->insert($wp_wc_order_meta);

                    try {
                        $billingCompany = $orderData['billing']['company'];
                        $shippingCompany = $orderData['shipping']['company'];
                    } catch (\Throwable $th) {
                        $billingCompany = '';
                        $shippingCompany = '';
                    }
                    DB::table('wp_wc_order_addresses')->insert([
                        [
                            'order_id' => $orderId,
                            'address_type' => 'billing',
                            'first_name' => $orderData['billing']['first_name'],
                            'last_name' => $orderData['billing']['last_name'],
                            'company' => $billingCompany ?? '',
                            'address_1' => $orderData['billing']['address_1'],
                            'address_2' => $orderData['billing']['address_2'],
                            'city' => $orderData['billing']['city'],
                            'state' => $orderData['billing']['state'],
                            'postcode' => $orderData['billing']['postcode'],
                            'country' => $orderData['billing']['country'],
                            'email' => $orderData['billing']['email'],
                            'phone' => $orderData['billing']['phone']
                        ],
                        [
                            'order_id' => $orderId,
                            'address_type' => 'shipping',
                            'first_name' => $orderData['shipping']['first_name'],
                            'last_name' => $orderData['shipping']['last_name'],
                            'company' => $shippingCompany ?? '',
                            'address_1' => $orderData['shipping']['address_1'],
                            'address_2' => $orderData['shipping']['address_2'],
                            'city' => $orderData['shipping']['city'],
                            'state' => $orderData['shipping']['state'],
                            'postcode' => $orderData['shipping']['postcode'],
                            'country' => $orderData['shipping']['country'],
                            'email' => $orderData['billing']['email'],
                            'phone' => $orderData['billing']['phone']
                        ]
                    ]);

                    DB::table('wp_wc_order_stats')->insert([
                        'order_id' => $orderId,
                        'parent_id' => 0,
                        'status' => 'wc-pre-processing',
                        'date_created' => now(),
                        'date_created_gmt' => now(),
                        'num_items_sold' => $productCount,
                        'total_sales' => $totalAmount,
                        'tax_total' => 0,
                        'shipping_total' => $shippingLines[0]['total'],
                        'net_total' => $totalAmount,
                        'returning_customer' => 0,
                        'customer_id' => $user->ID,
                        'date_paid' => null,
                        'date_completed' => null,
                    ]);

                    $orderNotes = [
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Order status changed from Pending payment to Processing (express).',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                        [
                            'comment_post_ID' => $orderId,
                            'comment_author' => 'Laravel',
                            'comment_author_email' => '',
                            'comment_author_url' => '',
                            'comment_author_IP' => $ip,
                            'comment_date' => now(),
                            'comment_date_gmt' => now(),
                            'comment_content' => 'Express Onaccount Payment',
                            'comment_karma' => 0,
                            'comment_approved' => 1,
                            'comment_agent' => $agent,
                            'comment_type' => 'order_note',
                            'comment_parent' => 0,
                            'user_id' => 0,
                        ],
                    ];
                    foreach ($orderNotes as $note) {
                        DB::table('wp_comments')->insert($note);
                    }
                    $mail = $user->user_email;
                    try {
                        $coupon = UserCoupon::where('email', $mail)->first();
                        // if (!$coupon) {
                        // }
                        // if ($coupon->canUse === false) {
                        // }
                        $coupon->canUse = false;
                        $coupon->save();
                    } catch (\Throwable $th) {
                        Log::info($th->getMessage());
                    }
                    $checkout->delete();
                    //order data created in db
                    DB::commit();
                    $email = $orderData['billing']['email'];
                    $username = $orderData['billing']['first_name'] . ' ' . $orderData['billing']['last_name'];
                    $deliveryDate = '3 working Days';
                    $businessAddress = implode(' ', $orderData['shipping']);



                    $order = Order::with(['meta', 'items.meta'])->find($newValue);
                    $shippingAddress = $businessAddress ?? 'N/A';
                    $orderDate = $order->post_date;
                    $paymentMethod = $order->meta->where('meta_key', '_payment_method_title')->first()->meta_value ?? 'N/A';
                    $items = $order->items->where('order_item_type', 'line_item')->map(function ($item) {
                        $sku = $item->meta->where('meta_key', '_sku')->first()->meta_value ?? 'N/A';
                        $quantity = $item->meta->where('meta_key', '_qty')->first()->meta_value ?? 0;
                        $subtotal = $item->meta->where('meta_key', '_line_subtotal')->first()->meta_value ?? 0;
                        $total = $item->meta->where('meta_key', '_line_total')->first()->meta_value ?? 0;

                        return [
                            'name' => $item->order_item_name,
                            'sku' => $sku,
                            'quantity' => $quantity,
                            'subtotal' => $subtotal,
                            'total' => $total,
                        ];
                    });
                    $subtotal = $order->meta->where('meta_key', '_order_subtotal')->first()->meta_value ?? 0;
                    $shipping = $order->meta->where('meta_key', '_order_shipping')->first()->meta_value ?? 0;
                    $tax = $order->meta->where('meta_key', '_order_tax')->first()->meta_value ?? 0;
                    $discount = $order->meta->where('meta_key', '_cart_discount')->first()->meta_value ?? 0;
                    $total = $order->meta->where('meta_key', '_order_total')->first()->meta_value ?? 0;
                    $watermarkNumber = $user->account ?? '  ';
                    $html = View::make('pdf.order_invoice', compact(
                        'order',
                        'shippingAddress',
                        'orderDate',
                        'paymentMethod',
                        'items',
                        // 'subtotal',
                        'shipping',
                        'tax',
                        'discount',
                        'total',
                        'watermarkNumber'
                    ))->render();
                    $dompdf = new Dompdf();
                    $dompdf->loadHtml($html);
                    $dompdf->setPaper('A4', 'portrait');
                    $dompdf->render();
                    $pdfOutput = $dompdf->output();
                    $tempFilePath = "temp/order_invoice_{$orderId}.pdf";
                    Storage::put($tempFilePath, $pdfOutput);

                    SendOrderConfirmationEmail::dispatch(
                        $email,
                        $newValue,
                        $username,
                        $deliveryDate,
                        $businessAddress,
                        $tempFilePath
                    );
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 'Order creation failed: ' . $e->getMessage()], 500);
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Order ' . $newValue . ' successfully created',
                    'data' => 'On Account Payment',
                    'order' => $orderId,
                    'orderNo' => $newValue
                ], 200);
            } catch (Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        }
    }
}
